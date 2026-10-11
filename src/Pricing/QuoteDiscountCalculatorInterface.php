<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteDiscountCalculatorInterface
{
    /**
     * Global discount of the quote (positive amount, never more than the subtotal): the fixed
     * amount when set, the percentage of the subtotal otherwise.
     */
    public function calculate(QuoteRequestInterface $quoteRequest, int $subtotal): int;
}
