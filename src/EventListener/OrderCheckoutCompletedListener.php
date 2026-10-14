<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * `sylius_order_checkout` → `complete`: the accepted quote of the cart becomes "ordered".
 */
final readonly class OrderCheckoutCompletedListener
{
    public function __construct(
        private QuoteRequestRepositoryInterface $quoteRequestRepository,
        private QuoteRequestManagerInterface $quoteRequestManager,
        private OrderCheckoutCompletedDoctrineListener $doctrineListener,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByOrder($order);
        if (!$quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface || QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus() || !QuoteCartInspector::holdsQuoteLines($order, $quoteRequest)) {
            return;
        }

        if ($this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER)) {
            $this->quoteRequestManager->markOrdered($quoteRequest);
            // The "ordered" event is dispatched once the order is flushed, as on the Winzou path.
            $this->doctrineListener->enqueue($quoteRequest);
        }
    }
}
