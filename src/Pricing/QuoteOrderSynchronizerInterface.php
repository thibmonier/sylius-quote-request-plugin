<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteOrderSynchronizerInterface
{
    /**
     * Makes the order items exactly the quote lines (variant, quantity), with immutable unit
     * prices set to the negotiated prices, and spreads the global discount on the units.
     * Runs inside the order processing: it never calls the order processor itself.
     */
    public function synchronize(QuoteRequestInterface $quoteRequest, OrderInterface $order): void;
}
