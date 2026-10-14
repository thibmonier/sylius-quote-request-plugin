<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Pdf;

use Knp\Snappy\AbstractGenerator;
use Knp\Snappy\GeneratorInterface;
use Knp\Snappy\Pdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
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

    /**
     * wkhtmltopdf must not read local files nor run scripts: the HTML holds customer input
     * (escaped by Twig, this is defence in depth) and loads no external resource.
     */
    public const OPTIONS = [
        'encoding' => 'utf-8',
        'page-size' => 'A4',
        'disable-local-file-access' => true,
        'disable-javascript' => true,
        'disable-external-links' => true,
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly ?GeneratorInterface $snappy = null,
        private readonly ?string $binary = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->engine() instanceof \Knp\Snappy\GeneratorInterface;
    }

    public function generate(QuoteRequestInterface $quoteRequest): string
    {
        $engine = $this->engine();
        if (!$engine instanceof \Knp\Snappy\GeneratorInterface) {
            throw new \LogicException('No PDF engine: install knplabs/knp-snappy-bundle or set tmonier_sylius_quote_request.pdf_binary.');
        }

        return $engine->getOutputFromHtml($this->twig->render(self::TEMPLATE, ['quote_request' => $quoteRequest]), self::OPTIONS);
    }

    public function getFilename(QuoteRequestInterface $quoteRequest): string
    {
        return sprintf('quote-%s.pdf', (string) $quoteRequest->getNumber());
    }

    private function engine(): ?GeneratorInterface
    {
        if ($this->resolved) {
            return $this->engine;
        }
        $this->resolved = true;

        $engine = $this->snappy;
        if (!$engine instanceof \Knp\Snappy\GeneratorInterface && null !== $this->binary) {
            if (!class_exists(Pdf::class)) {
                $this->logger?->warning('tmonier_sylius_quote_request.pdf_binary is set but knplabs/knp-snappy is not installed: quote PDFs are disabled.');

                return null;
            }
            $engine = new Pdf($this->binary);
        }

        // A configured engine whose binary is missing would fail on every PDF: treat it as absent.
        if ($engine instanceof AbstractGenerator && !$this->isExecutable((string) $engine->getBinary())) {
            $this->logger?->warning('The wkhtmltopdf binary "{binary}" is not executable: quote PDFs are disabled.', ['binary' => $engine->getBinary()]);

            return null;
        }

        return $this->engine = $engine;
    }

    private function isExecutable(string $binary): bool
    {
        // A full path, spaces included (knp-snappy escapes it), or Windows path.
        if (is_file($binary) && is_executable($binary)) {
            return true;
        }
        // A command with arguments ("xvfb-run wkhtmltopdf"): check the command itself.
        $command = strtok(trim($binary), ' ');
        if (false === $command) {
            return false;
        }
        if (str_contains($command, '/')) {
            return is_executable($command);
        }

        return null !== (new ExecutableFinder())->find($command);
    }
}
