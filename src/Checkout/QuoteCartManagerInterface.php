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
     * Unlinks the quote cart: its items get back the catalog prices, or are removed when the
     * quote is ordered by other means (flushed). The quote
     * keeps its status, an accepted quote can start a new cart later.
     */
    public function release(QuoteRequestInterface $quoteRequest, bool $removeItems = false): void;

    /**
     * Once the merchant placed the order of a quote: removes its products from another cart of
     * the customer (the cart the request was made from, other carts), keeping the rest (flushed).
     */
    public function removeQuotedProducts(OrderInterface $cart, QuoteRequestInterface $quoteRequest): void;
}
