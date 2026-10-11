<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteTotalsCalculatorInterface
{
    /**
     * Totals of the quote as the order will compute them: negotiated prices, global discount and
     * taxes of the channel (default tax zone of the channel, or the zone of the customer's
     * default address). Shipping is not included: it is chosen at checkout.
     */
    public function calculate(QuoteRequestInterface $quoteRequest): QuoteTotals;
}
