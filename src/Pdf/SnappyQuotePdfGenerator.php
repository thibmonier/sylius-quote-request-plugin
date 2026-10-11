<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pdf;

use Knp\Snappy\GeneratorInterface;
use Knp\Snappy\Pdf;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Twig\Environment;

/**
 * Default engine: wkhtmltopdf through knplabs/knp-snappy (`knp_snappy.pdf` of KnpSnappyBundle,
 * or the `pdf_binary` path). Unavailable when neither is installed.
 */
final class SnappyQuotePdfGenerator implements QuotePdfGeneratorInterface
{
    public const TEMPLATE = '@TmonierSyliusQuoteRequestPlugin/pdf/quote.html.twig';

    private ?GeneratorInterface $engine = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Environment $twig,
        private readonly ?GeneratorInterface $snappy = null,
        private readonly ?string $binary = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->engine();
    }

    public function generate(QuoteRequestInterface $quoteRequest): string
    {
        $engine = $this->engine();
        if (null === $engine) {
            throw new \LogicException('No PDF engine: install knplabs/knp-snappy-bundle or set tmonier_sylius_quote_request.pdf_binary.');
        }

        return $engine->getOutputFromHtml($this->twig->render(self::TEMPLATE, ['quote_request' => $quoteRequest]), [
            'encoding' => 'utf-8',
            'page-size' => 'A4',
        ]);
    }

    public function getFilename(QuoteRequestInterface $quoteRequest): string
    {
        return sprintf('quote-%s.pdf', (string) $quoteRequest->getNumber());
    }

    private function engine(): ?GeneratorInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->engine = $this->snappy;
            if (null === $this->engine && null !== $this->binary && class_exists(Pdf::class) && is_executable($this->binary)) {
                $this->engine = new Pdf($this->binary);
            }
        }

        return $this->engine;
    }
}
