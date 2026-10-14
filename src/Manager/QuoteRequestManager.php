<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteEventQueue;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestManager implements QuoteRequestManagerInterface
{
    public const NUMBER_FORMAT = 'Q%06d';

    private int $depth = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StateMachineInterface $stateMachine,
        private readonly QuoteEventQueue $eventQueue,
        private readonly QuoteCartManagerInterface $quoteCartManager,
        private readonly ClockInterface $clock,
        private readonly int $defaultValidityDays,
    ) {
    }

    public function submit(QuoteRequestInterface $quoteRequest, ?QuoteMessageInterface $message = null): void
    {
        if ($message instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface) {
            $quoteRequest->addMessage($message);
        }
        $quoteRequest->setCreatedAt($quoteRequest->getCreatedAt() ?? $this->clock->now());

        $this->entityManager->persist($quoteRequest);
        $this->entityManager->flush();

        if (null === $quoteRequest->getNumber()) {
            $quoteRequest->setNumber(sprintf(self::NUMBER_FORMAT, (int) $quoteRequest->getId()));
            $this->entityManager->flush();
        }

        $this->dispatchEvent(new QuoteRequestEvent($quoteRequest), QuoteRequestEvents::CREATED);
    }

    public function can(QuoteRequestInterface $quoteRequest, string $transition): bool
    {
        return $this->stateMachine->can($quoteRequest, QuoteRequestTransitions::GRAPH, $transition);
    }

    public function apply(QuoteRequestInterface $quoteRequest, string $transition, ?QuoteMessageInterface $message = null): void
    {
        if (QuoteRequestTransitions::TRANSITION_ACCEPT === $transition) {
            $this->accept($quoteRequest);

            return;
        }

        // Refused before the transaction: the caller keeps its unsaved changes (no rollback/clear).
        $this->assertCan($quoteRequest, $transition);

        $this->transactional($quoteRequest, function () use ($quoteRequest, $transition, $message): void {
            $this->applyTransition($quoteRequest, $transition, $message);
            if (QuoteRequestTransitions::TRANSITION_CANCEL === $transition) {
                $this->quoteCartManager->release($quoteRequest);
            }
            $this->entityManager->flush();
            $this->dispatch($quoteRequest, $transition);
        });
    }

    public function accept(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface
    {
        $this->assertCan($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);

        return $this->transactional($quoteRequest, function () use ($quoteRequest, $customer, $storeInSession): OrderInterface {
            $this->applyTransition($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);
            $cart = $this->quoteCartManager->start($quoteRequest, $customer, $storeInSession);
            $this->dispatch($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);

            return $cart;
        }, true);
    }

    public function startCheckout(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null): OrderInterface
    {
        if (QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
            throw QuoteTransitionException::notAllowed('checkout', $quoteRequest->getStatus());
        }

        // The lock re-checks status and version: a concurrent start or conversion is refused.
        return $this->transactional($quoteRequest, fn (): OrderInterface => $this->quoteCartManager->start($quoteRequest, $customer), true);
    }

    public function markOrdered(QuoteRequestInterface $quoteRequest): void
    {
        $this->applyTransition($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER);
    }

    public function postMessage(QuoteRequestInterface $quoteRequest, QuoteMessageInterface $message): void
    {
        $quoteRequest->addMessage($message);
        $quoteRequest->setUpdatedAt($this->clock->now());
        $this->entityManager->flush();

        $this->dispatchEvent(new QuoteRequestEvent($quoteRequest), QuoteRequestEvents::MESSAGE_POSTED);
    }

    public function save(QuoteRequestInterface $quoteRequest): void
    {
        $quoteRequest->setUpdatedAt($this->clock->now());
        $this->entityManager->flush();
    }

    public function transactional(QuoteRequestInterface $quoteRequest, callable $operation, bool $decision = false): mixed
    {
        if ($this->depth > 0) {
            if ($decision) {
                $this->bumpVersion($quoteRequest);
            }

            return $operation();
        }

        $connection = $this->entityManager->getConnection();
        ++$this->depth;
        $this->eventQueue->hold();
        $connection->beginTransaction();
        $committed = false;

        try {
            $this->lock($quoteRequest, $decision);
            if ($decision) {
                $this->bumpVersion($quoteRequest);
            }
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();
            $committed = true;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            // What was flushed in the transaction is gone: forget the in-memory changes too.
            $this->entityManager->clear();

            throw $exception;
        } finally {
            --$this->depth;
            $this->eventQueue->release($committed);
        }

        return $result;
    }

    /**
     * Locks the quote row until the end of the transaction and checks that nobody changed its
     * status meanwhile; for the decisions on the quote cart (accept, checkout start,
     * conversion) the version too, which those decisions bump. Messages and merchant edits
     * neither check nor bump it, so they never make a concurrent request fail.
     */
    private function lock(QuoteRequestInterface $quoteRequest, bool $decision): void
    {
        $id = $quoteRequest->getId();
        if (null === $id) {
            return;
        }

        $table = $this->entityManager->getClassMetadata($quoteRequest::class)->getTableName();
        $row = $this->entityManager->getConnection()->fetchAssociative(sprintf('SELECT status, version FROM %s WHERE id = ? FOR UPDATE', $table), [$id]);
        $status = is_array($row) && is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
        $version = is_array($row) && is_numeric($row['version'] ?? null) ? (int) $row['version'] : null;
        if ($status !== $quoteRequest->getStatus() || ($decision && $version !== $quoteRequest->getVersion())) {
            throw QuoteTransitionException::concurrentChange($status);
        }
    }

    private function bumpVersion(QuoteRequestInterface $quoteRequest): void
    {
        $quoteRequest->setVersion((int) $quoteRequest->getVersion() + 1);
    }

    private function assertCan(QuoteRequestInterface $quoteRequest, string $transition): void
    {
        if ($this->depth === 0 && !$this->can($quoteRequest, $transition)) {
            throw QuoteTransitionException::notAllowed($transition, $quoteRequest->getStatus());
        }
    }

    private function applyTransition(QuoteRequestInterface $quoteRequest, string $transition, ?QuoteMessageInterface $message = null): void
    {
        if (!$this->can($quoteRequest, $transition)) {
            throw QuoteTransitionException::notAllowed($transition, $quoteRequest->getStatus());
        }

        $now = $this->clock->now();
        if (QuoteRequestTransitions::TRANSITION_PROPOSE === $transition) {
            $quoteRequest->setProposedAt($now);
            // No date, or a date already over (re-proposal after a revision): default validity.
            $validUntil = $quoteRequest->getValidUntil();
            if (!$validUntil instanceof \DateTimeImmutable || $validUntil < $now) {
                $quoteRequest->setValidUntil($now->modify(sprintf('+%d days', $this->defaultValidityDays)));
            }
        }
        if (QuoteRequestTransitions::TRANSITION_ACCEPT === $transition) {
            $quoteRequest->setAcceptedAt($now);
        }
        if (QuoteRequestTransitions::TRANSITION_ORDER === $transition) {
            $quoteRequest->setOrderedAt($now);
        }
        if ($message instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface) {
            $quoteRequest->addMessage($message);
        }

        $this->stateMachine->apply($quoteRequest, QuoteRequestTransitions::GRAPH, $transition);
        $quoteRequest->setUpdatedAt($now);
    }

    private function dispatch(QuoteRequestInterface $quoteRequest, string $transition): void
    {
        $eventName = QuoteRequestEvents::BY_TRANSITION[$transition] ?? null;
        if (null !== $eventName) {
            $this->dispatchEvent(new QuoteRequestEvent($quoteRequest, $transition), $eventName);
        }
    }

    private function dispatchEvent(QuoteRequestEvent $event, string $eventName): void
    {
        $this->eventQueue->dispatch($event, $eventName);
    }
}
