<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\ProductVariant;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartInspector;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLine;

final class QuoteCartInspectorTest extends TestCase
{
    public function testAnOrderHoldsTheQuoteLinesOnlyWithTheirLockedPrices(): void
    {
        $quoted = new ProductVariant();
        $other = new ProductVariant();
        $quote = new QuoteRequest();
        $line = new QuoteRequestLine();
        $line->setVariant($quoted);
        $line->setCatalogUnitPrice(2000);
        $line->setUnitPrice(1500);
        $quote->addLine($line);

        self::assertFalse(QuoteCartInspector::holdsQuoteLines($this->order($other, 1500, true), $quote), 'Another product.');
        self::assertFalse(QuoteCartInspector::holdsQuoteLines($this->order($quoted, 2000, false), $quote), 'The quoted product at the catalog price.');
        self::assertFalse(QuoteCartInspector::holdsQuoteLines(new Order(), $quote), 'Empty order.');
        self::assertTrue(QuoteCartInspector::holdsQuoteLines($this->order($quoted, 1500, true), $quote));
    }

    private function order(ProductVariant $variant, int $unitPrice, bool $immutable): Order
    {
        $order = new Order();
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setUnitPrice($unitPrice);
        $item->setImmutable($immutable);
        $order->addItem($item);

        return $order;
    }
}
