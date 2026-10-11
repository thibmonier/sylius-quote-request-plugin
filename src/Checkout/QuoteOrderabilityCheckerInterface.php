<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Core\Model\ChannelInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

/**
 * Whether the lines of a quote can still be ordered, with the rules of the Sylius checkout
 * (OrderProductEligibility, InStock): variant and product enabled, product in the channel, price
 * in the channel, stock sufficient for tracked variants.
 */
interface QuoteOrderabilityCheckerInterface
{
    /** Everything but the stock: the line can be put in a cart. */
    public function isSellable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool;

    public function isOrderable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool;

    /** @return list<QuoteRequestLineInterface> */
    public function getUnorderableLines(QuoteRequestInterface $quoteRequest): array;
}
