<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\RateLimit;

/**
 * Rate limiter keys that the sender cannot multiply for free.
 */
final class RateLimitKeys
{
    /** The same mailbox: case, "+tag" sub-addressing, and the dots of Gmail addresses ignored. */
    public static function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if (false === $at) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');
        if (false !== $plus) {
            $local = substr($local, 0, $plus);
        }
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /**
     * IPv4 address (also when written as an IPv4-mapped IPv6 address, ::ffff:a.b.c.d), or the
     * /56 network of an IPv6 address: ISPs commonly give a whole /56 (or /64) to one subscriber.
     */
    public static function ip(?string $ip): string
    {
        if (null === $ip || '' === $ip) {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if (false === $packed || 16 !== strlen($packed)) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop(substr($packed, 0, 7) . str_repeat("\0", 9)) . '/56';
    }

    private function __construct()
    {
    }
}
