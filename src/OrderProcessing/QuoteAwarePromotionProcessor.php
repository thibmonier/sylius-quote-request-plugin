<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * Decorates the Sylius promotion processor: unless `allow_promotions` is enabled, the cart of an
 * accepted quote gets no catalog promotion on top of the negotiated prices (the promotion
 * adjustments are already cleared by the adjustments clearer, the applied promotions and the
 * coupon are removed here so that their usage is not counted).
 */
final class QuoteAwarePromotionProcessor implements OrderProcessorInterface
{
    public function __construct(
        private readonly OrderProcessorInterface $decorated,
        private readonly QuoteOrderResolverInterface $quoteOrderResolver,
        private readonly bool $allowPromotions,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if ($this->allowPromotions || !$order instanceof OrderInterface || null === $this->quoteOrderResolver->resolveLockingQuote($order)) {
            $this->decorated->process($order);

            return;
        }

        foreach ($order->getPromotions()->toArray() as $promotion) {
            $order->removePromotion($promotion);
        }
        $order->setPromotionCoupon(null);
    }
}
