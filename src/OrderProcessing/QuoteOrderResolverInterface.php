<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Order\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteOrderResolverInterface
{
    /** The accepted quote whose prices are locked in this cart, if any. */
    public function resolveLockingQuote(OrderInterface $order): ?QuoteRequestInterface;

    /** Links a quote cart for the rest of the request (no query needed). */
    public function remember(OrderInterface $order, QuoteRequestInterface $quoteRequest): void;

    public function forget(OrderInterface $order): void;
}
