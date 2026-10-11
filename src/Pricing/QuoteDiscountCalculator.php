<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

final class QuoteDiscountCalculator implements QuoteDiscountCalculatorInterface
{
    public function calculate(QuoteRequestInterface $quoteRequest, int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        $amount = $quoteRequest->getDiscountAmount();
        if (null !== $amount && $amount > 0) {
            return min($amount, $subtotal);
        }

        $percentage = $quoteRequest->getDiscountPercentage();
        if (null !== $percentage && $percentage > 0) {
            return min((int) round($subtotal * min($percentage, 100.0) / 100), $subtotal);
        }

        return 0;
    }
}
