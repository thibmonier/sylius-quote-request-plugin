<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

/**
 * Called by two order processors and the cart page, so the lookup is memoised per order, and
 * carts without immutable item (all the regular carts) never query the database.
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
            if (!$this->hasImmutableItem($order)) {
                return null;
            }
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

    private function hasImmutableItem(OrderInterface $order): bool
    {
        foreach ($order->getItems() as $item) {
            if ($item->isImmutable()) {
                return true;
            }
        }

        return false;
    }
}
