# Sylius Quote Request Plugin

[![Build](https://github.com/thibmonier/sylius-quote-request-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-quote-request-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**B2B request for quote (RFQ) for Sylius 2: customers ask for a quote, the merchant negotiates,
the customer accepts and checks out at the negotiated prices.**

Wholesale buyers rarely pay the catalog price: they ask for a quote for 200 units, the sales
team answers with its prices, and the order follows. In Sylius 2 the only quote feature is the
paid Sylius Plus RFQ module; the open-source quote plugins stopped at Sylius 1.

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
- Demo fixture `tmonier_quote_request` (quotes in every status).
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

Screenshots go in `docs/images/`.

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |

The quote becomes *ordered* on `workflow.sylius_order_checkout.completed.complete` (Symfony
Workflow adapter, the Sylius 2 default) and, whatever the adapter (Winzou included), when an
order whose checkout state becomes `completed` is flushed (Doctrine `onFlush` listener).

Optional: `knplabs/knp-snappy-bundle` and wkhtmltopdf for the quote PDF.

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
    pdf_binary: ~               # wkhtmltopdf path, when KnpSnappyBundle is not installed
```

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

`created`, `proposed`, `accepted` and the messages events are dispatched after the change is
flushed; `ordered` is dispatched during the checkout completion (flushed with the order). The
Symfony Workflow events of `tmonier_quote_request` are available too.

## Security

- The secret link is the only access for guests: 256-bit tokens, compared in constant time,
  limited to the channel of the quote. Anyone holding the link can view and accept the quote
  (as stated in the e-mail).
- *My quotes* only shows the quotes of the logged-in customer (404 otherwise).
- Every action is a POST with a CSRF token; the admin pages are behind the Sylius admin
  firewall.
- Customer input is escaped in the e-mails (the Sylius e-mail layout disables autoescaping).

## Limitations

- One currency per quote: the base currency of the channel.
- Shipping costs are not part of the quote; they are chosen and charged at checkout.
- Lines are product variants of the catalog (no free-text line, no attachment).
- Amount volume discounts assume a currency with 2 decimals.
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

## License

MIT, see [LICENSE](LICENSE).
