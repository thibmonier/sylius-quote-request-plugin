<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit;

use Sylius\Resource\Factory\FactoryInterface;

/**
 * @template T of object
 * @implements FactoryInterface<T>
 */
final class CallbackFactory implements FactoryInterface
{
    /** @param \Closure(): T $create */
    public function __construct(private readonly \Closure $create)
    {
    }

    /** @return T */
    public function createNew(): object
    {
        return ($this->create)();
    }
}
