<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * Dispatched after the change is flushed, under the names of {@see QuoteRequestEvents}.
 */
final class QuoteRequestEvent extends Event
{
    public function __construct(
        private readonly QuoteRequestInterface $quoteRequest,
        private readonly ?string $transition = null,
    ) {
    }

    public function getQuoteRequest(): QuoteRequestInterface
    {
        return $this->quoteRequest;
    }

    /** Workflow transition that caused the event, null for "created". */
    public function getTransition(): ?string
    {
        return $this->transition;
    }
}
