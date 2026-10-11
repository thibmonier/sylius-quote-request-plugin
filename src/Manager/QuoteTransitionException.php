<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

final class QuoteTransitionException extends \RuntimeException
{
    public static function notAllowed(string $transition, string $status): self
    {
        return new self(sprintf('The "%s" transition cannot be applied to a quote request in the "%s" status.', $transition, $status));
    }

    public static function concurrentChange(string $status): self
    {
        return new self(sprintf('The quote request was changed meanwhile (now "%s").', $status));
    }

    public static function cartOfAnotherCustomer(): self
    {
        return new self('The cart of this quote belongs to another customer.');
    }
}
