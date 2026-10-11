<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Basket;

use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Products the visitor wants a quote for, kept in the session until the request is sent.
 */
interface QuoteBasketInterface
{
    /** @return array<int, int> quantity by variant id */
    public function all(ChannelInterface $channel): array;

    public function add(ChannelInterface $channel, int $variantId, int $quantity): void;

    /** @param array<int, int> $quantities quantity by variant id */
    public function replace(ChannelInterface $channel, array $quantities): void;

    public function remove(ChannelInterface $channel, int $variantId): void;

    public function clear(ChannelInterface $channel): void;

    public function count(ChannelInterface $channel): int;
}
