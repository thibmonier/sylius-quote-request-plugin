<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

final class QuoteTransitionException extends \RuntimeException
{
    public static function notAllowed(string $transition, string $status): self
    {
        return new self(sprintf('The "%s" transition cannot be applied to a quote request in the "%s" status.', $transition, $status));
    }
}
