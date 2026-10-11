<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Sylius\Component\Core\Distributor\IntegerDistributorInterface;
use Sylius\Component\Core\Distributor\ProportionalIntegerDistributorInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

final class QuoteOrderSynchronizer implements QuoteOrderSynchronizerInterface
{
    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param AdjustmentFactoryInterface<AdjustmentInterface> $adjustmentFactory
     */
    public function __construct(
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private readonly AdjustmentFactoryInterface $adjustmentFactory,
        private readonly ProportionalIntegerDistributorInterface $proportionalDistributor,
        private readonly IntegerDistributorInterface $integerDistributor,
        private readonly QuoteDiscountCalculatorInterface $discountCalculator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function synchronize(QuoteRequestInterface $quoteRequest, OrderInterface $order): void
    {
        $lines = $this->orderableLines($quoteRequest);

        if (!$this->itemsMatch($lines, $order)) {
            foreach ($order->getItems()->toArray() as $item) {
                $order->removeItem($item);
            }
            foreach ($lines as $line) {
                $order->addItem($this->createItem($line));
            }
        }

        $items = array_values($order->getItems()->toArray());
        foreach ($items as $index => $item) {
            /** @var OrderItemInterface $item */
            $this->lockPrice($item, $lines[$index]);
        }

        $this->applyDiscount($quoteRequest, $order);
    }

    /** @return list<QuoteRequestLineInterface> */
    private function orderableLines(QuoteRequestInterface $quoteRequest): array
    {
        $lines = [];
        foreach ($quoteRequest->getLines() as $line) {
            if (null !== $line->getVariant() && $line->getQuantity() > 0) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @param list<QuoteRequestLineInterface> $lines */
    private function itemsMatch(array $lines, OrderInterface $order): bool
    {
        $items = array_values($order->getItems()->toArray());
        if (count($items) !== count($lines)) {
            return false;
        }

        foreach ($lines as $index => $line) {
            /** @var OrderItemInterface $item */
            $item = $items[$index];
            if ($item->getVariant() !== $line->getVariant() || $item->getQuantity() !== $line->getQuantity()) {
                return false;
            }
        }

        return true;
    }

    private function createItem(QuoteRequestLineInterface $line): OrderItemInterface
    {
        /** @var ProductVariantInterface $variant */
        $variant = $line->getVariant();

        $item = $this->orderItemFactory->createNew();
        $item->setVariant($variant);
        $item->setProductName($variant->getProduct()?->getName() ?? $line->getProductName());
        $item->setVariantName($variant->getName() ?? $line->getVariantName());
        $this->lockPrice($item, $line);
        $this->orderItemQuantityModifier->modify($item, $line->getQuantity());

        return $item;
    }

    private function lockPrice(OrderItemInterface $item, QuoteRequestLineInterface $line): void
    {
        $item->setImmutable(true);
        $item->setUnitPrice($line->getEffectiveUnitPrice());
        $item->setOriginalUnitPrice(max($line->getCatalogUnitPrice(), $line->getEffectiveUnitPrice()));
    }

    private function applyDiscount(QuoteRequestInterface $quoteRequest, OrderInterface $order): void
    {
        $items = [];
        $itemTotals = [];
        /** @var OrderItemInterface $item */
        foreach ($order->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                foreach ($unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
                    if (QuoteRequestInterface::DISCOUNT_ADJUSTMENT_ORIGIN === $adjustment->getOriginCode()) {
                        $unit->removeAdjustment($adjustment);
                    }
                }
            }
            $items[] = $item;
            $itemTotals[] = $item->getUnitPrice() * $item->getQuantity();
        }

        $discount = $this->discountCalculator->calculate($quoteRequest, array_sum($itemTotals));
        if (0 === $discount) {
            return;
        }

        $label = $this->translator->trans('tmonier_quote_request.ui.quote_discount', ['%number%' => (string) $quoteRequest->getNumber()], 'messages', $order->getLocaleCode());

        /** @var list<int> $itemDiscounts */
        $itemDiscounts = $this->proportionalDistributor->distribute($itemTotals, -$discount);
        foreach ($items as $index => $item) {
            $units = array_values($item->getUnits()->toArray());
            if (0 === $itemDiscounts[$index] || [] === $units) {
                continue;
            }

            /** @var list<int> $unitDiscounts */
            $unitDiscounts = $this->integerDistributor->distribute($itemDiscounts[$index], count($units));
            foreach ($units as $unitIndex => $unit) {
                if (0 === $unitDiscounts[$unitIndex]) {
                    continue;
                }

                $adjustment = $this->adjustmentFactory->createWithData(
                    AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT,
                    $label,
                    $unitDiscounts[$unitIndex],
                    false,
                    ['quote_request' => $quoteRequest->getNumber()],
                );
                $adjustment->setOriginCode(QuoteRequestInterface::DISCOUNT_ADJUSTMENT_ORIGIN);
                $unit->addAdjustment($adjustment);
            }
        }
    }
}
