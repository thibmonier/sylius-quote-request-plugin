<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pdf;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * PDF of a proposal. The engine is optional and swappable: decorate or replace the
 * `tmonier_quote_request.pdf_generator` service to use your own.
 */
interface QuotePdfGeneratorInterface
{
    /** False when no PDF engine is configured: download links and e-mail attachments are hidden. */
    public function isAvailable(): bool;

    /** @return string the PDF content */
    public function generate(QuoteRequestInterface $quoteRequest): string;

    public function getFilename(QuoteRequestInterface $quoteRequest): string;
}
