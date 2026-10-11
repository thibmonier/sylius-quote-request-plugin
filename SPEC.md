# SPEC — tmonier/sylius-quote-request-plugin

B2B request for quote (RFQ) for Sylius 2.2 / 2.3.

## 1. Research (2026-10-11)

### Existing Sylius plugins

| Package | Last release | Sylius | Status |
|---|---|---|---|
| `asdoria/sylius-quote-request-plugin` | 0.1.2, 2023-10-12 | none declared, Symfony 5.4/6.0, needs `asdoria/sylius-quick-shopping-plugin` | Sylius 1 era, 442 installs, last push 2024-02 |
| `tavy315/sylius-quotes-plugin` | 0.3.1, 2021-10-08 | `^1.8` | dead |
| Sylius RFQ module (Sylius Plus) | 3.1.1, 2026-06 | 1.13 – 2.2 | paid, proprietary |

No maintained open-source quote plugin for Sylius 2 exists (Packagist search "sylius quote",
GitHub search `sylius quote`). The official RFQ module is part of the paid Sylius Plus B2B
suite: request from the cart, merchant offer, back-and-forth, conversion to order.

Sources: https://packagist.org/search.json?q=sylius%20quote,
https://packagist.org/packages/asdoria/sylius-quote-request-plugin,
https://packagist.org/packages/tavy315/sylius-quotes-plugin,
https://addons.sylius.com/en_US/products/rfq-plugin,
https://store.sylius.com/products/rfq-plugin-by-sylius.

### UX references

- **Adobe Commerce B2B negotiable quotes**: quote requested from the cart; statuses New, Open,
  Submitted (Pending on the storefront), Client reviewed, Updated, Ordered, Closed, Declined,
  Expired; only the party currently reviewing can change the quote; line-item or quote-level
  discounts by the seller; expiration when the buyer does not answer in time; after
  acceptance no more discount, checkout at the negotiated price, quote *Ordered* once the order
  is placed. https://experienceleague.adobe.com/en/docs/commerce-admin/b2b/quotes/quotes
- **PrestaShop quote modules** (FME *Request a Quote*, Addify *Request a Quote*): *Add to
  quote* button on product pages, quote basket of several products, custom form (company,
  message...), statuses pending / accepted / rejected, expiry dates, admin messages, link to a
  prefilled cart. https://addify.store/product/prestashop-request-a-quote/,
  https://www.templatemonster.com/prestashop-extensions/prestashop-request-a-quote-module-547754.html

Kept for v1: request from the cart and from product pages (basket), a single workflow with a
negotiation loop (`request_changes` / `revise`), quote-level discount plus per-line prices,
validity date with an expiry command, messages thread, acceptance into a cart with locked
prices then the regular checkout, guest access by secret link. Left out: buyer-side edition of
quantities during negotiation (the buyer asks by message), shipping proposed in the quote,
attachments, company accounts.

## 2. Conversion mechanism (decision)

Goal: an accepted quote becomes a real Sylius order whose prices are exactly the negotiated
ones, after the full order processing, without changing core entities.

Options considered:

1. **Immutable order items + plugin processor (chosen).** `OrderItem::$immutable` is a core
   flag already honoured by `OrderPricesRecalculator` (priority 50). The plugin adds
   `QuotePriceLockProcessor` at priority 45: for a cart linked to an accepted quote it makes the
   items equal to the quote lines (variant, quantity), sets the negotiated unit price, keeps the
   items immutable and spreads the global discount as `order_promotion` adjustments (origin
   `tmonier_quote_request`) on the units. Shipping (40), promotions (20) and taxes (10) run
   after it, so taxes are computed on the discounted negotiated prices. Idempotent: the
   adjustments clearer (60) removes the discount every time and the processor adds it again.
2. Plugin-specific adjustment type for the price difference: rejected, the order would show the
   catalog price plus an unknown adjustment, and tax / promotion distributors would not see it.
3. Copy the negotiated price into a channel pricing / customer group pricing: rejected, it leaks
   to other carts and needs core changes.
4. A plugin `OrderItem` subclass with a "locked price" field: rejected, it modifies a core
   entity (forbidden for these plugins) and conflicts with other plugins doing the same.

