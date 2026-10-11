<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Event;

use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestEvents
{
    /** A customer (or guest) submitted a new quote request. */
    public const CREATED = 'tmonier_quote_request.created';

    /** The merchant sent a proposal. */
    public const PROPOSED = 'tmonier_quote_request.proposed';

    /** The customer accepted the proposal: the quote cart exists (`getOrder()`). */
    public const ACCEPTED = 'tmonier_quote_request.accepted';

    /** The order created from the quote completed the checkout (or was converted by the merchant). */
    public const ORDERED = 'tmonier_quote_request.ordered';

    public const DECLINED = 'tmonier_quote_request.declined';

    public const EXPIRED = 'tmonier_quote_request.expired';

    public const CANCELLED = 'tmonier_quote_request.cancelled';

    /** The customer asked for changes to the proposal. */
    public const CHANGES_REQUESTED = 'tmonier_quote_request.changes_requested';

    /** A message was added to the thread (customer or merchant). */
    public const MESSAGE_POSTED = 'tmonier_quote_request.message_posted';

    public const BY_TRANSITION = [
        QuoteRequestTransitions::TRANSITION_PROPOSE => self::PROPOSED,
        QuoteRequestTransitions::TRANSITION_ACCEPT => self::ACCEPTED,
        QuoteRequestTransitions::TRANSITION_ORDER => self::ORDERED,
        QuoteRequestTransitions::TRANSITION_DECLINE => self::DECLINED,
        QuoteRequestTransitions::TRANSITION_EXPIRE => self::EXPIRED,
        QuoteRequestTransitions::TRANSITION_CANCEL => self::CANCELLED,
        QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES => self::CHANGES_REQUESTED,
    ];

    private function __construct()
    {
    }
}
