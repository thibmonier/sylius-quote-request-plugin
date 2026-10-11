<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Order\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;

final class QuoteCartChecker implements QuoteCartCheckerInterface
{
    public function __construct(private readonly QuoteOrderResolverInterface $quoteOrderResolver)
    {
    }

    public function isQuoteCart(OrderInterface $order): bool
    {
        return null !== $this->quoteOrderResolver->resolveLockingQuote($order);
    }
}
