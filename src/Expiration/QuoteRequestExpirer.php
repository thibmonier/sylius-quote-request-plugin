<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Expiration;

use Psr\Clock\ClockInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestExpirer implements QuoteRequestExpirerInterface
{
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function expire(bool $dryRun = false): array
    {
        $expired = [];
        foreach ($this->quoteRequestRepository->findExpiredProposals($this->clock->now()) as $quoteRequest) {
            if (!$this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_EXPIRE)) {
                continue;
            }
            if (!$dryRun) {
                $this->quoteRequestManager->apply($quoteRequest, QuoteRequestTransitions::TRANSITION_EXPIRE);
            }
            $expired[] = (string) $quoteRequest->getNumber();
        }

        return $expired;
    }
}
