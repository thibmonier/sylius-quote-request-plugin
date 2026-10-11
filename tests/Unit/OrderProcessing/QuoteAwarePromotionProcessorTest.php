<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\OrderProcessing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Promotion;
use Sylius\Component\Core\Model\PromotionCoupon;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteAwarePromotionProcessor;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;

final class QuoteAwarePromotionProcessorTest extends TestCase
{
    public function testARegularCartGetsThePromotions(): void
    {
        $inner = $this->inner();
        (new QuoteAwarePromotionProcessor($inner, $this->resolver(null), false))->process(new Order());

        self::assertSame(1, $inner->calls);
    }

    public function testAQuoteCartGetsNoPromotionAndLosesItsCoupon(): void
    {
        $inner = $this->inner();
        $order = new Order();
        $order->addPromotion(new Promotion());
        $order->setPromotionCoupon(new PromotionCoupon());

        (new QuoteAwarePromotionProcessor($inner, $this->resolver(new QuoteRequest()), false))->process($order);

        self::assertSame(0, $inner->calls);
        self::assertCount(0, $order->getPromotions());
        self::assertNull($order->getPromotionCoupon());
    }

    public function testPromotionsCanBeAllowedOnQuoteCarts(): void
    {
        $inner = $this->inner();
        (new QuoteAwarePromotionProcessor($inner, $this->resolver(new QuoteRequest()), true))->process(new Order());

        self::assertSame(1, $inner->calls);
    }

    private function inner(): OrderProcessorInterface
    {
        return new class() implements OrderProcessorInterface {
            public int $calls = 0;

            public function process(OrderInterface $order): void
            {
                ++$this->calls;
            }
        };
    }

    private function resolver(?QuoteRequestInterface $quote): QuoteOrderResolverInterface
    {
        return new class($quote) implements QuoteOrderResolverInterface {
            public function __construct(private readonly ?QuoteRequestInterface $quote)
            {
            }

            public function resolveLockingQuote(OrderInterface $order): ?QuoteRequestInterface
            {
                return $this->quote;
            }
        };
    }
}
