<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Pdf;

use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\SnappyQuotePdfGenerator;
use Twig\Environment;

/**
 * Test application only: renders the real PDF template (so that it is exercised) and returns a
 * minimal one-page PDF "Quote <number>" instead of calling wkhtmltopdf.
 */
final class FakeQuotePdfGenerator implements QuotePdfGeneratorInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generate(QuoteRequestInterface $quoteRequest): string
    {
        $html = $this->twig->render(SnappyQuotePdfGenerator::TEMPLATE, ['quote_request' => $quoteRequest]);
        $text = sprintf('Quote %s (%d bytes of HTML)', (string) $quoteRequest->getNumber(), strlen($html));

        return self::pdf($text);
    }

    public function getFilename(QuoteRequestInterface $quoteRequest): string
    {
        return sprintf('quote-%s.pdf', (string) $quoteRequest->getNumber());
    }

    private static function pdf(string $text): string
    {
        $stream = sprintf("BT /F1 18 Tf 72 760 Td (%s) Tj ET", addcslashes($text, '()\\'));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }
}
