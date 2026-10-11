<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Expiration;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestExpirer implements QuoteRequestExpirerInterface
{
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function expire(bool $dryRun = false): array
    {
        $ids = array_map(
            static fn (QuoteRequestInterface $quoteRequest): mixed => $quoteRequest->getId(),
            $this->quoteRequestRepository->findExpiredProposals($this->clock->now()),
        );

        $expired = [];
        foreach ($ids as $id) {
            // Fetched one by one: a failed quote clears the entity manager.
            $quoteRequest = $this->quoteRequestRepository->find($id);
            if (!$quoteRequest instanceof QuoteRequestInterface || !$this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_EXPIRE)) {
                continue;
            }
            if ($dryRun) {
                $expired[] = (string) $quoteRequest->getNumber();

                continue;
            }

            try {
                $this->quoteRequestManager->apply($quoteRequest, QuoteRequestTransitions::TRANSITION_EXPIRE);
                $expired[] = (string) $quoteRequest->getNumber();
            } catch (QuoteTransitionException $exception) {
                // Accepted or changed meanwhile (e.g. at the validity boundary): skip it.
                $this->logger->info('Quote {id} not expired: {message}', ['id' => $id, 'message' => $exception->getMessage()]);
            }
        }

        return $expired;
    }
}
