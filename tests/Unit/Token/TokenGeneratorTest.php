<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Token;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuoteRequestPlugin\Token\TokenGenerator;

final class TokenGeneratorTest extends TestCase
{
    public function testTokensAreLongUrlSafeAndUnique(): void
    {
        $generator = new TokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $token = $generator->generate();
            self::assertSame(TokenGenerator::LENGTH, strlen($token));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            $tokens[] = $token;
        }

        self::assertCount(200, array_unique($tokens));
    }

    public function testTokensMatchTheShopRouteRequirement(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20,64}$/', (new TokenGenerator())->generate());
    }
}
