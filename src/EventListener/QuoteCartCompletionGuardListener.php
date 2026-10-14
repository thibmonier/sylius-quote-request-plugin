<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

/**
 * `sylius_order_checkout` → `complete` guard (Symfony Workflow adapter): an order holding items at
 * quote prices completes only while it is the cart of the accepted quote holding them. The
 * Doctrine listener enforces the same rule for the other adapters.
 */
final readonly class QuoteCartCompletionGuardListener
{
    public const CODE = 'tmonier_quote_request.unbacked_quote_prices';

    public function __construct(private QuoteRequestRepositoryInterface $quoteRequestRepository)
    {
    }

    public function __invoke(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface || [] === QuoteCartInspector::lockedItems($order)) {
            return;
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByOrder($order);
        if ($quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface && QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
            $quoteRequest = null;
        }

        if (!QuoteCartInspector::isBackedByQuote($order, $quoteRequest)) {
            $event->addTransitionBlocker(new TransitionBlocker('The cart holds quote prices without its accepted quote.', self::CODE));
        }
    }
}
