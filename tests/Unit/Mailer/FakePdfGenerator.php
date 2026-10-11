<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Mailer;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;

final class FakePdfGenerator implements QuotePdfGeneratorInterface
{
    public function __construct(
        private readonly bool $available,
        private readonly bool $fails = false,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function generate(QuoteRequestInterface $quoteRequest): string
    {
        if ($this->fails) {
            throw new \RuntimeException('wkhtmltopdf exited with code 127');
        }

        return '%PDF-fake ' . $quoteRequest->getNumber();
    }

    public function getFilename(QuoteRequestInterface $quoteRequest): string
    {
        return sprintf('quote-%s.pdf', (string) $quoteRequest->getNumber());
    }
}
