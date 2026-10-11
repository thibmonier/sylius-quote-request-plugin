<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TmonierSyliusQuoteRequestExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{allow_guests: bool, default_validity_days: int, admin_email: string|null, from_product_page: bool, from_cart: bool, allow_promotions: bool, max_lines: int, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_quote_request', $config['driver'], $config['resources'], $container);

        foreach (['allow_guests', 'default_validity_days', 'admin_email', 'from_product_page', 'from_cart', 'allow_promotions', 'max_lines'] as $key) {
            $container->setParameter('tmonier_quote_request.' . $key, $config[$key]);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded, so the model class parameters
        // have to exist at prepend time.
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_quote_request.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusQuoteRequestPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusQuoteRequestPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_quote_request';
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusQuoteRequestPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusQuoteRequestPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
