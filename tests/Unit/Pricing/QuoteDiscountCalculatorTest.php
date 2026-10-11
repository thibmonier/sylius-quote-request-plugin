<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteDiscountCalculator;

final class QuoteDiscountCalculatorTest extends TestCase
{
    /** @return iterable<string, array{int|null, float|null, int, int}> */
    public static function discounts(): iterable
    {
        yield 'none' => [null, null, 10000, 0];
        yield 'fixed amount' => [1500, null, 10000, 1500];
        yield 'fixed amount wins over the percentage' => [1500, 50.0, 10000, 1500];
        yield 'fixed amount capped at the subtotal' => [20000, null, 10000, 10000];
        yield 'percentage' => [null, 12.5, 10000, 1250];
        yield 'percentage rounded' => [null, 10.0, 3 * 1999 + 7 * 1234, 1464];
        yield 'percentage capped at 100' => [null, 150.0, 10000, 10000];
        yield 'zero amount uses the percentage' => [0, 5.0, 10000, 500];
        yield 'empty quote' => [1000, null, 0, 0];
    }

    #[DataProvider('discounts')]
    public function testItCalculatesTheGlobalDiscount(?int $amount, ?float $percentage, int $subtotal, int $expected): void
    {
        $quote = new QuoteRequest();
        $quote->setDiscountAmount($amount);
        $quote->setDiscountPercentage($percentage);

        self::assertSame($expected, (new QuoteDiscountCalculator())->calculate($quote, $subtotal));
    }
}
