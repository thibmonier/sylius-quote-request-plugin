<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Token;

interface TokenGeneratorInterface
{
    /** URL-safe random token giving access to one quote without an account. */
    public function generate(): string;
}
