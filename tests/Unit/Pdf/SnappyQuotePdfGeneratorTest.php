<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Pdf;

use Knp\Snappy\GeneratorInterface;
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
            ->with('<h1>Q000042</h1>', self::arrayHasKey('page-size'))
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
}
