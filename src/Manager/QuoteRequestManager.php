<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestManager implements QuoteRequestManagerInterface
{
    public const NUMBER_FORMAT = 'Q%06d';

    private int $depth = 0;

    /** @var list<array{QuoteRequestEvent, string}> events held until the transaction commits */
    private array $pendingEvents = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StateMachineInterface $stateMachine,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly QuoteCartManagerInterface $quoteCartManager,
        private readonly ClockInterface $clock,
        private readonly int $defaultValidityDays,
    ) {
    }

    public function submit(QuoteRequestInterface $quoteRequest, ?QuoteMessageInterface $message = null): void
    {
        if (null !== $message) {
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
        return $this->transactional($quoteRequest, function () use ($quoteRequest, $customer, $storeInSession): OrderInterface {
            $this->applyTransition($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);
            $cart = $this->quoteCartManager->start($quoteRequest, $customer, $storeInSession);
            $this->dispatch($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);

            return $cart;
        });
    }

    public function startCheckout(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null): OrderInterface
    {
        return $this->transactional($quoteRequest, function () use ($quoteRequest, $customer): OrderInterface {
            if (QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
                throw QuoteTransitionException::notAllowed('checkout', $quoteRequest->getStatus());
            }

            return $this->quoteCartManager->start($quoteRequest, $customer);
        });
    }

    public function markOrdered(QuoteRequestInterface $quoteRequest): void
    {
        $this->applyTransition($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER);
        $this->dispatch($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER);
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

    public function transactional(QuoteRequestInterface $quoteRequest, callable $operation): mixed
    {
        if ($this->depth > 0) {
            return $operation();
        }

        $connection = $this->entityManager->getConnection();
        ++$this->depth;
        $connection->beginTransaction();

        try {
            $this->lock($quoteRequest);
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->pendingEvents = [];
            // What was flushed in the transaction is gone: forget the in-memory changes too.
            $this->entityManager->clear();

            throw $exception;
        } finally {
            --$this->depth;
        }

        $events = $this->pendingEvents;
        $this->pendingEvents = [];
        foreach ($events as [$event, $eventName]) {
            $this->eventDispatcher->dispatch($event, $eventName);
        }

        return $result;
    }

    /**
     * Locks the quote row until the end of the transaction and checks that nobody changed its
     * status meanwhile (double click, customer and merchant at the same time).
     */
    private function lock(QuoteRequestInterface $quoteRequest): void
    {
        $id = $quoteRequest->getId();
        if (null === $id) {
            return;
        }

        $table = $this->entityManager->getClassMetadata($quoteRequest::class)->getTableName();
        $status = $this->entityManager->getConnection()->fetchOne(sprintf('SELECT status FROM %s WHERE id = ? FOR UPDATE', $table), [$id]);
        if ($status !== $quoteRequest->getStatus()) {
            throw QuoteTransitionException::concurrentChange(is_string($status) ? $status : 'unknown');
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
            if (null === $validUntil || $validUntil < $now) {
                $quoteRequest->setValidUntil($now->modify(sprintf('+%d days', $this->defaultValidityDays)));
            }
        }
        if (QuoteRequestTransitions::TRANSITION_ACCEPT === $transition) {
            $quoteRequest->setAcceptedAt($now);
        }
        if (QuoteRequestTransitions::TRANSITION_ORDER === $transition) {
            $quoteRequest->setOrderedAt($now);
        }
        if (null !== $message) {
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
        if ($this->depth > 0) {
            $this->pendingEvents[] = [$event, $eventName];

            return;
        }

        $this->eventDispatcher->dispatch($event, $eventName);
    }
}
