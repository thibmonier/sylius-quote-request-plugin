<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteCartManagerInterface
{
    /**
     * Cart of an accepted quote, reused while it is still a cart, created otherwise; stored as
     * the shop cart of the current session unless `$storeInSession` is false (flushed).
     */
    public function start(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface;

    /**
     * Unlinks the quote cart: its items get back the catalog prices (flushed). The quote
     * keeps its status, an accepted quote can start a new cart later.
     */
    public function release(QuoteRequestInterface $quoteRequest): void;
}
