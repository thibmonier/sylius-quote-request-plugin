<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Pdf;

use Knp\Snappy\GeneratorInterface;
use Knp\Snappy\Pdf;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\SnappyQuotePdfGenerator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SnappyQuotePdfGeneratorTest extends TestCase
{
    public function testItIsUnavailableWithoutEngine(): void
    {
        $generator = new SnappyQuotePdfGenerator($this->twig(), null, '/nonexistent/wkhtmltopdf');

        self::assertFalse($generator->isAvailable());
        $this->expectException(\LogicException::class);
        $generator->generate(new QuoteRequest());
    }

    public function testItRendersTheTemplateWithTheSnappyEngine(): void
    {
        $snappy = $this->createMock(GeneratorInterface::class);
        $snappy->expects(self::once())
            ->method('getOutputFromHtml')
            ->with('<h1>Q000042</h1>', self::callback(static fn (array $options): bool => true === $options['disable-local-file-access'] && true === $options['disable-javascript']))
            ->willReturn('%PDF-1.4 rendered')
        ;
        $generator = new SnappyQuotePdfGenerator($this->twig(), $snappy);
        $quote = new QuoteRequest();
        $quote->setNumber('Q000042');

        self::assertTrue($generator->isAvailable());
        self::assertSame('%PDF-1.4 rendered', $generator->generate($quote));
        self::assertSame('quote-Q000042.pdf', $generator->getFilename($quote));
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([SnappyQuotePdfGenerator::TEMPLATE => '<h1>{{ quote_request.number }}</h1>']));
    }

    public function testABundleEngineWithoutWorkingBinaryIsUnavailable(): void
    {
        $generator = new SnappyQuotePdfGenerator($this->twig(), new Pdf('/nonexistent/wkhtmltopdf'));

        self::assertFalse($generator->isAvailable());
    }

    public function testABinaryFoundInThePathIsAccepted(): void
    {
        $generator = new SnappyQuotePdfGenerator($this->twig(), new Pdf('sh'));

        self::assertTrue($generator->isAvailable());
    }

    public function testABinaryPathWithSpacesIsAccepted(): void
    {
        $directory = sprintf('%s/wk tools %s', sys_get_temp_dir(), bin2hex(random_bytes(4)));
        mkdir($directory);
        $binary = $directory . '/wkhtmltopdf';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);

        try {
            self::assertTrue((new SnappyQuotePdfGenerator($this->twig(), new Pdf($binary)))->isAvailable());
        } finally {
            unlink($binary);
            rmdir($directory);
        }
    }
}
