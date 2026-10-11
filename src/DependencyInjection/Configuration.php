<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessage;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLine;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepository;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_quote_request');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('allow_guests')
                    ->info('Visitors without an account can request a quote (they follow a secret link sent by e-mail).')
                    ->defaultTrue()
                ->end()
                ->integerNode('default_validity_days')
                    ->info('Validity of a proposal, in days, when the merchant does not set a date.')
                    ->defaultValue(30)
                    ->min(1)
                ->end()
                ->scalarNode('admin_email')
                    ->info('Recipient of the merchant notifications; the channel contact e-mail when null.')
                    ->defaultNull()
                ->end()
                ->booleanNode('from_product_page')
                    ->info('Show the "Add to quote request" form on product pages.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('from_cart')
                    ->info('Show the "Request a quote for this cart" button on the cart page.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('allow_promotions')
                    ->info('Apply the cart promotions and coupons on top of the negotiated prices of an accepted quote.')
                    ->defaultFalse()
                ->end()
                ->integerNode('max_lines')
                    ->info('Maximum number of lines of one quote request.')
                    ->defaultValue(100)
                    ->min(1)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
            ->end()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $rootNode): void
    {
        $resources = [
            'quote_request' => [QuoteRequest::class, QuoteRequestInterface::class, QuoteRequestRepository::class],
            'quote_request_line' => [QuoteRequestLine::class, QuoteRequestLineInterface::class, null],
            'quote_message' => [QuoteMessage::class, QuoteMessageInterface::class, null],
        ];

        $children = $rootNode
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
        ;

        foreach ($resources as $name => [$model, $interface, $repository]) {
            $classes = $children
                ->arrayNode($name)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('classes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('model')->defaultValue($model)->cannotBeEmpty()->end()
                                ->scalarNode('interface')->defaultValue($interface)->cannotBeEmpty()->end()
                                ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                ->scalarNode('factory')->defaultValue(Factory::class)->end()
            ;
            if (null !== $repository) {
                $classes->scalarNode('repository')->defaultValue($repository)->cannotBeEmpty()->end();
            } else {
                $classes->scalarNode('repository')->end();
            }
            $classes
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ;
        }
    }
}
