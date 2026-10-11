<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class QuoteOrderResolver implements QuoteOrderResolverInterface
{
    public function __construct(private readonly QuoteRequestRepositoryInterface $quoteRequestRepository)
    {
    }

    public function resolveLockingQuote(BaseOrderInterface $order): ?QuoteRequestInterface
    {
        if (!$order instanceof OrderInterface || OrderInterface::STATE_CART !== $order->getState() || null === $order->getId()) {
            return null;
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByOrder($order);
        if (null === $quoteRequest || QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
            return null;
        }

        return $quoteRequest;
    }
}
