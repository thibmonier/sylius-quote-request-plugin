<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Basket;

use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SessionQuoteBasket implements QuoteBasketInterface
{
    public const SESSION_KEY = '_tmonier_quote_request.basket';

    public const MAX_QUANTITY = 1000000;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly int $maxLines,
    ) {
    }

    public function all(ChannelInterface $channel): array
    {
        $session = $this->session();
        if (null === $session) {
            return [];
        }

        $stored = $session->get($this->key($channel), []);
        if (!is_array($stored)) {
            return [];
        }

        $quantities = [];
        foreach ($stored as $variantId => $quantity) {
            if (is_int($variantId) && is_int($quantity) && $quantity > 0) {
                $quantities[$variantId] = $quantity;
            }
        }

        return $quantities;
    }

    public function add(ChannelInterface $channel, int $variantId, int $quantity): void
    {
        $quantities = $this->all($channel);
        $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $quantity;
        $this->replace($channel, $quantities);
    }

    public function replace(ChannelInterface $channel, array $quantities): void
    {
        $clean = [];
        foreach ($quantities as $variantId => $quantity) {
            if ($quantity > 0) {
                $clean[$variantId] = min($quantity, self::MAX_QUANTITY);
            }
        }

        $this->session()?->set($this->key($channel), array_slice($clean, 0, $this->maxLines, true));
    }

    public function remove(ChannelInterface $channel, int $variantId): void
    {
        $quantities = $this->all($channel);
        unset($quantities[$variantId]);
        $this->replace($channel, $quantities);
    }

    public function clear(ChannelInterface $channel): void
    {
        $this->session()?->remove($this->key($channel));
    }

    public function count(ChannelInterface $channel): int
    {
        return count($this->all($channel));
    }

    private function key(ChannelInterface $channel): string
    {
        return sprintf('%s.%s', self::SESSION_KEY, (string) $channel->getCode());
    }

    private function session(): ?SessionInterface
    {
        try {
            return $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return null;
        }
    }
}
