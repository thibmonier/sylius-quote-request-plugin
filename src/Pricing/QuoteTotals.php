<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

final class QuoteTotals
{
    public function __construct(
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $taxTotal,
        public readonly int $taxIncludedTotal,
        public readonly int $taxExcludedTotal,
        public readonly int $total,
    ) {
    }

    /** Taxes already contained in the prices (tax rates "included in price"). */
    public function hasIncludedTaxes(): bool
    {
        return $this->taxIncludedTotal > 0;
    }

    public function hasExcludedTaxes(): bool
    {
        return $this->taxExcludedTotal > 0;
    }
}
