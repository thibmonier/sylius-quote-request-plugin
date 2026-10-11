<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * `sylius_order_checkout` → `complete`: the accepted quote of the cart becomes "ordered".
 */
final class OrderCheckoutCompletedListener
{
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByOrder($order);
        if (null === $quoteRequest || QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
            return;
        }

        if ($this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_ORDER)) {
            $this->quoteRequestManager->markOrdered($quoteRequest);
        }
    }
}