Link quote ↔ order: `tmonier_quote_request.order_id` (plugin table, `ON DELETE SET NULL`).
The processor finds the quote by order (one indexed query per cart processing, only for
persisted carts). The cart is flushed before its first processing for that reason.

Cart edition: the processor restores the quote content whatever the customer does
(quantities, extra products). The cart page explains it and offers *Leave the quote prices*:
the link is removed, the items become mutable, the catalog prices come back, the quote stays
*accepted* and *Go to checkout* starts a new quote cart later (the old cart may also have
been removed by `sylius:remove-expired-carts`).

Promotions: Adobe forbids discounts after acceptance; the plugin decorates
`sylius.order_processing.order_promotion_processor` and, unless `allow_promotions` is true,
skips it for quote carts and removes applied promotions and the coupon (so that their usage is
not counted). Catalog promotions only affect the catalog price, which a negotiated price
replaces anyway.

Taxes: computed by Sylius (`OrderTaxesProcessor`) with the zone of the order address, on top
of / inside the negotiated price according to the tax rate (`included_in_price`). The merchant
enters prices in the same convention as the channel pricings. Quote totals reuse the same code
on a transient order (customer default address or channel default tax zone); the integration
test checks that they equal the order totals.

Shipping and payment: chosen at checkout, outside the quote (v1).

Ordered: `workflow.sylius_order_checkout.completed.complete` → the accepted quote of the order
goes to *ordered* (no flush, the checkout flushes). Works for the shop checkout, the API
checkout and the admin conversion.

## 3. Model

- `QuoteRequest` (`tmonier_quote_request`): number (`Q000042`, from the id), status, token,
  channel, locale, currency (channel base currency), customer (nullable: guests), contact
  (e-mail, company, first/last name, phone, VAT number), discount amount or percentage,
  validity date, internal note, order, dates (created, updated, proposed, accepted, ordered).
- `QuoteRequestLine`: variant (nullable when deleted), product / variant names and code
  (snapshot), quantity, requested quantity, catalog unit price (snapshot), negotiated unit
  price (null = catalog price).
- `QuoteMessage`: author type (customer / admin), author name, body, date.

## 4. Workflow

| Transition | From | To | Who |
|---|---|---|---|
| review | new | in_review | merchant |
| propose | new, in_review | proposed | merchant (guard: one orderable line; validity defaults to `default_validity_days`) |
| revise | proposed | in_review | merchant |
| request_changes | proposed | in_review | customer (with a message) |
| accept | proposed | accepted | customer, or merchant through *convert to order* (guard: still valid, one orderable line) |
| order | accepted | ordered | checkout completion |
| decline | new, in_review, proposed | declined | merchant; customer for a proposal |
| expire | proposed | expired | `tmonier:quote-request:expire`, merchant |
| cancel | new, in_review, proposed, accepted | cancelled | merchant; customer while new / in review. Releases the quote cart |

The merchant edits a quote only in *new* / *in review*. The customer sees the merchant prices
from *proposed* on (catalog prices are shown as indicative before).

## 5. Access and security

- Guest access by token (32 random bytes, base64url, unique index), checked again with
  `hash_equals` (MySQL collations are case-insensitive) and limited to the channel of the quote.
- Account pages: quote found by number **and** customer of the logged-in shop user.
- CSRF tokens on every POST (shop and admin), per action and per quote.
- E-mails escape customer input explicitly (the Sylius layout has autoescape off).

## 6. Tests

- Unit: workflow definition and guards, discount calculator, price lock synchronizer (items,
  immutability, discount spread, idempotence, restoring a changed cart), promotion decorator,
  token generator, session basket, entities, mailer recipients, configuration, translations.
- Integration: accepted quote → full checkout through the state machine and the composite
  order processor → order with the negotiated prices, the quote discount, no cart promotion
  (an applicable 50 % promotion exists and does apply to a regular cart), taxes > 0, quote
  totals = order totals; changed cart restored; release; cancel; expiry command.
- Functional: guest request from a product page, customer request from the cart with prefilled
  details, validation, CSRF, account list / detail and access control, token access, prices
  hidden before the proposal, accept → cart → checkout page, leave and resume, ask for changes,
  decline, expired proposal, admin grid and filters, edit lines / prices / discount / add
  product and send the proposal (e-mail), save, message, transitions, CSRF and admin firewall,
  conversion to a placed order.
