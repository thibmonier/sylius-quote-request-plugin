<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Core\Model\OrderInterface;
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

    private function __construct()
    {
    }
}
