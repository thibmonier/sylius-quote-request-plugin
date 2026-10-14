<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

final class QuoteCartInspector
{
    /**
     * Whether the order holds quote lines at their locked prices: an order that does not (the
     * customer emptied the quote cart and bought something else) never makes the quote "ordered".
     */
    public static function holdsQuoteLines(OrderInterface $order, QuoteRequestInterface $quoteRequest): bool
    {
        foreach ($order->getItems() as $item) {
            if (!$item->isImmutable()) {
                continue;
            }
            foreach ($quoteRequest->getLines() as $line) {
                if (null !== $line->getVariant() && $line->getVariant() === $item->getVariant() && $line->getEffectiveUnitPrice() === $item->getUnitPrice()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<OrderItemInterface> items whose price is locked by a quote (marker adjustment) */
    public static function lockedItems(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            // isImmutable() first: no adjustment loading for regular items.
            if ($item->isImmutable() && !$item->getAdjustments(QuoteRequestInterface::LOCK_ADJUSTMENT)->isEmpty()) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The invariant: locked items only in a cart linked to the accepted quote that holds them.
     */
    public static function isBackedByQuote(OrderInterface $order, ?QuoteRequestInterface $quoteRequest): bool
    {
        if ([] === self::lockedItems($order)) {
            return true;
        }

        return $quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface && $quoteRequest->getOrder() === $order && self::holdsQuoteLines($order, $quoteRequest);
    }

    private function __construct()
    {
    }
}
