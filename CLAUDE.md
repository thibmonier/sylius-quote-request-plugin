# CLAUDE.md

Sylius 2 plugin: B2B request for quote (shop request from the cart or product pages, admin
negotiation, acceptance into a cart with locked prices, regular checkout). Functional spec and
decisions: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:quote-request:expire -v
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3322, MailHog on
8041/1041), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_quote_request_dev` / `_test`,
both loaded with the default fixture suite, which includes the `tmonier_quote_request` demo
fixture (8 quotes in every status, ordered included when the random fixtures allow it). Dev server:
`symfony server:start -d --port=8088 --no-tls` (admin `sylius` / `sylius`, shop
`shop@example.com` / `sylius`).

## Architecture

- `src/Entity`: `QuoteRequest`, `QuoteRequestLine`, `QuoteMessage` (Sylius resources, XML
  mapped superclasses in `config/doctrine`, migration in `src/Migrations`, Schema API so it
  runs on MySQL, MariaDB and PostgreSQL).
- Workflow `tmonier_quote_request` in `config/app/workflow.yaml` (marking store: `status`),
  guards in `EventListener/QuoteRequestGuardListener`. Constants in `src/Workflow`.
- `Manager/QuoteRequestManager`: the only place that changes a quote (submit, apply, accept,
  markOrdered, postMessage, save): transition + dates + flush + plugin events. Controllers,
  the expiry command, the fixture and the checkout listener go through it.
- Price lock: `Pricing/QuoteOrderSynchronizer` (items = quote lines, immutable, negotiated
  price, discount spread as `order_promotion` adjustments with origin `tmonier_quote_request`),
  used by `OrderProcessing/QuotePriceLockProcessor` (tag `sylius.order_processor`, priority 45)
  and by `Pricing/QuoteTotalsCalculator` (transient order + Sylius taxes processor).
  `OrderProcessing/QuoteAwarePromotionProcessor` decorates the promotion processor.
  `OrderProcessing/QuoteOrderResolver` finds the accepted quote of a persisted cart.
- `Checkout/QuoteCartManager`: creates / reuses the quote cart (flushes before processing, the
  resolver needs an id), stores it as the session cart; `release()` unlinks it (flushes first).
- `EventListener/OrderCheckoutCompletedListener`: `workflow.sylius_order_checkout.completed.complete`
  → `markOrdered()` (no flush); `OrderCheckoutCompletedDoctrineListener` (onFlush, checkoutState
  → completed) does the same for any state machine adapter.
- `Pdf/QuotePdfGeneratorInterface` + `SnappyQuotePdfGenerator` (optional knp-snappy engine).
  The test application aliases `tmonier_quote_request.pdf_generator` to
  `tests/TestApplication/src/Pdf/FakeQuotePdfGenerator` (`tests/TestApplication/config/fake_quote_pdf.php`)
  unless `WKHTMLTOPDF_PATH` points to a wkhtmltopdf binary.
- Volume discounts: `QuoteRequestLine` discount type + tiers (`getEffectiveUnitPrice()` is the
  price locked in the order), `Form/DataTransformer/DiscountTiersTransformer`.
- The test application reads `allow_guests` from `TMONIER_QUOTE_ALLOW_GUESTS` (see
  `ShopGuestsDisabledTest`) and suggests group discounts for `retail` / `wholesale`.
- `Conversion/QuoteOrderConverter`: admin "convert to order" (accepts a proposal if needed,
  applies the checkout transitions with the merchant's address and methods).
- `Basket/SessionQuoteBasket`: products collected before the request (session, per channel).
- `Mailer/QuoteRequestMailer`: subscriber on the plugin events, e-mails in `config/app/mailer.yaml`.
- Controllers: `Controller/Shop` (basket, request form, token pages, account, actions),
  `Controller/Admin` (show/edit, transitions, message, convert). Admin index is the
  ResourceController + grid `tmonier_quote_request_admin_quote_request`.
- Twig: `Twig/QuoteRequestExtension` (`tmonier_quote_request_totals`, `_can`, `_cart_quote`,
  `_setting`, `_shows_prices`, `_status_color`). Hooks in `config/twig_hooks`.

- Locks and atomicity: `QuoteRequestManager::transactional()` (transaction + `SELECT ... FOR
  UPDATE` on the quote with a status re-check, events dispatched after commit, EM cleared on
  failure). Accept, startCheckout, apply and the conversion use it.
- `Checkout/QuoteOrderabilityChecker`: accept guard, synchronizer (unsellable lines left out,
  fixed discount prorated), conversion validated with `sylius_checkout_complete`.
- Real PDF: `WKHTMLTOPDF_PATH=$PWD/tests/TestApplication/bin/wkhtmltopdf-docker` (clear
  var/cache/test) runs `QuotePdfRenderingTest` with wkhtmltopdf in Docker and pdftotext.
- Winzou: `winzou/state-machine-bundle` is a dev dependency registered in the test app;
  `TEST_SYLIUS_STATE_MACHINE_ADAPTER=winzou_state_machine` switches the Sylius graphs
  (`WinzouStateMachineTest`). The rate limiter of the test app uses a filesystem pool cleared
  by every functional test.

## Rules

- No core entity change: plugin tables only.
- Labels in `messages`, flashes in `flashes`; every key in en, fr, de, es, it, nl, pl
  (`TranslationsTest` also checks that every key used in src/templates/config exists).
  Clear the cache (dev and test) after adding a translation file.
- E-mail templates: the Sylius e-mail layout has `autoescape false`: escape every user value
  with `|e`.
- Admin forms need `{% form_theme form '@SyliusAdmin/shared/form_theme.html.twig' %}`, shop
  forms `@SyliusShop/form/theme.html.twig`.
- Functional tests run in a transaction rolled back after each test (kernel not rebooted).
  Sylius fixtures are random (default tax zone, addresses...): create the quotes, carts and
  promotions each test needs. Check e-mails before following the redirect.
