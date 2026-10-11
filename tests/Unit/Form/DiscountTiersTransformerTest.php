<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Tmonier\SyliusQuoteRequestPlugin\Form\DataTransformer\DiscountTiersTransformer;

final class DiscountTiersTransformerTest extends TestCase
{
    public function testItParsesTiersSortedByQuantity(): void
    {
        $tiers = (new DiscountTiersTransformer())->reverseTransform("100: 10\n1: 2,5; 50=7.5 ;");

        self::assertSame([
            ['min_quantity' => 1, 'value' => 2.5],
            ['min_quantity' => 50, 'value' => 7.5],
            ['min_quantity' => 100, 'value' => 10.0],
        ], $tiers);
    }

    public function testASingleValueStartsAtOneUnit(): void
    {
        self::assertSame([['min_quantity' => 1, 'value' => 5.0]], (new DiscountTiersTransformer())->reverseTransform('5'));
        self::assertSame([], (new DiscountTiersTransformer())->reverseTransform('  '));
    }

    public function testItFormatsTiers(): void
    {
        self::assertSame('1: 2.5; 50: 10', (new DiscountTiersTransformer())->transform([
            ['min_quantity' => 1, 'value' => 2.5],
            ['min_quantity' => 50, 'value' => 10.0],
        ]));
        self::assertSame('', (new DiscountTiersTransformer())->transform(null));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'text' => ['ten percent'];
        yield 'negative' => ['10: -5'];
        yield 'zero quantity' => ['0: 5'];
        yield 'too many tiers' => [implode(';', array_map(static fn (int $i): string => $i . ':1', range(1, 11)))];
    }

    #[DataProvider('invalidInputs')]
    public function testItRejectsInvalidInput(string $input): void
    {
        $this->expectException(TransformationFailedException::class);

        (new DiscountTiersTransformer())->reverseTransform($input);
    }
}
