<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * State machine agnostic twin of {@see OrderCheckoutCompletedListener}: when an order whose
 * checkout state becomes "completed" is flushed, its accepted quote becomes "ordered" in the same
 * flush. Needed when `sylius_order_checkout` runs on the Winzou adapter (no Symfony Workflow
 * event); with the Symfony Workflow adapter the quote is already "ordered" and nothing happens.
 *
 * Inside onFlush only the quote fields change (no workflow, no event: listeners could not
 * persist anything there); the "ordered" event is dispatched on postFlush.
 */
final class OrderCheckoutCompletedDoctrineListener
{
    /** @var list<QuoteRequestInterface> */
    private array $ordered = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
    ) {
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
            if (!$quoteRequest instanceof QuoteRequestInterface || QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
                continue;
            }

            $now = $this->clock->now();
            $quoteRequest->setStatus(QuoteRequestStatuses::ORDERED);
            $quoteRequest->setOrderedAt($now);
            $quoteRequest->setUpdatedAt($now);
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($quoteRequest::class), $quoteRequest);
            $this->ordered[] = $quoteRequest;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $ordered = $this->ordered;
        $this->ordered = [];
        foreach ($ordered as $quoteRequest) {
            $this->eventDispatcher->dispatch(new QuoteRequestEvent($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER), QuoteRequestEvents::ORDERED);
        }
    }
}
