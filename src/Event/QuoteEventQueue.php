<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Event;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches the plugin events once the change is committed, in the order they happened.
 *
 * - Inside a transaction of the plugin (accept, conversion...): held until its commit; a failed
 *   operation drops only its own events.
 * - Inside a transaction of somebody else (e.g. the messenger doctrine_transaction middleware
 *   of the Sylius API): held until the end of the request, command or message, then each event
 *   is checked against the database (the change it reports must be stored: a rolled back
 *   change leaves nothing behind).
 * - Otherwise dispatched right away.
 */
final class QuoteEventQueue implements EventSubscriberInterface
{
    /** @var list<array{event: QuoteRequestEvent, name: string, proof: array{0: string, 1: ?string}}> */
    private array $events = [];

    /** @var list<int> queue length when each running plugin operation started */
    private array $marks = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $baselineTransactionLevel = 0,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            WorkerMessageHandledEvent::class => 'flush',
            WorkerMessageFailedEvent::class => 'discard',
        ];
    }

    public function dispatch(QuoteRequestEvent $event, string $eventName): void
    {
        if ([] !== $this->marks || $this->connection->getTransactionNestingLevel() > $this->baselineTransactionLevel) {
            // The change this event reports: checked against the database before dispatching.
            $this->events[] = ['event' => $event, 'name' => $eventName, 'proof' => self::proof($event, $eventName)];

            return;
        }

        $this->dispatchNow($event, $eventName);
    }

    /** A plugin operation starts (its own transaction). */
    public function hold(): void
    {
        $this->marks[] = count($this->events);
    }

    /** A plugin operation ends: its events are dropped if it failed, dispatched once nothing else holds them. */
    public function release(bool $committed): void
    {
        $mark = array_pop($this->marks) ?? 0;
        if (!$committed) {
            $this->events = array_slice($this->events, 0, $mark);

            return;
        }
        if ([] === $this->marks && $this->connection->getTransactionNestingLevel() <= $this->baselineTransactionLevel) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        while ([] !== $this->events) {
            $entry = array_shift($this->events);
            if ($this->isStored($entry['event'], $entry['proof'])) {
                $this->dispatchNow($entry['event'], $entry['name']);
            }
        }
    }

    public function discard(): void
    {
        $this->events = [];
    }

    private function dispatchNow(QuoteRequestEvent $event, string $eventName): void
    {
        $this->eventDispatcher->dispatch($event, $eventName);

        // Same behaviour whatever the moment of dispatch: what the "ordered" listeners persist is saved.
        if (QuoteRequestEvents::ORDERED === $eventName && $this->entityManager->isOpen()) {
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $unitOfWork->computeChangeSets();
            if ([] !== $unitOfWork->getScheduledEntityInsertions() || [] !== $unitOfWork->getScheduledEntityUpdates() || [] !== $unitOfWork->getScheduledEntityDeletions()) {
                $this->entityManager->flush();
            }
        }
    }

    /**
     * What must be in the database for the event to be true: the column the change wrote (with
     * its value), the message it posted, or for "created" the quote row itself.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function proof(QuoteRequestEvent $event, string $eventName): array
    {
        $quoteRequest = $event->getQuoteRequest();

        return match ($eventName) {
            QuoteRequestEvents::PROPOSED => ['proposed_at', $quoteRequest->getProposedAt()?->format('Y-m-d H:i:s')],
            QuoteRequestEvents::ACCEPTED => ['accepted_at', $quoteRequest->getAcceptedAt()?->format('Y-m-d H:i:s')],
            QuoteRequestEvents::ORDERED, QuoteRequestEvents::DECLINED, QuoteRequestEvents::EXPIRED, QuoteRequestEvents::CANCELLED => ['status', $quoteRequest->getStatus()],
            QuoteRequestEvents::MESSAGE_POSTED, QuoteRequestEvents::CHANGES_REQUESTED => ['message', is_scalar($id = ($quoteRequest->getMessages()->last() ?: null)?->getId()) ? (string) $id : null],
            default => ['row', null],
        };
    }

    /** @param array{0: string, 1: ?string} $proof */
    private function isStored(QuoteRequestEvent $event, array $proof): bool
    {
        $id = $event->getQuoteRequest()->getId();
        if (null === $id) {
            return false;
        }

        [$column, $expected] = $proof;
        if ('message' === $column) {
            return null !== $expected && false !== $this->connection->fetchOne('SELECT id FROM tmonier_quote_request_message WHERE id = ? AND quote_request_id = ?', [$expected, $id]);
        }

        $row = $this->connection->fetchAssociative('SELECT status, proposed_at, accepted_at FROM tmonier_quote_request WHERE id = ?', [$id]);
        if (!is_array($row)) {
            return false;
        }
        if ('row' === $column) {
            return true;
        }
        $stored = $row[$column] ?? null;

        return null !== $expected && is_string($stored) && substr($stored, 0, 19) === $expected;
    }
}
