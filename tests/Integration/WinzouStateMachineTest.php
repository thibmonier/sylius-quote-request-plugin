<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * Shop on `default_adapter: winzou_state_machine` (Sylius graphs on Winzou): the quote graph
 * still runs on the Symfony Workflow and the quote becomes "ordered" through the Doctrine
 * listener, its event being dispatched after the flush.
 */
final class WinzouStateMachineTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['TEST_SYLIUS_STATE_MACHINE_ADAPTER'] = $_ENV['TEST_SYLIUS_STATE_MACHINE_ADAPTER'] = 'winzou_state_machine';
        self::bootKernel();
        $this->entityManager()->getConnection()->beginTransaction();
        // The checkout of the tests ships to the US and pays: methods created here, not taken
        // from the random fixtures.
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog = new \Tests\Tmonier\SyliusQuoteRequestPlugin\TestCatalog(self::getContainer());
        $catalog->shippingMethod($channel, ['US']);
        $catalog->paymentMethod($channel);
        $this->entityManager()->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        unset($_SERVER['TEST_SYLIUS_STATE_MACHINE_ADAPTER'], $_ENV['TEST_SYLIUS_STATE_MACHINE_ADAPTER']);
        parent::tearDown();
    }

    public function testTheQuoteIsOrderedWhenTheCheckoutRunsOnWinzou(): void
    {
        self::assertSame('winzou_state_machine', self::getContainer()->getParameter('sylius_abstraction.state_machine.default_adapter'));
        $orderedEvents = [];
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(QuoteRequestEvents::ORDERED, function () use (&$orderedEvents): void {
            $orderedEvents[] = $this->entityManager()->getConnection()->isTransactionActive();
        });

        $quote = $this->proposedQuote();
        $cart = $this->manager()->accept($quote, $this->customer(), false);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        /** @var FactoryInterface<AddressInterface> $addressFactory */
        $addressFactory = self::getContainer()->get('sylius.factory.address');
        $address = $addressFactory->createNew();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setStreet('1 Main Street');
        $address->setCity('New York');
        $address->setPostcode('10001');
        $address->setCountryCode('US');
        $cart->setShippingAddress($address);
        $cart->setBillingAddress(clone $address);
        foreach ([OrderCheckoutTransitions::TRANSITION_ADDRESS, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT, OrderCheckoutTransitions::TRANSITION_COMPLETE] as $transition) {
            if (OrderCheckoutTransitions::TRANSITION_COMPLETE === $transition || $stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition)) {
                $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);
            }
        }

        // No Symfony Workflow event on Winzou: still accepted until the flush.
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $this->statusOf($quote));
        self::assertSame([], $orderedEvents);

        $this->entityManager()->flush();

        self::assertSame(QuoteRequestStatuses::ORDERED, $this->statusOf($quote));
        self::assertCount(1, $orderedEvents, 'One "ordered" event, after the flush.');
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(QuoteRequest::class, $quote->getId());
        self::assertInstanceOf(QuoteRequestInterface::class, $reloaded);
        self::assertSame(QuoteRequestStatuses::ORDERED, $reloaded->getStatus(), 'The status is persisted.');
        self::assertNotNull($reloaded->getOrderedAt());
    }

    private function proposedQuote(): QuoteRequestInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        /** @var list<ProductVariantInterface> $variants */
        $variants = $this->entityManager()->createQueryBuilder()
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
            ->getResult()
        ;
        if ([] === $variants) {
            $variants = [(new \Tests\Tmonier\SyliusQuoteRequestPlugin\TestCatalog(self::getContainer()))->variant($channel)];
        }

        /** @var QuoteRequestFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_quote_request.factory');
        $quote = $factory->createForChannel($channel, (string) $channel->getDefaultLocale()?->getCode(), $this->customer());
        $line = $factory->createLine($quote, $variants[0], 2);
        self::assertNotNull($line);
        $line->setUnitPrice(1500);
        $quote->addLine($line);
        $this->manager()->submit($quote);
        $this->manager()->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);

        return $quote;
    }

    public function testOrderedListenersThatOnlyPersistAreSaved(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(QuoteRequestEvents::ORDERED, static function (\Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent $event): void {
            /** @var QuoteRequestFactoryInterface $factory */
            $factory = self::getContainer()->get('tmonier_quote_request.factory');
            $event->getQuoteRequest()->addMessage($factory->createMessage('admin', 'ERP', 'Order exported'));
        });
        $quote = $this->proposedQuote();
        $cart = $this->manager()->accept($quote, $this->customer(), false);

        $this->completeCheckout($cart);
        $this->entityManager()->flush();

        $count = $this->entityManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM tmonier_quote_request_message WHERE quote_request_id = ? AND body = 'Order exported'", [$quote->getId()]);
        self::assertSame(1, \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($count), 'Same behaviour as the Symfony Workflow path.');
    }

    public function testAFailedFlushDispatchesNoOrderedEvent(): void
    {
        $events = 0;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(QuoteRequestEvents::ORDERED, static function () use (&$events): void {
            ++$events;
        });
        $quote = $this->proposedQuote();
        $cart = $this->manager()->accept($quote, $this->customer(), false);
        $this->completeCheckout($cart);
        /** @var \Tmonier\SyliusQuoteRequestPlugin\EventListener\OrderCheckoutCompletedDoctrineListener $listener */
        $listener = self::getContainer()->get('tmonier_quote_request.listener.order_checkout_completed_doctrine');
        $listener->onFlush(new \Doctrine\ORM\Event\OnFlushEventArgs($this->entityManager()));
        // The flush does not happen (failure): its pending "ordered" event must never come out.
        $this->entityManager()->clear();

        $this->entityManager()->flush(); // a later, unrelated flush

        self::assertSame(0, $events);
    }

    private function completeCheckout(\Sylius\Component\Core\Model\OrderInterface $cart): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        /** @var FactoryInterface<AddressInterface> $addressFactory */
        $addressFactory = self::getContainer()->get('sylius.factory.address');
        $address = $addressFactory->createNew();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setStreet('1 Main Street');
        $address->setCity('New York');
        $address->setPostcode('10001');
        $address->setCountryCode('US');
        $cart->setShippingAddress($address);
        $cart->setBillingAddress(clone $address);
        foreach ([OrderCheckoutTransitions::TRANSITION_ADDRESS, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT, OrderCheckoutTransitions::TRANSITION_COMPLETE] as $transition) {
            if (OrderCheckoutTransitions::TRANSITION_COMPLETE === $transition || $stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition)) {
                $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);
            }
        }
    }

    private function statusOf(QuoteRequestInterface $quote): string
    {
        return $quote->getStatus();
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->entityManager()->getRepository(CustomerInterface::class)->findOneBy(['email' => 'shop@example.com']);
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function manager(): QuoteRequestManagerInterface
    {
        /** @var QuoteRequestManagerInterface $manager */
        $manager = self::getContainer()->get('tmonier_quote_request.manager');

        return $manager;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
