<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMemberInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Catalog data created by the tests themselves (inside their rolled-back transaction), so that
 * no test depends on the random Sylius fixtures: zones, shipping and payment methods, products.
 */
final class TestCatalog
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function country(string $code): CountryInterface
    {
        $country = $this->entityManager()->getRepository(CountryInterface::class)->findOneBy(['code' => $code]);
        if (!$country instanceof CountryInterface) {
            /** @var FactoryInterface<CountryInterface> $factory */
            $factory = $this->container->get('sylius.factory.country');
            $country = $factory->createNew();
            $country->setCode($code);
            $this->entityManager()->persist($country);
        }
        $country->setEnabled(true);

        return $country;
    }

    /** @param list<string> $countryCodes */
    public function zone(array $countryCodes): ZoneInterface
    {
        /** @var FactoryInterface<ZoneInterface> $zoneFactory */
        $zoneFactory = $this->container->get('sylius.factory.zone');
        /** @var FactoryInterface<ZoneMemberInterface> $memberFactory */
        $memberFactory = $this->container->get('sylius.factory.zone_member');

        $zone = $zoneFactory->createNew();
        $zone->setCode('TMONIER_' . strtoupper(bin2hex(random_bytes(4))));
        $zone->setName($zone->getCode());
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        foreach ($countryCodes as $countryCode) {
            $this->country($countryCode);
            $member = $memberFactory->createNew();
            $member->setCode($countryCode);
            $zone->addMember($member);
        }
        $this->entityManager()->persist($zone);

        return $zone;
    }

    /** A flat-rate method of the channel shipping to the given countries. */
    public function shippingMethod(ChannelInterface $channel, array $countryCodes, int $amount = 500): ShippingMethodInterface
    {
        /** @var FactoryInterface<ShippingMethodInterface> $factory */
        $factory = $this->container->get('sylius.factory.shipping_method');
        $method = $factory->createNew();
        $code = 'tmonier_ship_' . bin2hex(random_bytes(4));
        $method->setCode($code);
        $method->setCurrentLocale((string) $channel->getDefaultLocale()?->getCode());
        $method->setFallbackLocale((string) $channel->getDefaultLocale()?->getCode());
        $method->setName('Test ' . $code);
        $method->setZone($this->zone($countryCodes));
        $method->setCalculator('flat_rate');
        $method->setConfiguration([(string) $channel->getCode() => ['amount' => $amount]]);
        $method->addChannel($channel);
        $method->setEnabled(true);
        $this->entityManager()->persist($method);

        return $method;
    }

    public function paymentMethod(ChannelInterface $channel): PaymentMethodInterface
    {
        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $factory */
        $factory = $this->container->get('sylius.factory.payment_method');
        $method = $factory->createWithGateway('offline');
        $code = 'tmonier_pay_' . bin2hex(random_bytes(4));
        $method->setCode($code);
        $method->getGatewayConfig()?->setGatewayName($code);
        $method->setCurrentLocale((string) $channel->getDefaultLocale()?->getCode());
        $method->setFallbackLocale((string) $channel->getDefaultLocale()?->getCode());
        $method->setName('Test ' . $code);
        $method->addChannel($channel);
        $method->setEnabled(true);
        $this->entityManager()->persist($method);

        return $method;
    }

    /** Disables every other shipping method of the channel (e.g. to make one method the only eligible one). */
    public function disableShippingMethodsBut(ChannelInterface $channel, ShippingMethodInterface ...$kept): void
    {
        /** @var list<ShippingMethodInterface> $methods */
        $methods = $this->entityManager()->getRepository(ShippingMethodInterface::class)->findAll();
        foreach ($methods as $method) {
            if (!in_array($method, $kept, true) && $method->hasChannel($channel)) {
                $method->setEnabled(false);
            }
        }
    }

    /** An enabled, untracked variant of a new product sold in the channel. */
    public function variant(ChannelInterface $channel, int $price = 2500, bool $shippingRequired = true): ProductVariantInterface
    {
        /** @var ProductFactoryInterface<ProductInterface> $productFactory */
        $productFactory = $this->container->get('sylius.factory.product');
        /** @var FactoryInterface<ChannelPricingInterface> $pricingFactory */
        $pricingFactory = $this->container->get('sylius.factory.channel_pricing');
        $locale = (string) $channel->getDefaultLocale()?->getCode();
        $code = 'tmonier_product_' . bin2hex(random_bytes(4));

        $product = $productFactory->createWithVariant();
        Assert::isInstanceOf($product, ProductInterface::class);
        $product->setCode($code);
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->setName('Test product ' . $code);
        $product->setSlug($code);
        $product->setEnabled(true);
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        Assert::isInstanceOf($variant, ProductVariantInterface::class);
        $variant->setCode($code . '_variant');
        $variant->setCurrentLocale($locale);
        $variant->setFallbackLocale($locale);
        $variant->setName('Test variant ' . $code);
        $variant->setEnabled(true);
        $variant->setTracked(false);
        $variant->setShippingRequired($shippingRequired);
        $pricing = $pricingFactory->createNew();
        $pricing->setChannelCode($channel->getCode());
        $pricing->setPrice($price);
        $pricing->setOriginalPrice($price);
        $variant->addChannelPricing($pricing);

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $variant;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);

        return $entityManager;
    }
}
