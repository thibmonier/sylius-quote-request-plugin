<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusQuoteRequestPlugin\DependencyInjection\TmonierSyliusQuoteRequestExtension;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter('tmonier_quote_request.allow_guests'));
        self::assertSame(30, $container->getParameter('tmonier_quote_request.default_validity_days'));
        self::assertNull($container->getParameter('tmonier_quote_request.admin_email'));
        self::assertTrue($container->getParameter('tmonier_quote_request.from_product_page'));
        self::assertTrue($container->getParameter('tmonier_quote_request.from_cart'));
        self::assertFalse($container->getParameter('tmonier_quote_request.allow_promotions'));
        self::assertSame(100, $container->getParameter('tmonier_quote_request.max_lines'));
        self::assertSame(QuoteRequest::class, $container->getParameter('tmonier_quote_request.model.quote_request.class'));
        self::assertTrue($container->hasDefinition('tmonier_quote_request.order_processing.price_lock'));
    }

    public function testCustomValues(): void
    {
        $container = $this->load([
            'allow_guests' => false,
            'default_validity_days' => 15,
            'admin_email' => 'sales@example.com',
            'from_product_page' => false,
            'from_cart' => false,
            'allow_promotions' => true,
        ]);

        self::assertFalse($container->getParameter('tmonier_quote_request.allow_guests'));
        self::assertSame(15, $container->getParameter('tmonier_quote_request.default_validity_days'));
        self::assertSame('sales@example.com', $container->getParameter('tmonier_quote_request.admin_email'));
        self::assertFalse($container->getParameter('tmonier_quote_request.from_product_page'));
        self::assertFalse($container->getParameter('tmonier_quote_request.from_cart'));
        self::assertTrue($container->getParameter('tmonier_quote_request.allow_promotions'));
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $container->setParameter('kernel.bundles_metadata', []);
        $extension = new TmonierSyliusQuoteRequestExtension();
        $container->registerExtension($extension);
        $container->prependExtensionConfig($extension->getAlias(), $config);
        $extension->prepend($container);
        $extension->load([$config], $container);

        return $container;
    }
}
