<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Volume discount tiers <-> "min quantity: value" pairs separated by ";" or new lines,
 * e.g. "1: 5; 50: 7.5; 100: 10". A single value without quantity means "from 1 unit".
 *
 * @implements DataTransformerInterface<list<array{min_quantity: int, value: float}>, string>
 */
final class DiscountTiersTransformer implements DataTransformerInterface
{
    public const MAX_TIERS = 10;

    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        $pairs = [];
        foreach ($value as $tier) {
            $pairs[] = sprintf('%d: %s', $tier['min_quantity'], rtrim(rtrim(number_format($tier['value'], 2, '.', ''), '0'), '.'));
        }

        return implode('; ', $pairs);
    }

    public function reverseTransform(mixed $value): array
    {
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }

        $tiers = [];
        foreach (preg_split('/[;\n]+/', $value) ?: [] as $pair) {
            $pair = trim($pair);
            if ('' === $pair) {
                continue;
            }
            if (1 !== preg_match('/^(?:(\d+)\s*[:=]\s*)?(\d+(?:[.,]\d+)?)$/', $pair, $matches)) {
                throw new TransformationFailedException(sprintf('Invalid volume discount tier "%s".', $pair));
            }

            $minQuantity = '' === $matches[1] ? 1 : (int) $matches[1];
            if ($minQuantity < 1) {
                throw new TransformationFailedException('The minimum quantity of a tier is at least 1.');
            }
            $tiers[$minQuantity] = ['min_quantity' => $minQuantity, 'value' => (float) str_replace(',', '.', $matches[2])];
        }

        if (count($tiers) > self::MAX_TIERS) {
            throw new TransformationFailedException('Too many volume discount tiers.');
        }
        ksort($tiers);

        return array_values($tiers);
    }
}
