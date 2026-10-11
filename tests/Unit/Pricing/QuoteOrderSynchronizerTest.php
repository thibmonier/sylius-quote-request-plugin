<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Distributor\IntegerDistributor;
use Sylius\Component\Core\Distributor\ProportionalIntegerDistributor;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Order\Factory\AdjustmentFactory;
use Sylius\Component\Order\Factory\OrderItemUnitFactory;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifier;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Translation\IdentityTranslator;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLine;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteDiscountCalculator;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteOrderSynchronizer;

final class QuoteOrderSynchronizerTest extends TestCase
{
    private QuoteOrderSynchronizer $synchronizer;

    private OrderItemQuantityModifier $quantityModifier;

    protected function setUp(): void
    {
        $this->quantityModifier = new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class));
        $this->synchronizer = new QuoteOrderSynchronizer(
            new Factory(OrderItem::class),
            $this->quantityModifier,
            new AdjustmentFactory(new Factory(Adjustment::class)),
            new ProportionalIntegerDistributor(),
            new IntegerDistributor(),
            new QuoteDiscountCalculator(),
            new IdentityTranslator(),
        );
    }

    public function testItCreatesImmutableItemsAtTheNegotiatedPrices(): void
    {
        [$first, $second] = [$this->variant('A'), $this->variant('B')];
        $quote = $this->quote([[$first, 3, 2000, 1500], [$second, 2, 1000, null]]);
        $order = new Order();

        $this->synchronizer->synchronize($quote, $order);

        $items = $this->items($order);
        self::assertCount(2, $items);
        self::assertSame([$first, $second], array_map(static fn (OrderItemInterface $item) => $item->getVariant(), $items));
        self::assertSame([1500, 1000], array_map(static fn (OrderItemInterface $item): int => $item->getUnitPrice(), $items));
        self::assertSame([2000, 1000], array_map(static fn (OrderItemInterface $item): ?int => $item->getOriginalUnitPrice(), $items));
        self::assertSame([3, 2], array_map(static fn (OrderItemInterface $item): int => $item->getQuantity(), $items));
        self::assertTrue($items[0]->isImmutable() && $items[1]->isImmutable());
        self::assertSame('Product A', $items[0]->getProductName());
        self::assertSame(3 * 1500 + 2 * 1000, $order->getItemsTotal());
    }

    public function testItSpreadsTheGlobalDiscountOnTheUnits(): void
    {
        $quote = $this->quote([[$this->variant('A'), 3, 2000, 1999], [$this->variant('B'), 7, 1500, 1234]]);
        $quote->setDiscountPercentage(10.0);
        $quote->setNumber('Q000042');
        $order = new Order();

        $this->synchronizer->synchronize($quote, $order);

        $subtotal = 3 * 1999 + 7 * 1234;
        $discount = (int) round($subtotal * 0.1);
        self::assertSame(-$discount, $order->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT));
        self::assertSame($subtotal - $discount, $order->getItemsTotal());
        foreach ($this->items($order) as $item) {
            foreach ($item->getUnits() as $unit) {
                $adjustments = $unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
                self::assertCount(1, $adjustments);
                $adjustment = $adjustments->first();
                self::assertNotFalse($adjustment);
                self::assertSame(QuoteRequestInterface::DISCOUNT_ADJUSTMENT_ORIGIN, $adjustment->getOriginCode());
                self::assertSame('tmonier_quote_request.ui.quote_discount', $adjustment->getLabel());
                self::assertFalse($adjustment->isNeutral());
            }
        }
    }

    public function testItIsIdempotent(): void
    {
        $quote = $this->quote([[$this->variant('A'), 4, 2000, 1500]]);
        $quote->setDiscountAmount(300);
        $order = new Order();

        $this->synchronizer->synchronize($quote, $order);
        $item = $this->items($order)[0];
        $this->synchronizer->synchronize($quote, $order);
        $this->synchronizer->synchronize($quote, $order);

        self::assertSame($item, $this->items($order)[0], 'Matching items are kept.');
        self::assertSame(-300, $order->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT));
        self::assertSame(4 * 1500 - 300, $order->getItemsTotal());
    }

    public function testItRestoresTheQuoteContentWhenTheCartWasChanged(): void
    {
        $variant = $this->variant('A');
        $quote = $this->quote([[$variant, 4, 2000, 1500]]);
        $order = new Order();
        $this->synchronizer->synchronize($quote, $order);

        $item = $this->items($order)[0];
        $this->quantityModifier->modify($item, 40);
        $item->setUnitPrice(1);
        $item->setImmutable(false);
        $extra = new OrderItem();
        $extra->setVariant($this->variant('B'));
        $this->quantityModifier->modify($extra, 1);
        $order->addItem($extra);

        $this->synchronizer->synchronize($quote, $order);

        $items = $this->items($order);
        self::assertCount(1, $items);
        self::assertSame($variant, $items[0]->getVariant());
        self::assertSame(4, $items[0]->getQuantity());
        self::assertSame(1500, $items[0]->getUnitPrice());
        self::assertTrue($items[0]->isImmutable());
    }

    public function testLinesOfDeletedVariantsAreSkipped(): void
    {
        $quote = $this->quote([[$this->variant('A'), 1, 2000, null]]);
        $orphan = new QuoteRequestLine();
        $orphan->setQuantity(5);
        $orphan->setCatalogUnitPrice(999);
        $quote->addLine($orphan);
        $order = new Order();

        $this->synchronizer->synchronize($quote, $order);

        self::assertCount(1, $order->getItems());
    }

    /** @param list<array{0: ProductVariant, 1: int, 2: int, 3: int|null}> $lines */
    private function quote(array $lines): QuoteRequest
    {
        $quote = new QuoteRequest();
        foreach ($lines as [$variant, $quantity, $catalogPrice, $price]) {
            $line = new QuoteRequestLine();
            $line->setVariant($variant);
            $line->setQuantity($quantity);
            $line->setCatalogUnitPrice($catalogPrice);
            $line->setUnitPrice($price);
            $quote->addLine($line);
        }

        return $quote;
    }

    private function variant(string $code): ProductVariant
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Product ' . $code);
        $variant = new ProductVariant();
        $variant->setCode($code);
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $product->addVariant($variant);

        return $variant;
    }

    /** @return list<OrderItemInterface> */
    private function items(Order $order): array
    {
        /** @var list<OrderItemInterface> $items */
        $items = array_values($order->getItems()->toArray());

        return $items;
    }
}
