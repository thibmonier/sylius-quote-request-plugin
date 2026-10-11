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

    /** IPv4 address, or the /64 network of an IPv6 address (one subscriber gets a whole /64). */
    public static function ip(?string $ip): string
    {
        if (null === $ip || '' === $ip) {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if (false === $packed || 16 !== strlen($packed)) {
            return $ip;
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private function __construct()
    {
    }
}
