<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin;

use PHPUnit\Framework\Assert;

/** Scalar results of raw SQL queries in tests. */
final class DbValue
{
    public static function int(mixed $value): int
    {
        Assert::assertIsNumeric($value);

        return (int) $value;
    }
}
