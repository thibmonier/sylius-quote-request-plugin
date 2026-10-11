<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * State machine agnostic twin of {@see OrderCheckoutCompletedListener}: when an order whose
 * checkout state becomes "completed" is flushed, its accepted quote becomes "ordered". Needed
 * when `sylius_order_checkout` runs on the Winzou adapter (no Symfony Workflow event); with the
 * Symfony Workflow adapter the quote is already "ordered" and nothing happens.
 */
final class OrderCheckoutCompletedDoctrineListener
{
    public function __construct(private readonly QuoteRequestManagerInterface $quoteRequestManager)
    {
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
            if (!$quoteRequest instanceof QuoteRequestInterface ||
                QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus() ||
                !$this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER)) {
                continue;
            }

            $this->quoteRequestManager->markOrdered($quoteRequest);
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($quoteRequest::class), $quoteRequest);
        }
    }
}
