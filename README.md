# Sylius Quote Request Plugin

> Français : [README.fr.md](README.fr.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-quote-request-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-quote-request-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**B2B request for quote (RFQ) for Sylius 2: customers ask for a quote, the merchant negotiates,
the customer accepts and checks out at the negotiated prices.**

Wholesale buyers rarely pay the catalog price: they ask for a quote for 200 units, the sales
team answers with its prices, and the order follows. Sylius 2 has no quote feature in its core,
and the open-source quote plugins stopped at Sylius 1.

This plugin adds the whole loop to the shop and the admin, and turns an accepted quote into a
regular Sylius order whose prices cannot be changed by the cart processing. It is free and
open source.

## Features

**Shop**

- *Request a quote for this cart* on the cart page, and *Add to quote request* on product pages
  (variant + quantity, several products can be collected before sending).
- Request form: company, first and last name, e-mail, phone, VAT / company number (optional),
  message and the desired quantity of every product. Logged-in customers get their details
  prefilled; guests are welcome (configurable).
- **My quotes** in the customer account: list and detail with status, lines, proposed prices,
  validity date and the messages thread. The customer accepts, declines, asks for changes
  (with a message) or cancels a request still in review.
- Guests follow their quote with a **secret link** sent by e-mail (256-bit random token), with
  the same actions.
- **Accept → cart with locked prices → regular checkout.** Accepting creates a cart holding
  exactly the quote lines at the negotiated prices; the customer chooses shipping and payment
  as usual, and the order is linked to the quote, which becomes *ordered*. The cart page shows
  that the cart comes from a quote, with a button to go back to the catalog prices.

**Admin** (*Sales → Quote requests*)

- Grid with filters: status, number, customer (e-mail, company, name), channel, date.
- Quote page: edit quantities, **override unit prices**, **volume discounts per line**
  (percentage or amount per unit, by quantity tiers such as `10: 5; 50: 10`), remove lines,
  add products (autocomplete), **global discount** (amount or percentage) with an optional
  **customer group suggestion** (one click to apply), validity date, internal note, message to
  the customer, totals with the channel taxes.
- Actions: *Save*, *Save and send the proposal* (e-mail), start the review, revise a sent
  proposal, decline, mark as expired, cancel, **PDF**, and two ways to place the order for the
  customer: **Convert to order now** (one click: customer's default address, default shipping
  and payment methods, payment awaiting) or **Convert to order** (address and methods chosen
  by the merchant).

**Quote PDF** (optional engine)

- Admin and customer download a PDF of the proposal: number, seller billing data (channel
  shop billing data), customer, lines with catalog price, negotiated price, volume discount,
  global discount, taxes, totals and validity date. It is attached to the *proposal* e-mail.
- Default engine: wkhtmltopdf through `knplabs/knp-snappy` (`knp_snappy.pdf` of
  KnpSnappyBundle, or `pdf_binary`). Without engine, PDF links and attachments are simply
  hidden. Replace the `tmonier_quote_request.pdf_generator` service
  (`QuotePdfGeneratorInterface`) to use another engine.
- Messages thread shared with the customer.

**Under the hood**

- Symfony Workflow `tmonier_quote_request`: `new → in_review → proposed → accepted → ordered`,
  plus `declined`, `expired`, `cancelled` (and `proposed → in_review` when revised or when the
  customer asks for changes). A proposal is accepted only while valid.
- `tmonier:quote-request:expire` marks the proposals past their validity date as expired
  (run it from cron).
- E-mails through the Sylius mailer: new request (merchant + customer acknowledgement),
  proposal (customer), acceptance (merchant), messages (the other party).
- Events for integrators: `tmonier_quote_request.created`, `.proposed`, `.accepted`,
  `.ordered`, `.declined`, `.expired`, `.cancelled`, `.changes_requested`, `.message_posted`.
- Demo fixture `tmonier_quote_request` (quotes in every status, one of them ordered).
- Translations in English, French, German, Spanish, Italian, Dutch and Polish; Twig Hooks;
  MySQL, MariaDB and PostgreSQL migrations.

## How the negotiated prices survive the order processing

Sylius recalculates every cart (`sylius.order_processor`): prices from the channel pricing,
promotions, shipping, taxes. The plugin plugs into this pipeline instead of fighting it:

1. Cart items of an accepted quote are **immutable** (`OrderItem::$immutable`, a core flag):
   the Sylius prices recalculator skips them.
2. A processor at priority 45 (after the prices recalculator, before shipping, promotions and
   taxes) re-applies the quote on every processing: same variants and quantities, unit price
   = negotiated price minus the volume discount of the reached tier, global discount spread on the units as an order promotion adjustment
   (origin code `tmonier_quote_request`). If the customer changes the cart, the quote content
   comes back.
3. The promotion processor is decorated: by default, **cart promotions and coupons are not
   applied** to a quote cart (`allow_promotions: true` to allow them). Catalog promotions are
   part of the catalog price and never touch the negotiated price.
4. **Taxes** are computed by Sylius as usual, on the discounted negotiated prices, with the
   tax zone of the customer's address; prices follow the convention of your tax rates
   (included in price or not). **Shipping** is chosen and charged at checkout.

The totals shown on the quote are computed the same way (a transient order goes through the
same synchronizer and the Sylius taxes processor), with the zone of the customer's default
address or the default tax zone of the channel.

## Screenshots

![Product page with the "Need large quantities or a business price?" box to add a variant and quantity to a quote request](docs/images/shop-product-quote.jpg)

![Quote request form in the shop: collected products with desired quantities and the buyer's company details](docs/images/shop-quote-request-form.jpg)

![Proposal received by the customer: negotiated unit prices against catalog prices, global discount, totals, accept or decline, and message thread](docs/images/shop-quote-proposal.jpg)

![Cart created from an accepted quote with the negotiated prices locked before checkout](docs/images/shop-quote-cart.jpg)

![Quote requests in the back office with customer, status, validity and line count](docs/images/admin-quote-requests.jpg)

![Admin negotiation of a quote: negotiated unit prices, volume discount tiers, global discount, internal note and message to the customer](docs/images/admin-quote-request-negotiation.jpg)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2.9+, 2.3 (security fixes GHSA-2rv4-pjmm-7fxf, GHSA-vv4h-q2x8-74g4) |
| Symfony | 6.4, 7.4, 8.0 |

State machines: the quote graph `tmonier_quote_request` is always mapped to the Symfony
Workflow adapter (`graphs_to_adapters_mapping`), so it works when the shop keeps
`default_adapter: winzou_state_machine`. The quote becomes *ordered* on
`workflow.sylius_order_checkout.completed.complete` (Symfony Workflow adapter, the Sylius 2
default) and, whatever the adapter (Winzou included), when an order whose checkout state
becomes `completed` is flushed (Doctrine `onFlush` listener, event dispatched on `postFlush`).
Both paths are tested.

Optional: `knplabs/knp-snappy-bundle` (or `knplabs/knp-snappy` with `pdf_binary`) and
wkhtmltopdf for the quote PDF. Required: `symfony/rate-limiter` (guest rate limits).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-quote-request-plugin
    ```

2. Register the bundle in `config/bundles.php`:

    ```php
    Tmonier\SyliusQuoteRequestPlugin\TmonierSyliusQuoteRequestPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_quote_request.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusQuoteRequestPlugin/config/config.yaml" }
    ```

4. Import the routes (admin and shop) in `config/routes/tmonier_sylius_quote_request.yaml`:

    ```yaml
    tmonier_sylius_quote_request:
        resource: "@TmonierSyliusQuoteRequestPlugin/config/routes.yaml"
    ```

5. Run the migration (three tables: `tmonier_quote_request`, `tmonier_quote_request_line`,
   `tmonier_quote_request_message`):

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Expire old proposals every night:

    ```
    15 2 * * * cd /var/www/shop && bin/console tmonier:quote-request:expire
    ```

## Configuration

```yaml
# config/packages/tmonier_sylius_quote_request.yaml
tmonier_sylius_quote_request:
    allow_guests: true          # visitors without account can request a quote
    default_validity_days: 30   # validity of a proposal sent without date
    admin_email: ~              # merchant notifications; the channel contact e-mail when null
    from_product_page: true     # "Add to quote request" on product pages
    from_cart: true             # "Request a quote for this cart" on the cart page
    allow_promotions: false     # cart promotions on top of the negotiated prices
    max_lines: 100              # lines of one request
    customer_group_discounts:   # global discount (%) suggested by customer group code
        wholesale: 12.5
    pdf_binary: ~               # wkhtmltopdf path, with knplabs/knp-snappy, when KnpSnappyBundle is not installed
    base_url: ~                 # base of the links in the e-mails; the channel hostname when null
    checkout_idle_minutes: 30   # the merchant converts a quote whose cart checkout was abandoned that long ago
    pdf_attachment_directory: '%kernel.project_dir%/var/tmonier_quote_request/pdf' # proposal PDFs, kept 48 h (created 0775)
    rate_limit:                 # valid quote requests (guests and customers), messages, and quote transitions (accept/decline/cancel)
        enabled: true
        per_ip: 10              # IPv6: per /56 network; IPv4-mapped addresses count as IPv4
        per_email: 3            # case, +tag and Gmail dots ignored
        messages_per_ip: 30     # also caps transitions per IP
        messages_per_quote: 10  # also caps transitions per quote (token)
        interval: '1 hour'
        cache_pool: cache.rate_limiter
```

Validity dates are stored as the end of the day (23:59:59) in the PHP default timezone: use
the same `date.timezone` for PHP-FPM and for the cron running `tmonier:quote-request:expire`.

`allow_guests` (like the other flags) accepts an env variable, e.g.
`'%env(bool:QUOTE_ALLOW_GUESTS)%'`.

The models, repository and factories are Sylius resources
(`tmonier_quote_request.quote_request`, `.quote_request_line`, `.quote_message`) and can be
overridden under `tmonier_sylius_quote_request.resources`. E-mail templates are the Sylius
mailer e-mails `tmonier_quote_request_*` (see `config/app/mailer.yaml`).

## Events

```php
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;

#[AsEventListener(event: QuoteRequestEvents::ACCEPTED)]
public function onAccepted(QuoteRequestEvent $event): void
{
    $quote = $event->getQuoteRequest(); // $quote->getOrder() is the cart with the locked prices
}
```

Events of the operations the plugin runs in its own transaction (accept, merchant conversion,
merchant and customer transitions) are dispatched right after that transaction commits, in
the order of the changes (`accepted` before `ordered`), and not at all if it rolls back.

**Limitation:** events raised inside a transaction the plugin does not own (a Sylius API
command behind the messenger `doctrine_transaction` middleware, the checkout completion flush
that makes a quote `ordered`, any caller wrapping the plugin in its own transaction) are
dispatched right away, on postFlush, like the Sylius core events: listeners may run before the
outer transaction commits, and are not undone if it rolls back. A listener that fails after a
commit (e.g. the mail server is down) is logged and reported as a warning flash; the other
listeners and events still run and the operation is not reported as failed. For reliable integrations
(ERP, webhooks), record the events in an outbox, e.g.
[tmonier/sylius-webhook-outbox-plugin](https://github.com/thibmonier/sylius-webhook-outbox-plugin). What the `ordered` listeners persist is
flushed right after them, whatever the state machine adapter. The Symfony Workflow events of
`tmonier_quote_request` are available too.

## Integration with other plugins

- **Order processors.** `QuotePriceLockProcessor` is a `sylius.order_processor` at priority
  **45**: after the adjustments clearer (60) and the prices recalculator (50), before shipping
  (40), promotions (20) and taxes (10). A pricing plugin (e.g. tier pricing) must run at
  priority **<= 44** and must leave `immutable` items alone.
- **The `immutable` contract.** Items of a quote cart are `OrderItem::$immutable` (the Sylius
  prices recalculator skips them) and carry a `tmonier_quote_lock` adjustment. When a cart
  loses its quote (released, cancelled, conversion by the merchant), the plugin makes the items
  mutable again and runs the Sylius prices recalculator: catalog prices first, then any
  processor registered after 45 applies as usual. Do not set `immutable` on items of a quote
  cart yourself, and do not unlock them.
- **Quote carts.** `Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartCheckerInterface::isQuoteCart($order)`
  tells whether a cart is the cart of an accepted quote: its content is restored at every
  processing, so a bulk add-to-cart (quick order...) should refuse or divert to a regular cart.
  Service id `tmonier_quote_request.quote_cart_checker` (stable public API, also aliased by the
  interface); tmonier/sylius-quick-order-plugin uses it when installed.
- **Events**: see above; for integrations, prefer an outbox.

## Security

- The secret link is the access of **guest** quotes: 256-bit tokens, compared in constant time
  (case-sensitive even on MySQL collations), limited to the channel of the quote. Anyone
  holding the link can view and accept a guest quote (as stated in the e-mail).
- The quotes of a **shop account** need their owner logged in, link or not (other customers get
  a 403, visitors the login page); their e-mails link to *My quotes*. The cart of an accepted
  quote is never handed to another customer.
- Quote requests are rate limited per IP (IPv6 /56) and per mailbox (sub-addresses and Gmail
  dots count as one); messages and quote transitions (accept / decline / cancel) share the
  `messages_per_ip` / `messages_per_quote` budget, preventing a token holder from hammering
  the transition endpoint; only valid submissions count. A honeypot field drops robots. E-mails
  to the customer never repeat the contact details typed in the form (the recipient may not be
  the author); the merchant e-mail keeps them.
- Links in e-mails come from `base_url` or the channel hostname (scheme following
  `sylius.unsecured_urls`); without either, from the router default URI or the request: set
  `framework.trusted_hosts` (or `base_url`).
- Every action is a POST with a CSRF token; the admin pages are behind the Sylius admin
  firewall. Customers can only cancel a request in progress or decline a proposal.
- Customer input is escaped in the e-mails (the Sylius e-mail layout disables autoescaping) and
  in the PDF; wkhtmltopdf runs with `--disable-local-file-access` and `--disable-javascript`.
- Accepting and converting check that every product can still be ordered (enabled, sold in
  the channel, stock for tracked variants): no partial or empty order. The conversion runs the
  `sylius_checkout_complete` validation of the shop checkout, keeps the chosen shipping method
  or fails, and is atomic.
- Concurrency: every change of a quote locks its row; the decisions on its cart (accept,
  checkout start, conversion) also check and bump a version, so a decision taken on an outdated
  quote is refused (tested with two connections). Messages and merchant edits never make a
  concurrent request fail, nor does the quote ever make a checkout completion fail.
- Invariant: a cart holds items at quote prices only while it is the cart of the accepted
  quote holding them (items marked by a `tmonier_quote_lock` adjustment). Any other cart gets
  the catalog prices back at its next processing, and its checkout completion is refused
  (Symfony Workflow guard and a Doctrine check for any adapter). An order that does not hold
  the quote lines never makes the quote "ordered".
- A merchant conversion takes over the customer's quote cart (emptied), unless the customer is
  in its checkout: then it waits until the cart has been idle for `checkout_idle_minutes`.
- Once a quote is ordered (customer checkout or merchant conversion), the requested quantities
  of its products leave the cart the request was made from ("Request a quote for this cart"),
  unless that cart is at the payment step and was used within `checkout_idle_minutes`; the
  rest of the cart and the other carts are not touched.
- The rate limiters use the lock component (the plugin enables `framework.lock` with the flock
  store unless the application configures it): parallel requests respect the limits (tested).

## Limitations

- Doctrine DBAL 3 (Sylius 2.2): when the plugin runs inside a caller's transaction (e.g. an API
  command behind the messenger `doctrine_transaction` middleware) and `use_savepoints` is off,
  a failed plugin operation marks the caller's transaction rollback-only. Enable
  `doctrine.dbal.use_savepoints: true` (always on with DBAL 4).
- One currency per quote: the base currency of the channel.
- Shipping costs are not part of the quote; they are chosen and charged at checkout.
- Lines are product variants of the catalog (no free-text line, no attachment).
- Amount volume discounts assume a currency with 2 decimals.
- Rate limit keys only fold `+tag` sub-addressing (all providers) and Gmail dots; other
  provider aliases count as distinct mailboxes.
- The PDF of the proposal e-mail is attached by path (Sylius mailer): each PDF is written as a
  uniquely named file (`tmonier_quote_<number>-<random>.pdf`, 0644) in `pdf_attachment_directory` (default
  `%kernel.project_dir%/var/tmonier_quote_request/pdf`) and removed after 48 hours, so that an
  async mailer worker can read it. The directory is created 0775 when missing (an existing one
  keeps its permissions): run php-fpm, the console and the workers with a common group, or
  grant them an ACL, e.g.
  `setfacl -R -m u:www-data:rwX -m d:u:www-data:rwX var/tmonier_quote_request`. With workers on
  other hosts, use a shared directory or send the e-mails synchronously.
- A PDF engine that fails sends the proposal without attachment and warns the merchant.
- A product disabled or removed from the channel after the acceptance leaves the quote cart
  (with its share of a fixed global discount); the customer is told on the quote page.
- The PDF layout is a single Twig template (`templates/pdf/quote.html.twig`), override it in
  your theme or templates directory.
- A quote cart cannot be mixed with other products: leaving the quote prices brings the cart
  back to the catalog prices, the quote stays accepted and can start a new cart later.
- Guests who accept a quote check out as guests (e-mail asked by the checkout).
- The admin conversion uses the address and methods entered by the merchant; inventory and
  payment follow the regular Sylius rules (the order is placed with a new payment).
- No API Platform endpoints yet.

## Development

See [CLAUDE.md](CLAUDE.md) for the commands and [SPEC.md](SPEC.md) for the research and design
decisions.

<!-- vitrine:demo-b2b:start -->
## B2B demo

This plugin is used in the [Sylius B2B demo](https://github.com/thibmonier/sylius-b2b-demo): a Sylius 2.3 shop with fictitious sample data where the B2B plugins work together. The source code, setup and fixtures of the demo are in that repository.

The demo is an example, not a production setup.
<!-- vitrine:demo-b2b:end -->

## License

MIT, see [LICENSE](LICENSE).
