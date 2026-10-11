<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Order\Model\OrderInterface;

/**
 * Public API for other plugins (quick order, bulk add-to-cart...): a quote cart holds exactly
 * the lines of an accepted quote, at locked prices; adding products to it is pointless (the
 * price lock processor removes them). Refuse or divert such additions.
 */
interface QuoteCartCheckerInterface
{
    /** Whether the order is the cart of an accepted quote (its content and prices are locked). */
    public function isQuoteCart(OrderInterface $order): bool;
}
