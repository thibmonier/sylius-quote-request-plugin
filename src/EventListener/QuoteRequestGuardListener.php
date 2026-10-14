<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteOrderabilityCheckerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * A proposal needs at least one line with a product; it can be accepted only while valid and
 * when every line can still be ordered (product sold in the channel, stock sufficient).
 */
final readonly class QuoteRequestGuardListener
{
    public const CODE_NO_LINE = 'tmonier_quote_request.no_line';

    public const CODE_EXPIRED = 'tmonier_quote_request.expired';

    public const CODE_UNAVAILABLE = 'tmonier_quote_request.unavailable';

    public function __construct(
        private ClockInterface $clock,
        private QuoteOrderabilityCheckerInterface $orderabilityChecker,
    ) {
    }

    public function guardPropose(GuardEvent $event): void
    {
        $this->blockWithoutLines($event);
    }

    public function guardAccept(GuardEvent $event): void
    {
        $quoteRequest = $event->getSubject();
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            return;
        }

        if (!$quoteRequest->isValidAt($this->clock->now())) {
            $event->addTransitionBlocker(new TransitionBlocker('The proposal is no longer valid.', self::CODE_EXPIRED));
        }

        if ([] !== $this->orderabilityChecker->getUnorderableLines($quoteRequest)) {
            $event->addTransitionBlocker(new TransitionBlocker('A product of the quote can no longer be ordered.', self::CODE_UNAVAILABLE));
        }

        $this->blockWithoutLines($event);
    }

    private function blockWithoutLines(GuardEvent $event): void
    {
        $quoteRequest = $event->getSubject();
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            return;
        }

        foreach ($quoteRequest->getLines() as $line) {
            if (null !== $line->getVariant() && $line->getQuantity() > 0) {
                return;
            }
        }

        $event->addTransitionBlocker(new TransitionBlocker('The quote has no line that can be ordered.', self::CODE_NO_LINE));
    }
}
