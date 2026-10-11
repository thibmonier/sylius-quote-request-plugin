<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusQuoteRequestPlugin\Basket\QuoteBasketInterface;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManager;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Command\ExpireQuoteRequestsCommand;
use Tmonier\SyliusQuoteRequestPlugin\Controller\Admin\QuoteRequestController as AdminQuoteRequestController;
use Tmonier\SyliusQuoteRequestPlugin\Controller\Shop\QuoteRequestController as ShopQuoteRequestController;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverter;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\EventListener\OrderCheckoutCompletedListener;
use Tmonier\SyliusQuoteRequestPlugin\EventListener\QuoteRequestGuardListener;
use Tmonier\SyliusQuoteRequestPlugin\Expiration\QuoteRequestExpirer;
use Tmonier\SyliusQuoteRequestPlugin\Expiration\QuoteRequestExpirerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactory;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Fixture\QuoteRequestFixture;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\ConvertQuoteToOrderType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\QuoteRequestAdminType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\QuoteRequestLineAdminType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\AddToQuoteType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteRequestLineQuantityType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteRequestType;
use Tmonier\SyliusQuoteRequestPlugin\Mailer\QuoteRequestMailer;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManager;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Menu\AccountMenuListener;
use Tmonier\SyliusQuoteRequestPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteAwarePromotionProcessor;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolver;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuotePriceLockProcessor;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteDiscountCalculator;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteDiscountCalculatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteOrderSynchronizer;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteOrderSynchronizerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteTotalsCalculator;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteTotalsCalculatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Token\TokenGenerator;
use Tmonier\SyliusQuoteRequestPlugin\Token\TokenGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Twig\QuoteRequestExtension;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $graph = QuoteRequestTransitions::GRAPH;

    // Domain
    $services->set('tmonier_quote_request.token_generator', TokenGenerator::class);
    $services->alias(TokenGeneratorInterface::class, 'tmonier_quote_request.token_generator');

    $services
        ->set('tmonier_quote_request.factory', QuoteRequestFactory::class)
        ->args([
            service('tmonier_quote_request.factory.quote_request'),
            service('tmonier_quote_request.factory.quote_request_line'),
            service('tmonier_quote_request.factory.quote_message'),
            service('tmonier_quote_request.token_generator'),
            service('sylius.calculator.product_variant_price'),
            service('clock'),
        ])
    ;
    $services->alias(QuoteRequestFactoryInterface::class, 'tmonier_quote_request.factory');

    $services
        ->set('tmonier_quote_request.basket', SessionQuoteBasket::class)
        ->args([service('request_stack'), param('tmonier_quote_request.max_lines')])
    ;
    $services->alias(QuoteBasketInterface::class, 'tmonier_quote_request.basket');

    $services
        ->set('tmonier_quote_request.manager', QuoteRequestManager::class)
        ->args([
            service('tmonier_quote_request.manager.quote_request'),
            service('sylius_abstraction.state_machine'),
            service('event_dispatcher'),
            service('tmonier_quote_request.cart_manager'),
            service('clock'),
            param('tmonier_quote_request.default_validity_days'),
        ])
    ;
    $services->alias(QuoteRequestManagerInterface::class, 'tmonier_quote_request.manager');

    $services
        ->set('tmonier_quote_request.expirer', QuoteRequestExpirer::class)
        ->args([
            service('tmonier_quote_request.repository.quote_request'),
            service('tmonier_quote_request.manager'),
            service('clock'),
        ])
    ;
    $services->alias(QuoteRequestExpirerInterface::class, 'tmonier_quote_request.expirer');

    $services
        ->set('tmonier_quote_request.command.expire', ExpireQuoteRequestsCommand::class)
        ->args([service('tmonier_quote_request.expirer')])
        ->tag('console.command')
    ;

    // Pricing and order processing
    $services->set('tmonier_quote_request.discount_calculator', QuoteDiscountCalculator::class);
    $services->alias(QuoteDiscountCalculatorInterface::class, 'tmonier_quote_request.discount_calculator');

    $services
        ->set('tmonier_quote_request.order_synchronizer', QuoteOrderSynchronizer::class)
        ->args([
            service('sylius.factory.order_item'),
            service('sylius.modifier.order_item_quantity'),
            service('sylius.factory.adjustment'),
            service('sylius.distributor.proportional_integer'),
            service('sylius.distributor.integer'),
            service('tmonier_quote_request.discount_calculator'),
            service('translator'),
        ])
    ;
    $services->alias(QuoteOrderSynchronizerInterface::class, 'tmonier_quote_request.order_synchronizer');

    $services
        ->set('tmonier_quote_request.totals_calculator', QuoteTotalsCalculator::class)
        ->args([
            service('sylius.factory.order'),
            service('tmonier_quote_request.order_synchronizer'),
            service('sylius.order_processing.order_taxes_processor'),
        ])
    ;
    $services->alias(QuoteTotalsCalculatorInterface::class, 'tmonier_quote_request.totals_calculator');

    $services
        ->set('tmonier_quote_request.order_resolver', QuoteOrderResolver::class)
        ->args([service('tmonier_quote_request.repository.quote_request')])
    ;
    $services->alias(QuoteOrderResolverInterface::class, 'tmonier_quote_request.order_resolver');

    $services
        ->set('tmonier_quote_request.order_processing.price_lock', QuotePriceLockProcessor::class)
        ->args([
            service('tmonier_quote_request.order_resolver'),
            service('tmonier_quote_request.order_synchronizer'),
        ])
        // After the prices recalculator (50), before shipping (40), promotions (20) and taxes (10).
        ->tag('sylius.order_processor', ['priority' => 45])
    ;

    $services
        ->set('tmonier_quote_request.order_processing.promotion', QuoteAwarePromotionProcessor::class)
        ->decorate('sylius.order_processing.order_promotion_processor')
        ->args([
            service('.inner'),
            service('tmonier_quote_request.order_resolver'),
            param('tmonier_quote_request.allow_promotions'),
        ])
    ;

    $services
        ->set('tmonier_quote_request.cart_manager', QuoteCartManager::class)
        ->args([
            service('sylius.factory.order'),
            service('sylius.order_processing.order_processor'),
            service('sylius.manager.order'),
            service('sylius_shop.storage.cart_session'),
        ])
    ;
    $services->alias(QuoteCartManagerInterface::class, 'tmonier_quote_request.cart_manager');

    $services
        ->set('tmonier_quote_request.order_converter', QuoteOrderConverter::class)
        ->args([
            service('tmonier_quote_request.manager'),
            service('tmonier_quote_request.cart_manager'),
            service('sylius_abstraction.state_machine'),
            service('sylius.order_processing.order_processor'),
            service('sylius.repository.customer'),
            service('sylius.factory.customer'),
            service('sylius.manager.order'),
        ])
    ;
    $services->alias(QuoteOrderConverterInterface::class, 'tmonier_quote_request.order_converter');

    // Workflow and events
    $services
        ->set('tmonier_quote_request.listener.guard', QuoteRequestGuardListener::class)
        ->args([service('clock')])
        ->tag('kernel.event_listener', ['event' => sprintf('workflow.%s.guard.%s', $graph, QuoteRequestTransitions::TRANSITION_PROPOSE), 'method' => 'guardPropose'])
        ->tag('kernel.event_listener', ['event' => sprintf('workflow.%s.guard.%s', $graph, QuoteRequestTransitions::TRANSITION_ACCEPT), 'method' => 'guardAccept'])
    ;

    $services
        ->set('tmonier_quote_request.listener.order_checkout_completed', OrderCheckoutCompletedListener::class)
        ->args([
            service('tmonier_quote_request.repository.quote_request'),
            service('tmonier_quote_request.manager'),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_order_checkout.completed.complete'])
    ;

    $services
        ->set('tmonier_quote_request.mailer', QuoteRequestMailer::class)
        ->args([
            service('sylius.email_sender'),
            param('tmonier_quote_request.admin_email'),
        ])
        ->tag('kernel.event_subscriber')
    ;

    // Menus
    $services
        ->set('tmonier_quote_request.listener.admin_menu', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;
    $services
        ->set('tmonier_quote_request.listener.account_menu', AccountMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.shop.account'])
    ;

    // Forms
    foreach ([QuoteRequestType::class, QuoteRequestLineQuantityType::class, AddToQuoteType::class, QuoteMessageType::class, QuoteRequestAdminType::class, QuoteRequestLineAdminType::class, ConvertQuoteToOrderType::class] as $formType) {
        $services->set($formType)->tag('form.type');
    }

    // Controllers
    $services
        ->set('tmonier_quote_request.controller.shop', ShopQuoteRequestController::class)
        ->args([
            service('tmonier_quote_request.repository.quote_request'),
            service('tmonier_quote_request.factory'),
            service('tmonier_quote_request.manager'),
            service('tmonier_quote_request.cart_manager'),
            service('tmonier_quote_request.basket'),
            service('sylius.repository.product'),
            service('sylius.repository.product_variant'),
            service('sylius.context.channel'),
            service('sylius.context.locale'),
            service('sylius.context.customer'),
            service('sylius.context.cart'),
            param('tmonier_quote_request.allow_guests'),
            param('tmonier_quote_request.from_product_page'),
            param('tmonier_quote_request.from_cart'),
            param('tmonier_quote_request.max_lines'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_quote_request.controller.admin', AdminQuoteRequestController::class)
        ->args([
            service('tmonier_quote_request.repository.quote_request'),
            service('tmonier_quote_request.factory'),
            service('tmonier_quote_request.manager'),
            service('tmonier_quote_request.order_converter'),
            service('sylius.repository.shipping_method'),
            service('sylius.repository.payment_method'),
            service('sylius.factory.address'),
            service('translator'),
            param('tmonier_quote_request.max_lines'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    // Twig
    $services
        ->set('tmonier_quote_request.twig.extension', QuoteRequestExtension::class)
        ->args([
            service('tmonier_quote_request.totals_calculator'),
            service('tmonier_quote_request.manager'),
            service('tmonier_quote_request.order_resolver'),
            [
                'allow_guests' => param('tmonier_quote_request.allow_guests'),
                'from_product_page' => param('tmonier_quote_request.from_product_page'),
                'from_cart' => param('tmonier_quote_request.from_cart'),
                'allow_promotions' => param('tmonier_quote_request.allow_promotions'),
            ],
        ])
        ->tag('twig.extension')
    ;

    // Fixtures (demo data), when SyliusFixturesBundle is installed
    if (class_exists(\Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture::class)) {
        $services
            ->set('tmonier_quote_request.fixture.quote_request', QuoteRequestFixture::class)
            ->args([
                service('tmonier_quote_request.factory'),
                service('tmonier_quote_request.manager'),
                service('tmonier_quote_request.manager.quote_request'),
                service('sylius.repository.channel'),
                service('sylius.repository.customer'),
                service('sylius.repository.product_variant'),
                service('clock'),
            ])
            ->tag('sylius_fixtures.fixture')
        ;
    }
};
