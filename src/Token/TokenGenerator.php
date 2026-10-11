<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Token;

final class TokenGenerator implements TokenGeneratorInterface
{
    public const LENGTH = 43;

    public function generate(): string
    {
        // 32 random bytes (256 bits), base64url without padding: 43 characters.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
