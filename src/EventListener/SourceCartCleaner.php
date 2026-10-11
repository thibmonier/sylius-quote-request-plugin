<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;

/**
 * "ordered" (customer checkout or merchant conversion): the quoted products leave the cart the
 * request was made from ("Request a quote for this cart"), so that they are not bought twice.
 */
final class SourceCartCleaner
{
    public function __construct(private readonly QuoteCartManagerInterface $quoteCartManager)
    {
    }

    public function __invoke(QuoteRequestEvent $event): void
    {
        $quoteRequest = $event->getQuoteRequest();
        $source = $quoteRequest->getSourceOrder();
        if (null !== $source) {
            $this->quoteCartManager->removeQuotedProducts($source, $quoteRequest);
        }
    }
}
