<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Workflow;

final class QuoteRequestTransitions
{
    public const GRAPH = 'tmonier_quote_request';

    /** Merchant starts working on a new request. */
    public const TRANSITION_REVIEW = 'review';

    /** Merchant sends the proposal (prices, discount, validity) to the customer. */
    public const TRANSITION_PROPOSE = 'propose';

    /** Merchant takes a sent proposal back to change it. */
    public const TRANSITION_REVISE = 'revise';

    /** Customer asks for changes to a proposal (with a message). */
    public const TRANSITION_REQUEST_CHANGES = 'request_changes';

    /** Customer accepts a valid proposal: a cart with the locked prices is created. */
    public const TRANSITION_ACCEPT = 'accept';

    /** The order created from the accepted quote completes the checkout. */
    public const TRANSITION_ORDER = 'order';

    public const TRANSITION_DECLINE = 'decline';

    public const TRANSITION_EXPIRE = 'expire';

    public const TRANSITION_CANCEL = 'cancel';

    private function __construct()
    {
    }
}
