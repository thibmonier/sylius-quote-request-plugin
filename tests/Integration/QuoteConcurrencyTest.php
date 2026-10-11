<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\TestApplication\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\TransitionBlocker;
use Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue;
use Tests\Tmonier\SyliusQuoteRequestPlugin\TestCatalog;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\EventListener\QuoteCartCompletionGuardListener;
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
        $connection->executeStatement("DELETE FROM sylius_product WHERE code LIKE 'tmonier_product_%'");
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

    public function testALateWriteOfTheCustomerCannotKeepTheQuotePrices(): void
    {
        $quoteA = $this->quote(QuoteRequestStatuses::ACCEPTED);
        $cartId = $quoteA->getOrder()?->getId();
        self::assertNotNull($cartId);

        // Customer request in flight: quantity changed in the quote cart, processed, not flushed yet.
        $emB = $this->entityManager($this->containerB());
        $cartB = $emB->find(OrderInterface::class, $cartId);
        self::assertInstanceOf(OrderInterface::class, $cartB);
        /** @var OrderItemQuantityModifierInterface $modifier */
        $modifier = $this->containerB()->get('sylius.modifier.order_item_quantity');
        $item = $cartB->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $item);
        $modifier->modify($item, 5);
        /** @var OrderProcessorInterface $processorB */
        $processorB = $this->containerB()->get('sylius.order_processing.order_processor');
        $processorB->process($cartB);

        // The merchant places the order meanwhile, then the customer's write lands.
        $this->converter()->convert($quoteA, $this->address(), null, null);
        $emB->flush();

        // The race leaves quote-price items in the detached cart...
        $emA = $this->entityManager($this->containerA());
        $emA->clear();
        $cartA = $emA->find(OrderInterface::class, $cartId);
        self::assertInstanceOf(OrderInterface::class, $cartA);
        self::assertNotSame([], QuoteCartInspector::lockedItems($cartA));

        // ...that can never be checked out: the completion guard refuses it (Symfony Workflow)...
        $cartA->setCheckoutState(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        /** @var Registry $workflows */
        $workflows = $this->containerA()->get('workflow.registry');
        $blockers = $workflows->get($cartA, OrderCheckoutTransitions::GRAPH)->buildTransitionBlockerList($cartA, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        self::assertContains(QuoteCartCompletionGuardListener::CODE, array_map(static fn (TransitionBlocker $blocker): string => $blocker->getCode(), iterator_to_array($blockers)));
        // ...and so does the flush (any state machine adapter).
        $cartA->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);

        try {
            $emA->flush();
            self::fail('Completing a cart holding unbacked quote prices must fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('holds quote prices without its accepted quote', $exception->getMessage());
        }

        // The next processing of the cart gives the catalog prices back.
        $emB->clear();
        $cartB = $emB->find(OrderInterface::class, $cartId);
        self::assertInstanceOf(OrderInterface::class, $cartB);
        $processorB->process($cartB);
        $emB->flush();
        self::assertSame([], QuoteCartInspector::lockedItems($cartB));
        foreach ($cartB->getItems() as $cartItem) {
            self::assertSame(2500, $cartItem->getUnitPrice());
        }
        self::assertSame(1, DbValue::int($emB->getConnection()->fetchOne("SELECT COUNT(*) FROM sylius_order WHERE id > ? AND checkout_state = 'completed'", [$this->lastOrderId])), 'One order: the merchant one.');
    }

    public function testConcurrentMessagesAndChangeRequestsNeverFail(): void
    {
        $this->quote(QuoteRequestStatuses::PROPOSED);
        $quoteB = $this->entityManager($this->containerB())->find(QuoteRequest::class, $this->quoteId);
        self::assertInstanceOf(QuoteRequestInterface::class, $quoteB);
        $quoteA = $this->entityManager($this->containerA())->find(QuoteRequest::class, $this->quoteId);
        self::assertInstanceOf(QuoteRequestInterface::class, $quoteA);
        /** @var QuoteRequestFactoryInterface $factoryA */
        $factoryA = $this->containerA()->get('tmonier_quote_request.factory');
        /** @var QuoteRequestFactoryInterface $factoryB */
        $factoryB = $this->containerB()->get('tmonier_quote_request.factory');

        // The merchant writes and saves while the customer has the quote open.
        $this->manager($this->containerA())->postMessage($quoteA, $factoryA->createMessage('admin', 'Sales', 'Any question?'));
        $quoteA->setInternalNote('Edited meanwhile');
        $this->manager($this->containerA())->save($quoteA);

        $this->manager($this->containerB())->postMessage($quoteB, $factoryB->createMessage('customer', 'Buyer', 'Yes, one.'));
        $this->manager($this->containerB())->apply($quoteB, QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES, $factoryB->createMessage('customer', 'Buyer', 'Lower price please.'));

        $connection = $this->entityManager($this->containerA())->getConnection();
        self::assertSame(QuoteRequestStatuses::IN_REVIEW, $connection->fetchOne('SELECT status FROM tmonier_quote_request WHERE id = ?', [$this->quoteId]));
        self::assertSame(3, DbValue::int($connection->fetchOne('SELECT COUNT(*) FROM tmonier_quote_request_message WHERE quote_request_id = ?', [$this->quoteId])));
    }

    public function testACheckoutCompletionIsNotBlockedByAConcurrentQuoteChange(): void
    {
        $quoteA = $this->quote(QuoteRequestStatuses::ACCEPTED);
        $cartId = $quoteA->getOrder()?->getId();
        $emB = $this->entityManager($this->containerB());
        $cartB = $emB->find(OrderInterface::class, $cartId);
        self::assertInstanceOf(OrderInterface::class, $cartB);
        $this->completeCheckout($cartB, $this->containerB());

        // The merchant writes to the customer before the checkout is flushed.
        /** @var QuoteRequestFactoryInterface $factoryA */
        $factoryA = $this->containerA()->get('tmonier_quote_request.factory');
        $this->manager($this->containerA())->postMessage($quoteA, $factoryA->createMessage('admin', 'Sales', 'Thank you!'));

        $emB->flush();

        $connection = $emB->getConnection();
        self::assertSame(QuoteRequestStatuses::ORDERED, $connection->fetchOne('SELECT status FROM tmonier_quote_request WHERE id = ?', [$this->quoteId]));
        self::assertSame('completed', $connection->fetchOne('SELECT checkout_state FROM sylius_order WHERE id = ?', [$cartId]));
    }

    private function completeCheckout(OrderInterface $cart, ContainerInterface $container): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $container->get('sylius_abstraction.state_machine');
        /** @var OrderProcessorInterface $processor */
        $processor = $container->get('sylius.order_processing.order_processor');
        $address = $this->address();
        $cart->setShippingAddress($address);
        $cart->setBillingAddress(clone $address);
        foreach ([OrderCheckoutTransitions::TRANSITION_ADDRESS, OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT, OrderCheckoutTransitions::TRANSITION_SKIP_PAYMENT, OrderCheckoutTransitions::TRANSITION_COMPLETE] as $transition) {
            if ($stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition)) {
                $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);
                $processor->process($cart);
            }
        }
        self::assertSame('completed', $cart->getCheckoutState());
    }

    private function acceptedQuoteWithoutCart(): QuoteRequestInterface
    {
        $quote = $this->quote(QuoteRequestStatuses::ACCEPTED);
        /** @var QuoteCartManagerInterface $cartManager */
        $cartManager = $this->containerA()->get('tmonier_quote_request.cart_manager');
        $cartManager->release($quote, true); // "leave the quote prices": accepted, no cart
        $this->entityManager($this->containerA())->getConnection()->executeStatement('DELETE FROM sylius_order WHERE id > ?', [$this->lastOrderId]);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertNull($quote->getOrder());

        return $quote;
    }

    /** A committed quote (kernel A) on a product created for the test, with nothing to ship. */
    private function quote(string $status): QuoteRequestInterface
    {
        $container = $this->containerA();
        $channel = $this->entityManager($container)->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = (new TestCatalog($container))->variant($channel, 2500, false);

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
        if (QuoteRequestStatuses::NEW !== $status) {
            $manager->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);
        }
        if (QuoteRequestStatuses::ACCEPTED === $status) {
            $manager->accept($quote, $this->customer($container), false);
        }

        return $quote;
    }

    private function containerB(): ContainerInterface
    {
        $container = $this->other->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
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
