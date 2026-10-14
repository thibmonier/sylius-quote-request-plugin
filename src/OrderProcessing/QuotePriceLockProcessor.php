<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteOrderSynchronizerInterface;

/**
 * Registered after the prices recalculator (priority 50) and before shipping, promotions and
 * taxes: the cart of an accepted quote keeps exactly the quote lines, at the negotiated prices,
 * whatever the customer changes in the cart. A cart that is not (or no longer) linked to an
 * accepted quote loses its quote prices: its locked items get back the catalog prices.
 */
final readonly class QuotePriceLockProcessor implements OrderProcessorInterface
{
    public function __construct(
        private QuoteOrderResolverInterface $quoteOrderResolver,
        private QuoteOrderSynchronizerInterface $synchronizer,
        private OrderProcessorInterface $pricesRecalculator,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if (!$order instanceof OrderInterface || !$order->canBeProcessed()) {
            return;
        }

        $quoteRequest = $this->quoteOrderResolver->resolveLockingQuote($order);
        if ($quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface) {
            $this->synchronizer->synchronize($quoteRequest, $order);

            return;
        }

        $lockedItems = QuoteCartInspector::lockedItems($order);
        if ([] === $lockedItems) {
            return;
        }

        foreach ($lockedItems as $item) {
            foreach ($item->getAdjustments(QuoteRequestInterface::LOCK_ADJUSTMENT)->toArray() as $marker) {
                $item->removeAdjustment($marker);
            }
            $item->setImmutable(false);
        }
        // Back to the catalog prices through the normal Sylius path (the prices recalculator):
        // the processors registered after this one (e.g. tier pricing, priority <= 44) apply.
        $this->pricesRecalculator->process($order);
    }
}
