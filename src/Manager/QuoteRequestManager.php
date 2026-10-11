<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

use Doctrine\Persistence\ObjectManager;
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
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestManager implements QuoteRequestManagerInterface
{
    public const NUMBER_FORMAT = 'Q%06d';

    public function __construct(
        private readonly ObjectManager $objectManager,
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

        $this->objectManager->persist($quoteRequest);
        $this->objectManager->flush();

        if (null === $quoteRequest->getNumber()) {
            $quoteRequest->setNumber(sprintf(self::NUMBER_FORMAT, (int) $quoteRequest->getId()));
            $this->objectManager->flush();
        }

        $this->eventDispatcher->dispatch(new QuoteRequestEvent($quoteRequest), QuoteRequestEvents::CREATED);
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

        $this->applyTransition($quoteRequest, $transition, $message);

        if (QuoteRequestTransitions::TRANSITION_CANCEL === $transition) {
            $this->quoteCartManager->release($quoteRequest);
        }

        $this->objectManager->flush();
        $this->dispatch($quoteRequest, $transition);
    }

    public function accept(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface
    {
        $this->applyTransition($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);
        $cart = $this->quoteCartManager->start($quoteRequest, $customer, $storeInSession);
        $this->dispatch($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);

        return $cart;
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
        $this->objectManager->flush();

        $this->eventDispatcher->dispatch(new QuoteRequestEvent($quoteRequest), QuoteRequestEvents::MESSAGE_POSTED);
    }

    public function save(QuoteRequestInterface $quoteRequest): void
    {
        $quoteRequest->setUpdatedAt($this->clock->now());
        $this->objectManager->flush();
    }

    private function applyTransition(QuoteRequestInterface $quoteRequest, string $transition, ?QuoteMessageInterface $message = null): void
    {
        if (!$this->can($quoteRequest, $transition)) {
            throw QuoteTransitionException::notAllowed($transition, $quoteRequest->getStatus());
        }

        $now = $this->clock->now();
        if (QuoteRequestTransitions::TRANSITION_PROPOSE === $transition) {
            $quoteRequest->setProposedAt($now);
            if (null === $quoteRequest->getValidUntil()) {
                $quoteRequest->setValidUntil($now->modify(sprintf('+%d days', $this->defaultValidityDays))->setTime(23, 59, 59));
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
            $this->eventDispatcher->dispatch(new QuoteRequestEvent($quoteRequest, $transition), $eventName);
        }
    }
}
