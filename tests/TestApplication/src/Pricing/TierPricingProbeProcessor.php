<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Pricing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * Test application only: stands for a tier pricing plugin (order processor at priority 44, after
 * the quote price lock at 45). Disabled unless a test enables it: 100 off every mutable item.
 */
final class TierPricingProbeProcessor implements OrderProcessorInterface
{
    public static bool $enabled = false;

    public function process(BaseOrderInterface $order): void
    {
        if (!self::$enabled || !$order instanceof OrderInterface || OrderInterface::STATE_CART !== $order->getState()) {
            return;
        }

        foreach ($order->getItems() as $item) {
            if ($item instanceof OrderItemInterface && !$item->isImmutable()) {
                $item->setUnitPrice(max(0, $item->getUnitPrice() - 100));
            }
        }
    }
}
