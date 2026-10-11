<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Tmonier\SyliusQuoteRequestPlugin\Pdf\FakeQuotePdfGenerator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Test application only (dev and test environments): when WKHTMLTOPDF_PATH does not point to an
 * executable wkhtmltopdf, quote PDFs come from FakeQuotePdfGenerator (real template rendered,
 * placeholder PDF returned). Otherwise the default Snappy engine uses that binary. The decision
 * is taken when the container is compiled: clear the cache after changing the variable.
 */
return static function (ContainerConfigurator $container): void {
    if (!in_array($container->env(), ['dev', 'test'], true)) {
        return;
    }

    // Stands for a tier pricing plugin (disabled unless a test enables it).
    $container->services()
        ->set('tests.tmonier_quote_request.tier_pricing_probe', \Tests\Tmonier\SyliusQuoteRequestPlugin\Pricing\TierPricingProbeProcessor::class)
        ->tag('sylius.order_processor', ['priority' => 44])
    ;

    $binary = $_SERVER['WKHTMLTOPDF_PATH'] ?? $_ENV['WKHTMLTOPDF_PATH'] ?? null;
    if (is_string($binary) && is_executable($binary)) {
        $container->extension('tmonier_sylius_quote_request', ['pdf_binary' => $binary]);

        return;
    }

    $container->services()
        ->set('tests.tmonier_quote_request.fake_pdf_generator', FakeQuotePdfGenerator::class)
        ->args([service('twig')])
    ;
    $container->services()->alias('tmonier_quote_request.pdf_generator', 'tests.tmonier_quote_request.fake_pdf_generator');
};
