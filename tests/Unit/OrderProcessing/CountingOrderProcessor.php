<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\OrderProcessing;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

final class CountingOrderProcessor implements OrderProcessorInterface
{
    public int $calls = 0;

    public function process(OrderInterface $order): void
    {
        ++$this->calls;
    }
}
