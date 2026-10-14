<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Sylius\Component\Order\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;

final readonly class QuoteCartChecker implements QuoteCartCheckerInterface
{
    public function __construct(private QuoteOrderResolverInterface $quoteOrderResolver)
    {
    }

    public function isQuoteCart(OrderInterface $order): bool
    {
        return $this->quoteOrderResolver->resolveLockingQuote($order) instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
    }
}
