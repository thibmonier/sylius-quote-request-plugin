<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

/**
 * Called by two order processors and the cart page: the lookup is memoised per order (one
 * query per cart and request). It does not rely on the cart content, so a quote cart emptied by
 * the customer still gets its quote lines back.
 */
final class QuoteOrderResolver implements QuoteOrderResolverInterface
{
    /** @var \WeakMap<BaseOrderInterface, QuoteRequestInterface|false> */
    private \WeakMap $resolved;

    public function __construct(private readonly QuoteRequestRepositoryInterface $quoteRequestRepository)
    {
        $this->resolved = new \WeakMap();
    }

    public function resolveLockingQuote(BaseOrderInterface $order): ?QuoteRequestInterface
    {
        if (!$order instanceof OrderInterface || OrderInterface::STATE_CART !== $order->getState() || null === $order->getId()) {
            return null;
        }

        if (!isset($this->resolved[$order])) {
            $this->resolved[$order] = $this->quoteRequestRepository->findOneByOrder($order) ?? false;
        }

        $quoteRequest = $this->resolved[$order];
        if (false === $quoteRequest || QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus() || $quoteRequest->getOrder() !== $order) {
            return null;
        }

        return $quoteRequest;
    }

    public function remember(BaseOrderInterface $order, QuoteRequestInterface $quoteRequest): void
    {
        $this->resolved[$order] = $quoteRequest;
    }

    public function forget(BaseOrderInterface $order): void
    {
        unset($this->resolved[$order]);
    }
}
