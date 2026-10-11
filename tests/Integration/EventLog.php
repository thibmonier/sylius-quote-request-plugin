<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

/** Events received by a test listener: [event name, quote number]. */
final class EventLog
{
    /** @var list<array{string, ?string}> */
    public array $entries = [];
}
