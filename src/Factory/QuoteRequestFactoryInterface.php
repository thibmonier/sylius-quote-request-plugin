<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Factory;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

interface QuoteRequestFactoryInterface
{
    /** New request with a fresh access token, prefilled from the customer when known. */
    public function createForChannel(ChannelInterface $channel, string $localeCode, ?CustomerInterface $customer = null): QuoteRequestInterface;

    /** Line at the current channel price; null when the variant is not sold in the channel. */
    public function createLine(QuoteRequestInterface $quoteRequest, ProductVariantInterface $variant, int $quantity): ?QuoteRequestLineInterface;

    public function createMessage(string $authorType, ?string $authorName, string $body): QuoteMessageInterface;
}
