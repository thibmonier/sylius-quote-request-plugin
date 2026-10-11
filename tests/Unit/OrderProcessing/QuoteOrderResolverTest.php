<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\OrderProcessing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolver;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class QuoteOrderResolverTest extends TestCase
{
    public function testARegularCartIsLookedUpOncePerRequest(): void
    {
        $repository = $this->createMock(QuoteRequestRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByOrder')->willReturn(null);
        $cart = $this->cart(1, immutable: false);

        $resolver = new QuoteOrderResolver($repository);

        self::assertNull($resolver->resolveLockingQuote($cart));
        self::assertNull($resolver->resolveLockingQuote($cart));
    }

    public function testAnEmptiedQuoteCartStillFindsItsQuote(): void
    {
        $cart = $this->cart(4, immutable: false);
        foreach ($cart->getItems()->toArray() as $item) {
            $cart->removeItem($item);
        }
        $quote = new QuoteRequest();
        $quote->setStatus(QuoteRequestStatuses::ACCEPTED);
        $quote->setOrder($cart);
        $repository = $this->createMock(QuoteRequestRepositoryInterface::class);
        $repository->method('findOneByOrder')->willReturn($quote);

        self::assertSame($quote, (new QuoteOrderResolver($repository))->resolveLockingQuote($cart));
    }

    public function testTheQuoteOfACartIsLookedUpOnce(): void
    {
        $cart = $this->cart(2, immutable: true);
        $quote = new QuoteRequest();
        $quote->setStatus(QuoteRequestStatuses::ACCEPTED);
        $quote->setOrder($cart);
        $repository = $this->createMock(QuoteRequestRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByOrder')->with($cart)->willReturn($quote);

        $resolver = new QuoteOrderResolver($repository);

        $first = $resolver->resolveLockingQuote($cart);
        $second = $resolver->resolveLockingQuote($cart);
        self::assertSame($quote, $first);
        self::assertSame($first, $second);

        $quote->setStatus(QuoteRequestStatuses::CANCELLED);
        self::assertNull($resolver->resolveLockingQuote($cart), 'Only accepted quotes lock a cart.');
    }

    public function testRememberedAndForgottenCarts(): void
    {
        $repository = $this->createMock(QuoteRequestRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByOrder')->willReturn(null);
        $cart = $this->cart(3, immutable: false);
        $quote = new QuoteRequest();
        $quote->setStatus(QuoteRequestStatuses::ACCEPTED);
        $quote->setOrder($cart);
        $resolver = new QuoteOrderResolver($repository);

        $resolver->remember($cart, $quote);
        self::assertSame($quote, $resolver->resolveLockingQuote($cart), 'Remembered: no query.');

        $resolver->forget($cart);
        self::assertNull($resolver->resolveLockingQuote($cart), 'Forgotten: looked up again.');
    }

    private function cart(int $id, bool $immutable): Order
    {
        $cart = new Order();
        (new \ReflectionProperty(Order::class, 'id'))->setValue($cart, $id);
        $item = new OrderItem();
        $item->setImmutable($immutable);
        $cart->addItem($item);

        return $cart;
    }
}
