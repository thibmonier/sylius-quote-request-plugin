<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * Builds a transient (never persisted) order from the quote and runs the Sylius taxes processor
 * on it, so that the displayed totals are the ones of the future order.
 */
final class QuoteTotalsCalculator implements QuoteTotalsCalculatorInterface
{
    /** @param FactoryInterface<OrderInterface> $orderFactory */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly QuoteOrderSynchronizerInterface $synchronizer,
        private readonly OrderProcessorInterface $taxesProcessor,
    ) {
    }

    public function calculate(QuoteRequestInterface $quoteRequest): QuoteTotals
    {
        $subtotal = 0;
        foreach ($quoteRequest->getLines() as $line) {
            if (null !== $line->getVariant()) {
                $subtotal += $line->getSubtotal();
            }
        }

        $channel = $quoteRequest->getChannel();
        if (null === $channel) {
            return new QuoteTotals($subtotal, 0, 0, 0, 0, $subtotal);
        }

        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode($quoteRequest->getLocaleCode() ?? $channel->getDefaultLocale()?->getCode());
        $order->setCurrencyCode($quoteRequest->getCurrencyCode() ?? $channel->getBaseCurrency()?->getCode());

        $address = $quoteRequest->getCustomer()?->getDefaultAddress();
        if (null !== $address) {
            $billingAddress = clone $address;
            $billingAddress->setCustomer(null);
            $order->setBillingAddress($billingAddress);
        }

        $this->synchronizer->synchronize($quoteRequest, $order);
        $this->taxesProcessor->process($order);

        $discount = -$order->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
        $taxIncluded = 0;
        $taxExcluded = 0;
        foreach ($this->taxAdjustments($order) as $adjustment) {
            if ($adjustment->isNeutral()) {
                $taxIncluded += $adjustment->getAmount();
            } else {
                $taxExcluded += $adjustment->getAmount();
            }
        }

        return new QuoteTotals(
            $subtotal,
            $discount,
            $taxIncluded + $taxExcluded,
            $taxIncluded,
            $taxExcluded,
            $order->getTotal(),
        );
    }

    /** @return iterable<AdjustmentInterface> */
    private function taxAdjustments(OrderInterface $order): iterable
    {
        foreach ($order->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                yield from $unit->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
            }
            yield from $item->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
        }
    }
}
