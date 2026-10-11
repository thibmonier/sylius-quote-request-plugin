<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\TestApplication\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * Two kernels, two database connections: what two simultaneous requests do. Data is committed
 * (no wrapping transaction) and removed afterwards.
 */
final class QuoteConcurrencyTest extends KernelTestCase
{
    private Kernel $other;

    private int $lastOrderId;

    private ?int $quoteId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->other = new Kernel('test', true);
        $this->other->boot();
        $this->lastOrderId = \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($this->entityManager($this->containerA())->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order'));
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager($this->containerA())->getConnection();
        if (null !== $this->quoteId) {
            $connection->executeStatement('DELETE FROM tmonier_quote_request WHERE id = ?', [$this->quoteId]);
        }
        $connection->executeStatement('DELETE FROM sylius_order WHERE id > ?', [$this->lastOrderId]);
        $this->other->shutdown();
        parent::tearDown();
    }

    public function testACheckoutStartedMeanwhileStopsAConversionBasedOnAnOutdatedQuote(): void
    {
        $quoteA = $this->acceptedQuoteWithoutCart();
        $this->checkoutInOtherRequest();

        try {
            $this->converter()->convert($quoteA, $this->address(), null, null);
            self::fail('The outdated conversion must be refused.');
        } catch (QuoteTransitionException $exception) {
            self::assertTrue($exception->isConcurrentChange());
        }

        $connection = $this->entityManager($this->containerA())->getConnection();
        $orders = $connection->fetchAllAssociative('SELECT id, state, checkout_state FROM sylius_order WHERE id > ?', [$this->lastOrderId]);
        self::assertCount(1, $orders, 'Only the customer cart exists.');
        self::assertSame('cart', $orders[0]['state']);
        self::assertSame(\Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($orders[0]['id']), \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($connection->fetchOne('SELECT order_id FROM tmonier_quote_request WHERE id = ?', [$this->quoteId])), 'The customer cart is still the quote cart.');
    }

    public function testTwoCheckoutStartsCreateASingleQuoteCart(): void
    {
        $quoteA = $this->acceptedQuoteWithoutCart();
        $this->checkoutInOtherRequest();

        try {
            $this->manager($this->containerA())->startCheckout($quoteA, $this->customer($this->containerA()));
            self::fail('The second checkout start must be refused.');
        } catch (QuoteTransitionException $exception) {
            self::assertTrue($exception->isConcurrentChange());
        }

        $connection = $this->entityManager($this->containerA())->getConnection();
        self::assertSame(1, \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($connection->fetchOne('SELECT COUNT(*) FROM sylius_order WHERE id > ?', [$this->lastOrderId])), 'No orphan cart with locked prices.');
    }

    private function acceptedQuoteWithoutCart(): QuoteRequestInterface
    {
        $container = $this->containerA();
        $channel = $this->entityManager($container)->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $this->entityManager($container)->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.channels', 'channel')
            ->innerJoin('v.channelPricings', 'pricing')
            ->andWhere('v.enabled = true')
            ->andWhere('p.enabled = true')
            ->andWhere('v.tracked = false')
            ->andWhere('channel = :channel')
            ->andWhere('pricing.channelCode = :channelCode')
            ->setParameter('channel', $channel)
            ->setParameter('channelCode', $channel->getCode())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        /** @var QuoteRequestFactoryInterface $factory */
        $factory = $container->get('tmonier_quote_request.factory');
        $quote = $factory->createForChannel($channel, 'en_US', $this->customer($container));
        $line = $factory->createLine($quote, $variant, 2);
        self::assertNotNull($line);
        $line->setUnitPrice(111);
        $quote->addLine($line);
        $manager = $this->manager($container);
        $manager->submit($quote);
        $this->quoteId = $quote->getId();
        $manager->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);
        $manager->accept($quote, $this->customer($container), false);
        /** @var QuoteCartManagerInterface $cartManager */
        $cartManager = $container->get('tmonier_quote_request.cart_manager');
        $cartManager->release($quote, true); // "leave the quote prices": accepted, no cart
        $this->entityManager($container)->getConnection()->executeStatement('DELETE FROM sylius_order WHERE id > ?', [$this->lastOrderId]);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertNull($quote->getOrder());

        return $quote;
    }

    /** The customer's "back to checkout" request, committed in the other kernel. */
    private function checkoutInOtherRequest(): void
    {
        $container = $this->other->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $quoteB = $this->entityManager($container)->find(QuoteRequest::class, $this->quoteId);
        self::assertInstanceOf(QuoteRequestInterface::class, $quoteB);
        $this->manager($container)->startCheckout($quoteB, $this->customer($container));
    }

    private function address(): Address
    {
        $address = new Address();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setStreet('1 Main Street');
        $address->setCity('New York');
        $address->setPostcode('10001');
        $address->setCountryCode('US');

        return $address;
    }

    private function containerA(): ContainerInterface
    {
        return self::getContainer();
    }

    private function customer(ContainerInterface $container): CustomerInterface
    {
        $customer = $this->entityManager($container)->getRepository(CustomerInterface::class)->findOneBy(['email' => 'shop@example.com']);
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function converter(): QuoteOrderConverterInterface
    {
        /** @var QuoteOrderConverterInterface $converter */
        $converter = $this->containerA()->get('tmonier_quote_request.order_converter');

        return $converter;
    }

    private function manager(ContainerInterface $container): QuoteRequestManagerInterface
    {
        /** @var QuoteRequestManagerInterface $manager */
        $manager = $container->get('tmonier_quote_request.manager');

        return $manager;
    }

    private function entityManager(ContainerInterface $container): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
