<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * A proposal needs at least one line that can be ordered; it can be accepted only while valid.
 */
final class QuoteRequestGuardListener
{
    public const CODE_NO_LINE = 'tmonier_quote_request.no_line';

    public const CODE_EXPIRED = 'tmonier_quote_request.expired';

    public function __construct(private readonly ClockInterface $clock)
    {
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
