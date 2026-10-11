<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\SnappyQuotePdfGenerator;

/**
 * Real wkhtmltopdf rendering, run when WKHTMLTOPDF_PATH points to a binary (e.g.
 * tests/TestApplication/bin/wkhtmltopdf-docker) and pdftotext is installed; skipped otherwise.
 */
final class QuotePdfRenderingTest extends WebTestCase
{
    use QuoteRequestTestTrait;

    protected function setUp(): void
    {
        $binary = $_SERVER['WKHTMLTOPDF_PATH'] ?? $_ENV['WKHTMLTOPDF_PATH'] ?? null;
        if (!is_string($binary) || !is_executable($binary)) {
            self::markTestSkipped('WKHTMLTOPDF_PATH does not point to wkhtmltopdf.');
        }
        if (null === (new ExecutableFinder())->find('pdftotext')) {
            self::markTestSkipped('pdftotext is not installed.');
        }
        $this->setUpClient();
        self::assertInstanceOf(SnappyQuotePdfGenerator::class, self::getContainer()->get('tmonier_quote_request.pdf_generator'));
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->tearDownClient();
        }
        parent::tearDown();
    }

    public function testTheProposalPdfIsRenderedAndCustomerInputStaysText(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 12, 4321]], null, 'proposed');
        $quote->setCompany('<img src="file:///etc/hosts"><script>document.write("INJECTED")</script>ACME');
        $this->entityManager()->flush();
        $this->loginAdmin();

        $this->client->request('GET', sprintf('/admin/quote-requests/%d/pdf', $this->idOf($quote)));

        self::assertResponseIsSuccessful();
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);
        $text = $this->pdfToText($pdf);
        self::assertStringContainsString((string) $quote->getNumber(), $text);
        self::assertStringContainsString('43.21', $text);
        self::assertStringContainsString('<img src="file:///etc/hosts">', $text, 'The markup is printed as text, not interpreted.');
        self::assertStringNotContainsString("INJECTED\n", $text);
    }

    private function pdfToText(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'quote_pdf_');
        self::assertIsString($file);
        file_put_contents($file, $pdf);
        $process = new Process(['pdftotext', '-layout', $file, '-']);
        $process->mustRun();
        unlink($file);

        return $process->getOutput();
    }
}
