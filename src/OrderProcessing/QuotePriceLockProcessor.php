<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteOrderSynchronizerInterface;

/**
 * Registered after the prices recalculator (priority 50) and before shipping, promotions and
 * taxes: the cart of an accepted quote keeps exactly the quote lines, at the negotiated prices,
 * whatever the customer changes in the cart.
 */
final class QuotePriceLockProcessor implements OrderProcessorInterface
{
    public function __construct(
        private readonly QuoteOrderResolverInterface $quoteOrderResolver,
        private readonly QuoteOrderSynchronizerInterface $synchronizer,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if (!$order instanceof OrderInterface || !$order->canBeProcessed()) {
            return;
        }

        $quoteRequest = $this->quoteOrderResolver->resolveLockingQuote($order);
        if (null === $quoteRequest) {
            return;
        }

        $this->synchronizer->synchronize($quoteRequest, $order);
    }
}
