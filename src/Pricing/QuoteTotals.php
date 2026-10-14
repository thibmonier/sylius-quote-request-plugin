<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pricing;

final readonly class QuoteTotals
{
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $taxTotal,
        public int $taxIncludedTotal,
        public int $taxExcludedTotal,
        public int $total,
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
