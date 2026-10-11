<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

final class QuoteOrderabilityChecker implements QuoteOrderabilityCheckerInterface
{
    public function __construct(private readonly AvailabilityCheckerInterface $availabilityChecker)
    {
    }

    public function isSellable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool
    {
        $variant = $line->getVariant();
        $product = $variant?->getProduct();
        if (null === $variant || !$product instanceof ProductInterface || null === $channel || $line->getQuantity() <= 0) {
            return false;
        }

        return $variant->isEnabled() && $product->isEnabled() && $product->hasChannel($channel) &&
            null !== $variant->getChannelPricingForChannel($channel);
    }

    public function isOrderable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool
    {
        $variant = $line->getVariant();

        return $this->isSellable($line, $channel) &&
            null !== $variant &&
            $this->availabilityChecker->isStockSufficient($variant, $line->getQuantity());
    }

    public function getUnorderableLines(QuoteRequestInterface $quoteRequest): array
    {
        $lines = [];
        foreach ($quoteRequest->getLines() as $line) {
            if (!$this->isOrderable($line, $quoteRequest->getChannel())) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
