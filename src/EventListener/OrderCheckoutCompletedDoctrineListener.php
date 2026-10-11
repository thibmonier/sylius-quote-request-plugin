<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteEventQueue;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * Makes the quote of a completed checkout "ordered" and dispatches the "ordered" event, the
 * same way for every state machine adapter:
 *
 * - Symfony Workflow: {@see OrderCheckoutCompletedListener} applies the transition and queues
 *   the quote here;
 * - any adapter (Winzou included): onFlush sees the order checkout state becoming "completed"
 *   and sets the quote fields (no workflow, no event inside onFlush).
 *
 * The event goes through {@see QuoteEventQueue}: on postFlush, only for quotes really stored as
 * "ordered", and after the commit of an enclosing transaction.
 */
final class OrderCheckoutCompletedDoctrineListener
{
    /** @var array<int, QuoteRequestInterface> by object id */
    private array $ordered = [];

    public function __construct(
        private readonly QuoteEventQueue $eventQueue,
        private readonly ClockInterface $clock,
    ) {
    }

    public function enqueue(QuoteRequestInterface $quoteRequest): void
    {
        $this->ordered[spl_object_id($quoteRequest)] = $quoteRequest;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $changeSet = $unitOfWork->getEntityChangeSet($order);
            if (!isset($changeSet['checkoutState']) || OrderCheckoutStates::STATE_COMPLETED !== $changeSet['checkoutState'][1]) {
                continue;
            }

            $quoteRequest = $entityManager->getRepository(QuoteRequestInterface::class)->findOneBy(['order' => $order]);
            $backing = $quoteRequest instanceof QuoteRequestInterface && in_array($quoteRequest->getStatus(), [QuoteRequestStatuses::ACCEPTED, QuoteRequestStatuses::ORDERED], true) ? $quoteRequest : null;
            if (!QuoteCartInspector::isBackedByQuote($order, $backing)) {
                // Any adapter: never place an order at quote prices without its accepted quote.
                throw new \LogicException(sprintf('Order %s holds quote prices without its accepted quote: checkout refused.', (string) $order->getId()));
            }
            if (!$quoteRequest instanceof QuoteRequestInterface) {
                continue;
            }
            if (QuoteRequestStatuses::ORDERED === $quoteRequest->getStatus()) {
                // Symfony Workflow path: already marked, make sure the change is part of this flush.
                $this->enqueue($quoteRequest);
                $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($quoteRequest::class), $quoteRequest);

                continue;
            }
            if (QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus() || !QuoteCartInspector::holdsQuoteLines($order, $quoteRequest)) {
                continue;
            }

            $now = $this->clock->now();
            $quoteRequest->setStatus(QuoteRequestStatuses::ORDERED);
            $quoteRequest->setOrderedAt($now);
            $quoteRequest->setUpdatedAt($now);
            // Versioned update: fails the whole flush if the quote was changed meanwhile.
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($quoteRequest::class), $quoteRequest);
            $this->enqueue($quoteRequest);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $ordered = $this->ordered;
        $this->ordered = [];

        foreach ($ordered as $quoteRequest) {
            // Only what this flush stored: a quote marked in memory by a failed flush is dropped.
            if (!$entityManager->contains($quoteRequest) || QuoteRequestStatuses::ORDERED !== ($unitOfWork->getOriginalEntityData($quoteRequest)['status'] ?? null)) {
                continue;
            }
            // Dispatched now, or after the commit of the enclosing transaction.
            $this->eventQueue->dispatch(new QuoteRequestEvent($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER), QuoteRequestEvents::ORDERED);
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->ordered = [];
    }
}
