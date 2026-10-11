<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuoteRequestPlugin\RateLimit\RateLimitKeys;

final class RateLimitKeysTest extends TestCase
{
    public function testAddressesOfTheSameMailboxShareTheirKey(): void
    {
        $key = RateLimitKeys::email('victim@example.com');

        self::assertSame($key, RateLimitKeys::email(' Victim+1@Example.com '));
        self::assertSame($key, RateLimitKeys::email('victim+anything@example.com'));
        self::assertSame(RateLimitKeys::email('john.doe@gmail.com'), RateLimitKeys::email('J.O.H.N.D.O.E+x@googlemail.com'));
        self::assertNotSame($key, RateLimitKeys::email('victim@example.org'));
        // Dots are significant outside Gmail.
        self::assertNotSame(RateLimitKeys::email('a.b@example.com'), RateLimitKeys::email('ab@example.com'));
    }

    public function testAnIpv6NetworkIsOneKey(): void
    {
        self::assertSame(RateLimitKeys::ip('2001:db8:1:1::1'), RateLimitKeys::ip('2001:db8:1:1:ffff::42'));
        self::assertSame(RateLimitKeys::ip('2001:db8:1:1::1'), RateLimitKeys::ip('2001:db8:1:ff::1'), 'Same /56.');
        self::assertNotSame(RateLimitKeys::ip('2001:db8:1:1::1'), RateLimitKeys::ip('2001:db8:1:100::1'));
        self::assertSame('2001:db8:1::/56', RateLimitKeys::ip('2001:db8:1:1::1'));
        self::assertSame('203.0.113.7', RateLimitKeys::ip('203.0.113.7'));
        self::assertSame('unknown', RateLimitKeys::ip(null));
    }

    public function testIpv4MappedAddressesAreTheirIpv4Address(): void
    {
        self::assertSame('203.0.113.5', RateLimitKeys::ip('::ffff:203.0.113.5'));
        self::assertNotSame(RateLimitKeys::ip('::ffff:203.0.113.5'), RateLimitKeys::ip('::ffff:198.51.100.7'));
    }
}
