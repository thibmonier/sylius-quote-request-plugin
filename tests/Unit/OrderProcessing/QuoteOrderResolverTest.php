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
    public function testARegularCartNeverQueriesTheDatabase(): void
    {
        $repository = $this->createMock(QuoteRequestRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneByOrder');
        $cart = $this->cart(1, immutable: false);

        $resolver = new QuoteOrderResolver($repository);

        self::assertNull($resolver->resolveLockingQuote($cart));
        self::assertNull($resolver->resolveLockingQuote($cart));
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
        $repository->expects(self::never())->method('findOneByOrder');
        $cart = $this->cart(3, immutable: false);
        $quote = new QuoteRequest();
        $quote->setStatus(QuoteRequestStatuses::ACCEPTED);
        $quote->setOrder($cart);
        $resolver = new QuoteOrderResolver($repository);

        $resolver->remember($cart, $quote);
        self::assertSame($quote, $resolver->resolveLockingQuote($cart), 'A new quote cart has no immutable item yet.');

        $resolver->forget($cart);
        self::assertNull($resolver->resolveLockingQuote($cart));
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
