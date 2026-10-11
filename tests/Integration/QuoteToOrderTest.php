<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteTotalsCalculatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * An accepted quote becomes an order through the regular checkout (full order processing), with
 * exactly the negotiated prices and global discount, whatever the cart promotions.
 */
final class QuoteToOrderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testTheOrderKeepsTheNegotiatedPricesAfterTheFullCheckout(): void
    {
        [$first, $second] = $this->variants();
        $this->createApplicablePromotion();
        $quote = $this->proposedQuote([[$first, 3, 1999], [$second, 7, 1234]], 10.0);
        $expectedTotals = $this->totalsCalculator()->calculate($quote);

        $cart = $this->manager()->accept($quote, $this->customer(), false);
        $this->checkout($cart);

        self::assertSame(OrderInterface::STATE_NEW, $cart->getState());
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        self::assertNotNull($quote->getOrderedAt());
        self::assertSame([1999, 1234], $this->unitPrices($cart));
        self::assertSame([3, 7], array_map(static fn (OrderItemInterface $item): int => $item->getQuantity(), $this->items($cart)));
        self::assertSame(3 * 1999 + 7 * 1234, $cart->getItemsSubtotal());

        // Global discount (10 %) spread on the units, no catalog promotion on top of it.
        $discount = $cart->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
        self::assertSame(-(int) round((3 * 1999 + 7 * 1234) * 0.1), $discount);
        self::assertSame(0, $cart->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_ITEM_PROMOTION_ADJUSTMENT));
        self::assertSame(0, $cart->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT));
        self::assertCount(0, $cart->getPromotions());
        foreach ($cart->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                foreach ($unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
                    self::assertSame(QuoteRequestInterface::DISCOUNT_ADJUSTMENT_ORIGIN, $adjustment->getOriginCode());
                }
            }
        }

        // The totals shown on the quote are the ones of the order, shipping apart.
        self::assertSame($expectedTotals->discount, -$discount);
        self::assertSame($expectedTotals->taxTotal, $cart->getTaxTotal() - $this->shippingTaxes($cart));
        self::assertSame($expectedTotals->total, $cart->getItemsTotal());
        self::assertSame($cart->getItemsTotal() + $cart->getShippingTotal(), $cart->getTotal());
    }

    public function testTheSamePromotionAppliesToARegularCart(): void
    {
        [$first] = $this->variants();
        $this->createApplicablePromotion();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = self::getContainer()->get('sylius.factory.order');
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');

        $cart = $orderFactory->createNew();
        $cart->setChannel($this->channel());
        $cart->setLocaleCode((string) $this->channel()->getDefaultLocale()?->getCode());
        $cart->setCurrencyCode((string) $this->channel()->getBaseCurrency()?->getCode());
        $cart->setCustomer($this->customer());
        $item = $this->orderItemFactory()->createNew();
        $item->setVariant($first);
        $quantityModifier->modify($item, 2);
        $cart->addItem($item);
        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();
        $this->orderProcessor()->process($cart);

        self::assertLessThan(0, $cart->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT));
        self::assertNotCount(0, $cart->getPromotions());
    }

    public function testTheCartContentIsRestoredWhenTheCustomerChangesIt(): void
    {
        [$first, $second] = $this->variants();
        $quote = $this->proposedQuote([[$first, 3, 1999]]);
        $cart = $this->manager()->accept($quote, $this->customer(), false);

        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        $item = $this->items($cart)[0];
        $quantityModifier->modify($item, 50);
        $item->setUnitPrice(1);
        $extra = $this->orderItemFactory()->createNew();
        $extra->setVariant($second);
        $quantityModifier->modify($extra, 2);
        $cart->addItem($extra);
        $cart->setPromotionCoupon(null);

        $this->orderProcessor()->process($cart);

        self::assertCount(1, $cart->getItems());
        self::assertSame([1999], $this->unitPrices($cart));
        self::assertSame(3, $this->items($cart)[0]->getQuantity());
        self::assertTrue($this->items($cart)[0]->isImmutable());
    }

    public function testReleasingTheCartRestoresTheCatalogPrices(): void
    {
        [$first] = $this->variants();
        $quote = $this->proposedQuote([[$first, 2, 1]]);
        $catalogPrice = $quote->getLines()->first() ? $quote->getLines()->first()->getCatalogUnitPrice() : 0;
        $cart = $this->manager()->accept($quote, $this->customer(), false);
        self::assertSame([1], $this->unitPrices($cart));

        /** @var QuoteCartManagerInterface $cartManager */
        $cartManager = self::getContainer()->get('tmonier_quote_request.cart_manager');
        $cartManager->release($quote);

        self::assertNull($quote->getOrder());
        self::assertSame([$catalogPrice], $this->unitPrices($cart));
        self::assertFalse($this->items($cart)[0]->isImmutable());
    }

    public function testCancellingAnAcceptedQuoteReleasesItsCart(): void
    {
        [$first] = $this->variants();
        $quote = $this->proposedQuote([[$first, 2, 1]]);
        $cart = $this->manager()->accept($quote, $this->customer(), false);

        $this->manager()->apply($quote, QuoteRequestTransitions::TRANSITION_CANCEL);

        self::assertSame(QuoteRequestStatuses::CANCELLED, $quote->getStatus());
        self::assertFalse($this->items($cart)[0]->isImmutable());
        self::assertNotSame([1], $this->unitPrices($cart));
    }

    /**
     * @param list<array{0: ProductVariantInterface, 1: int, 2: int}> $lines
     */
    private function proposedQuote(array $lines, ?float $discountPercentage = null): QuoteRequestInterface
    {
        /** @var QuoteRequestFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_quote_request.factory');
        $customer = $this->customer();
        $quote = $factory->createForChannel($this->channel(), (string) $this->channel()->getDefaultLocale()?->getCode(), $customer);
        foreach ($lines as [$variant, $quantity, $price]) {
            $line = $factory->createLine($quote, $variant, $quantity);
            self::assertNotNull($line);
            $line->setUnitPrice($price);
            $quote->addLine($line);
        }
        $quote->setDiscountPercentage($discountPercentage);
        $this->manager()->submit($quote);
        $this->manager()->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);

        return $quote;
    }

    private function checkout(OrderInterface $cart): void
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
            self::assertTrue($stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition), sprintf('Checkout transition "%s".', $transition));
            $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);
            $this->orderProcessor()->process($cart);
        }
        $this->entityManager()->flush();
    }

    private function createApplicablePromotion(): void
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = self::getContainer()->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = self::getContainer()->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode('tmonier_quote_test_half_price');
        $promotion->setName('Half price for everybody');
        $promotion->setPriority(1000);
        $promotion->addChannel($this->channel());
        $action = $actionFactory->createNew();
        $action->setType('order_percentage_discount');
        $action->setConfiguration(['percentage' => 0.5]);
        $promotion->addAction($action);
        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();
    }

    private function shippingTaxes(OrderInterface $order): int
    {
        $taxes = 0;
        foreach ($order->getShipments() as $shipment) {
            $taxes += $shipment->getAdjustmentsTotal(AdjustmentInterface::TAX_ADJUSTMENT);
        }

        return $taxes;
    }

    /** @return list<int> */
    private function unitPrices(OrderInterface $order): array
    {
        return array_map(static fn (OrderItemInterface $item): int => $item->getUnitPrice(), $this->items($order));
    }

    /** @return list<OrderItemInterface> */
    private function items(OrderInterface $order): array
    {
        /** @var list<OrderItemInterface> $items */
        $items = array_values($order->getItems()->toArray());

        return $items;
    }

    /** @return list<ProductVariantInterface> */
    private function variants(): array
    {
        $channel = $this->channel();
        /** @var list<ProductVariantInterface> $candidates */
        $candidates = $this->entityManager()->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.channels', 'channel')
            ->innerJoin('v.channelPricings', 'pricing')
            ->andWhere('v.enabled = true')
            ->andWhere('p.enabled = true')
            ->andWhere('v.tracked = false')
            ->andWhere('v.shippingRequired = true')
            ->andWhere('channel = :channel')
            ->andWhere('pricing.channelCode = :channelCode')
            ->setParameter('channel', $channel)
            ->setParameter('channelCode', $channel->getCode())
            ->orderBy('v.id', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult()
        ;
        $variants = [];
        foreach ($candidates as $variant) {
            $variants[(int) $variant->getProduct()?->getId()] ??= $variant;
        }
        $variants = array_slice(array_values($variants), 0, 2);
        if (count($variants) < 2) {
            self::markTestSkipped('The fixtures contain not enough sellable untracked variants.');
        }

        return $variants;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->entityManager()->getRepository(CustomerInterface::class)->findOneBy(['email' => 'shop@example.com']);
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function manager(): QuoteRequestManagerInterface
    {
        /** @var QuoteRequestManagerInterface $manager */
        $manager = self::getContainer()->get('tmonier_quote_request.manager');

        return $manager;
    }

    private function totalsCalculator(): QuoteTotalsCalculatorInterface
    {
        /** @var QuoteTotalsCalculatorInterface $calculator */
        $calculator = self::getContainer()->get('tmonier_quote_request.totals_calculator');

        return $calculator;
    }

    private function orderProcessor(): OrderProcessorInterface
    {
        /** @var OrderProcessorInterface $processor */
        $processor = self::getContainer()->get('sylius.order_processing.order_processor');

        return $processor;
    }

    /** @return FactoryInterface<OrderItemInterface> */
    private function orderItemFactory(): FactoryInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');

        return $factory;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
