<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Event;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches the plugin events.
 *
 * - Events of an operation the plugin runs in its own transaction (accept, conversion,
 *   merchant transitions...) are held until that transaction commits, in the order they
 *   happened; a failed operation drops its own events.
 * - Any other event is dispatched right away. Inside a transaction the plugin does not own (a
 *   Sylius API command behind the messenger doctrine_transaction middleware, the checkout
 *   completion flush...), listeners may therefore run before that transaction commits, as for
 *   the Sylius core events: use an outbox for reliable integrations.
 */
final class QuoteEventQueue
{
    /** @var list<array{QuoteRequestEvent, string}> */
    private array $events = [];

    /** @var list<int> queue length when each running plugin operation started */
    private array $marks = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function dispatch(QuoteRequestEvent $event, string $eventName): void
    {
        if ([] !== $this->marks) {
            $this->events[] = [$event, $eventName];

            return;
        }

        $this->dispatchNow($event, $eventName);
    }

    /** A plugin operation starts (its own transaction). */
    public function hold(): void
    {
        $this->marks[] = count($this->events);
    }

    /** A plugin operation ends: its events are dropped if it failed, dispatched once committed. */
    public function release(bool $committed): void
    {
        $mark = array_pop($this->marks) ?? 0;
        if (!$committed) {
            $this->events = array_slice($this->events, 0, $mark);

            return;
        }
        if ([] !== $this->marks) {
            return;
        }

        while ([] !== $this->events) {
            [$event, $eventName] = array_shift($this->events);
            $this->dispatchNow($event, $eventName);
        }
    }

    private function dispatchNow(QuoteRequestEvent $event, string $eventName): void
    {
        $this->eventDispatcher->dispatch($event, $eventName);

        // What the "ordered" listeners persist is saved, whatever the moment of dispatch.
        if (QuoteRequestEvents::ORDERED === $eventName && $this->entityManager->isOpen()) {
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $unitOfWork->computeChangeSets();
            if ([] !== $unitOfWork->getScheduledEntityInsertions() || [] !== $unitOfWork->getScheduledEntityUpdates() || [] !== $unitOfWork->getScheduledEntityDeletions()) {
                $this->entityManager->flush();
            }
        }
    }
}
