<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Mailer;

final class QuoteRequestEmails
{
    /** To the merchant: a new request arrived. */
    public const ADMIN_NEW_REQUEST = 'tmonier_quote_request_admin_new_request';

    /** To the customer: the request is received (with the link to follow it). */
    public const CUSTOMER_REQUEST_RECEIVED = 'tmonier_quote_request_customer_request_received';

    /** To the customer: the proposal is ready. */
    public const CUSTOMER_PROPOSAL = 'tmonier_quote_request_customer_proposal';

    /** To the merchant: the customer accepted the proposal. */
    public const ADMIN_ACCEPTED = 'tmonier_quote_request_admin_accepted';

    /** To the merchant: the customer wrote a message or asked for changes. */
    public const ADMIN_CUSTOMER_MESSAGE = 'tmonier_quote_request_admin_customer_message';

    /** To the customer: the merchant wrote a message. */
    public const CUSTOMER_MERCHANT_MESSAGE = 'tmonier_quote_request_customer_merchant_message';

    private function __construct()
    {
    }
}
