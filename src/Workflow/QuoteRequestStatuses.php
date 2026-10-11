<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Workflow;

final class QuoteRequestStatuses
{
    public const NEW = 'new';

    public const IN_REVIEW = 'in_review';

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const ORDERED = 'ordered';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::NEW,
        self::IN_REVIEW,
        self::PROPOSED,
        self::ACCEPTED,
        self::ORDERED,
        self::DECLINED,
        self::EXPIRED,
        self::CANCELLED,
    ];

    /** Statuses in which the merchant can still change the lines, prices and conditions. */
    public const EDITABLE = [self::NEW, self::IN_REVIEW];

    /** Statuses in which the customer sees the merchant prices. */
    public const WITH_PROPOSAL = [self::PROPOSED, self::ACCEPTED, self::ORDERED, self::EXPIRED];

    private function __construct()
    {
    }
}
