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
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

/**
 * Dispatches the plugin events once the change is committed, in the order they happened.
 *
 * - Inside a transaction of the plugin (accept, conversion...): held until its commit,
 *   dropped on rollback.
 * - Inside a transaction of somebody else (e.g. the messenger doctrine_transaction middleware
 *   of the Sylius API): held until the end of the request, command or message, then
 *   dispatched only if the database confirms the quote is ordered (a rolled back order leaves
 *   nothing behind).
 * - Otherwise dispatched right away.
 */
final class QuoteEventQueue implements EventSubscriberInterface
{
    /** @var list<array{QuoteRequestEvent, string}> */
    private array $events = [];

    private int $holds = 0;

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
        if ($this->holds > 0 || $this->connection->getTransactionNestingLevel() > $this->baselineTransactionLevel) {
            $this->events[] = [$event, $eventName];

            return;
        }

        $this->dispatchNow($event, $eventName);
    }

    /** A transaction of the plugin starts. */
    public function hold(): void
    {
        ++$this->holds;
    }

    /** A transaction of the plugin ends: dispatch what it held if committed, drop it otherwise. */
    public function release(bool $committed): void
    {
        $this->holds = max(0, $this->holds - 1);
        if (!$committed) {
            $this->discard();

            return;
        }
        if (0 === $this->holds && $this->connection->getTransactionNestingLevel() <= $this->baselineTransactionLevel) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        while ([] !== $this->events) {
            [$event, $eventName] = array_shift($this->events);
            if (QuoteRequestEvents::ORDERED === $eventName && !$this->isStoredAsOrdered($event)) {
                continue;
            }
            $this->dispatchNow($event, $eventName);
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

    private function isStoredAsOrdered(QuoteRequestEvent $event): bool
    {
        $id = $event->getQuoteRequest()->getId();
        if (null === $id) {
            return false;
        }

        return QuoteRequestStatuses::ORDERED === $this->connection->fetchOne('SELECT status FROM tmonier_quote_request WHERE id = ?', [$id]);
    }
}
