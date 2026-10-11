<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Expiration;

interface QuoteRequestExpirerInterface
{
    /**
     * Marks the proposals past their validity date as expired.
     *
     * @return list<string> numbers of the expired quotes
     */
    public function expire(bool $dryRun = false): array;
}
