<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class ShopQuoteRequestTest extends WebTestCase
{
    use QuoteRequestTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testAGuestRequestsAQuoteFromAProductPage(): void
    {
        [$variant] = $this->sellableVariants(1);
        $product = $variant->getProduct();
        self::assertNotNull($product);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-add-to-quote]'));

        $form = $crawler->filter('[data-test-tmonier-add-to-quote-button]')->form();
        $form['tmonier_quote_request_add[variant]'] = (string) $this->idOf($variant);
        $form['tmonier_quote_request_add[quantity]'] = '40';
        $this->client->submit($form);
        self::assertResponseRedirects($this->shopUrl('/quote-request'));

        $crawler = $this->client->followRedirect();
        self::assertSame(['The product was added to your quote request.'], $this->flashes($crawler));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-line="%s"]', $variant->getCode())));

        $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[company]' => 'Acme Corp',
            'tmonier_quote_request[firstName]' => 'Ada',
            'tmonier_quote_request[lastName]' => 'Lovelace',
            'tmonier_quote_request[email]' => 'ada.guest@example.com',
            'tmonier_quote_request[phoneNumber]' => '+33 1 02 03 04 05',
            'tmonier_quote_request[vatNumber]' => 'FR40303265045',
            'tmonier_quote_request[lines][0][quantity]' => '50',
            'tmonier_quote_request[message]' => 'We need them <b>before</b> June.',
        ]);
        $this->client->submit($form);

        $quote = $this->entityManager()->getRepository(QuoteRequest::class)->findOneBy(['email' => 'ada.guest@example.com']);
        self::assertInstanceOf(QuoteRequestInterface::class, $quote);
        self::assertResponseRedirects($this->tokenUrl($quote));
        self::assertEmailCount(2);
        $adminEmail = self::getMailerMessage(0);
        self::assertNotNull($adminEmail);
        self::assertEmailAddressContains($adminEmail, 'To', 'contact@example.com');
        self::assertEmailHtmlBodyContains($adminEmail, 'We need them &lt;b&gt;before&lt;/b&gt; June.');
        $customerEmail = self::getMailerMessage(1);
        self::assertNotNull($customerEmail);
        self::assertEmailAddressContains($customerEmail, 'To', 'ada.guest@example.com');
        self::assertEmailHtmlBodyContains($customerEmail, (string) $quote->getToken());

        self::assertNull($quote->getCustomer());
        self::assertSame(QuoteRequestStatuses::NEW, $quote->getStatus());
        self::assertSame('FR40303265045', $quote->getVatNumber());
        self::assertSame(50, $quote->getLines()->first() ? $quote->getLines()->first()->getQuantity() : null);
        self::assertSame(1, $quote->getMessages()->count());

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(['Your quote request was sent. We will get back to you soon.'], $this->flashes($crawler));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-status="new"]'));
        self::assertStringContainsString('We need them <b>before</b> June.', $crawler->filter('[data-test-tmonier-quote-message="customer"]')->text());

        // The basket is emptied once the request is sent.
        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-empty]'));
    }

    public function testACustomerRequestsAQuoteForTheCartWithPrefilledDetails(): void
    {
        $customer = $this->shopCustomer();
        [$first, $second] = $this->sellableVariants(2);
        $this->createCart($customer, [[$first, 2], [$second, 5]]);
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-test-tmonier-request-quote-from-cart-button]')->form());
        self::assertResponseRedirects($this->shopUrl('/quote-request'));

        $crawler = $this->client->followRedirect();
        self::assertCount(2, $crawler->filter('[data-test-tmonier-quote-line]'));
        $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form();
        self::assertSame($customer->getFirstName() ?? '', $form->getValues()['tmonier_quote_request[firstName]'] ?? null);
        self::assertNotNull($crawler->filter('#tmonier_quote_request_email')->attr('disabled'));

        $form['tmonier_quote_request[firstName]'] = 'Bob';
        $form['tmonier_quote_request[lastName]'] = 'Builder';
        // Removing a product: quantity 0.
        $form['tmonier_quote_request[lines][1][quantity]'] = '0';
        $this->client->submit($form);

        $quote = $this->entityManager()->getRepository(QuoteRequest::class)->findOneBy(['customer' => $customer], ['id' => 'DESC']);
        self::assertInstanceOf(QuoteRequestInterface::class, $quote);
        self::assertResponseRedirects($this->shopUrl('/account/quotes/' . $quote->getNumber()));
        self::assertSame($customer->getEmail(), $quote->getEmail());
        self::assertCount(1, $quote->getLines());
        self::assertSame(2, $quote->getLines()->first() ? $quote->getLines()->first()->getQuantity() : null);
        self::assertEmailCount(2);

        $crawler = $this->client->request('GET', $this->shopUrl('/account/quotes'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-row="%s"]', $quote->getNumber())));
    }

    public function testTheRequestFormValidatesTheDetails(): void
    {
        [$variant] = $this->sellableVariants(1);
        $this->addToBasket($variant, 3);

        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
        $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[firstName]' => '',
            'tmonier_quote_request[lastName]' => 'Nobody',
            'tmonier_quote_request[email]' => 'not-an-email',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(QuoteRequest::class)->count(['lastName' => 'Nobody']));
        self::assertEmailCount(0);
    }

    public function testTheProductFormRequiresAValidCsrfToken(): void
    {
        [$variant] = $this->sellableVariants(1);
        $product = $variant->getProduct();
        self::assertNotNull($product);

        $this->client->request('POST', $this->shopUrl(sprintf('/quote-request/products/%d', $this->idOf($product))), [
            'tmonier_quote_request_add' => ['variant' => (string) $this->idOf($variant), 'quantity' => '5', '_token' => 'forged'],
        ]);

        self::assertResponseRedirects($this->shopUrl('/products/' . $product->getSlug()));
        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-empty]'));
    }

    public function testTheCartButtonRequiresAValidCsrfToken(): void
    {
        $this->client->request('POST', $this->shopUrl('/quote-request/from-cart'), ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGuestRequestsAreRateLimitedPerEmail(): void
    {
        [$variant] = $this->sellableVariants(1);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->addToBasket($variant, 5);
            $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
            $this->client->submit($crawler->filter('[data-test-tmonier-quote-submit]')->form([
                'tmonier_quote_request[firstName]' => 'Spam',
                'tmonier_quote_request[lastName]' => 'Bot',
                'tmonier_quote_request[email]' => 'victim@example.com',
            ]));
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSame(3, $this->entityManager()->getRepository(QuoteRequest::class)->count(['email' => 'victim@example.com']));
    }

    public function testTheHoneypotSilentlyDropsTheRequest(): void
    {
        [$variant] = $this->sellableVariants(1);
        $this->addToBasket($variant, 5);
        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));

        $this->client->submit($crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[firstName]' => 'Spam',
            'tmonier_quote_request[lastName]' => 'Bot',
            'tmonier_quote_request[email]' => 'bot@example.com',
            'tmonier_quote_request[website]' => 'http://spam.example',
        ]));

        self::assertResponseRedirects($this->shopUrl('/'));
        self::assertEmailCount(0);
        self::assertSame(0, $this->entityManager()->getRepository(QuoteRequest::class)->count(['email' => 'bot@example.com']));
    }

    public function testCustomerEmailsDoNotRepeatTheDetailsTypedInTheFormAndLinksIgnoreTheHostHeader(): void
    {
        [$variant] = $this->sellableVariants(1);
        $this->addToBasket($variant, 5);
        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
        $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[company]' => 'Your account is suspended call 555-0100',
            'tmonier_quote_request[firstName]' => 'Mallory',
            'tmonier_quote_request[lastName]' => 'Phisher',
            'tmonier_quote_request[email]' => 'someone@example.com',
        ]);

        $this->client->request('POST', (string) $form->getUri(), $form->getPhpValues(), [], ['HTTP_HOST' => 'localhost', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);

        self::assertEmailCount(2);
        $adminEmail = self::getMailerMessage(0);
        $customerEmail = self::getMailerMessage(1);
        self::assertNotNull($adminEmail);
        self::assertNotNull($customerEmail);
        self::assertEmailHtmlBodyContains($adminEmail, 'Your account is suspended call 555-0100');
        self::assertEmailHtmlBodyNotContains($customerEmail, 'Your account is suspended');
        // Channel hostname, with the scheme of sylius.unsecured_urls (http in the test application).
        self::assertEmailHtmlBodyContains($adminEmail, '://localhost/admin/quote-requests/');
        self::assertEmailHtmlBodyNotContains($adminEmail, 'evil.example');
        self::assertEmailHtmlBodyContains($customerEmail, '://localhost/' . $this->localeCode() . '/quotes/');
    }

    public function testSubAddressesAndAnIpv6NetworkShareTheirBudget(): void
    {
        [$variant] = $this->sellableVariants(1);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->addToBasket($variant, 5);
            $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
            $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form([
                'tmonier_quote_request[firstName]' => 'Spam',
                'tmonier_quote_request[lastName]' => 'Bot',
                'tmonier_quote_request[email]' => sprintf('Victim+%d@example.com', $attempt),
            ]);
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], ['REMOTE_ADDR' => sprintf('2001:db8:1:1::%d', $attempt)]);
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSame(3, \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($this->connection()->fetchOne("SELECT COUNT(*) FROM tmonier_quote_request WHERE LOWER(email) LIKE 'victim+%@example.com'")));
    }

    public function testInvalidSubmissionsDoNotCountAndCustomersAreLimitedToo(): void
    {
        $customer = $this->shopCustomer();
        $this->loginShopUser($customer);
        [$variant] = $this->sellableVariants(1);

        // Two invalid submissions (no last name): no budget used.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->addToBasket($variant, 5);
            $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
            $this->client->submit($crawler->filter('[data-test-tmonier-quote-submit]')->form(['tmonier_quote_request[lastName]' => '']));
            self::assertResponseStatusCodeSame(422);
        }
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->addToBasket($variant, 5);
            $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));
            $this->client->submit($crawler->filter('[data-test-tmonier-quote-submit]')->form(['tmonier_quote_request[lastName]' => 'Customer']));
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSame(3, $this->entityManager()->getRepository(QuoteRequest::class)->count(['customer' => $customer, 'lastName' => 'Customer']));
    }

    public function testAGuestConversationHasItsOwnBudget(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5]]);

        for ($message = 1; $message <= 6; ++$message) {
            $crawler = $this->client->request('GET', $this->tokenUrl($quote));
            $this->client->submit($crawler->filter('[data-test-tmonier-quote-send-message]')->form([
                'tmonier_quote_request_message[body]' => 'Message ' . $message,
            ]));
            $crawler = $this->client->followRedirect();
            self::assertSame(['Your message was sent.'], $this->flashes($crawler));
        }
    }

    public function testEmailLinksStayAbsoluteWithoutChannelHostname(): void
    {
        $this->channel()->setHostname(null);
        $this->entityManager()->flush();
        [$variant] = $this->sellableVariants(1);
        $this->addToBasket($variant, 5);
        $crawler = $this->client->request('GET', $this->shopUrl('/quote-request'));

        $this->client->submit($crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[firstName]' => 'Ada',
            'tmonier_quote_request[lastName]' => 'Guest',
            'tmonier_quote_request[email]' => 'ada.links@example.com',
        ]));

        $adminEmail = self::getMailerMessage(0);
        $customerEmail = self::getMailerMessage(1);
        self::assertNotNull($adminEmail);
        self::assertNotNull($customerEmail);
        self::assertEmailHtmlBodyContains($adminEmail, 'href="http://localhost/admin/quote-requests/');
        self::assertEmailHtmlBodyContains($customerEmail, 'href="http://localhost/' . $this->localeCode() . '/quotes/');
    }

    public function testAQuoteRequestedFromTheCartAndPlacedByTheMerchantLeavesTheOtherProductsInTheCart(): void
    {
        $customer = $this->shopCustomer();
        $address = new \Sylius\Component\Core\Model\Address();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setStreet('1 Main Street');
        $address->setCity('New York');
        $address->setPostcode('10001');
        $address->setCountryCode($this->shippableCountry());
        $customer->setDefaultAddress($address);
        [$quoted, $kept] = $this->sellableVariants(2);
        $cart = $this->createCart($customer, [[$quoted, 2], [$kept, 5]]);
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));
        $this->client->submit($crawler->filter('[data-test-tmonier-request-quote-from-cart-button]')->form());
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('[data-test-tmonier-quote-submit]')->form([
            'tmonier_quote_request[firstName]' => 'Ada',
            'tmonier_quote_request[lastName]' => 'Buyer',
        ]);
        $quantities = $form->getValues();
        foreach (array_keys($quantities) as $field) {
            if (str_ends_with($field, '[quantity]') && $crawler->filter(sprintf('[data-test-tmonier-quote-line="%s"] input[name="%s"]', $kept->getCode(), $field))->count() > 0) {
                $form[$field] = '0';
            }
        }
        $this->client->submit($form);
        $quote = $this->entityManager()->getRepository(QuoteRequest::class)->findOneBy(['customer' => $customer], ['id' => 'DESC']);
        self::assertInstanceOf(QuoteRequestInterface::class, $quote);
        self::assertSame($cart->getId(), $quote->getSourceOrder()?->getId());
        self::assertCount(1, $quote->getLines());

        // Proposed, then placed by the merchant (PROPOSED -> convert).
        $this->quoteManager()->apply($quote, 'propose');
        $this->loginAdmin();
        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());
        self::assertSame('ordered', $this->reloadQuote($quote)->getStatus());

        $cart = $this->entityManager()->find(OrderInterface::class, $this->idOf($cart));
        self::assertInstanceOf(OrderInterface::class, $cart);
        $variants = array_map(static fn (OrderItemInterface $item): ?string => $item->getVariant()?->getCode(), array_values($cart->getItems()->toArray()));
        self::assertSame([$kept->getCode()], $variants, 'The quoted product left the cart, the other one stayed.');
    }

    private function addToBasket(ProductVariantInterface $variant, int $quantity): void
    {
        $product = $variant->getProduct();
        self::assertNotNull($product);
        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));
        $form = $crawler->filter('[data-test-tmonier-add-to-quote-button]')->form();
        $form['tmonier_quote_request_add[variant]'] = (string) $this->idOf($variant);
        $form['tmonier_quote_request_add[quantity]'] = (string) $quantity;
        $this->client->submit($form);
    }

    /** @param list<array{0: ProductVariantInterface, 1: int}> $lines */
    private function createCart(CustomerInterface $customer, array $lines): OrderInterface
    {
        $container = self::getContainer();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $container->get('sylius.factory.order');
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $container->get('sylius.factory.order_item');
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $container->get('sylius.modifier.order_item_quantity');
        /** @var OrderProcessorInterface $processor */
        $processor = $container->get('sylius.order_processing.order_processor');

        $cart = $orderFactory->createNew();
        $cart->setChannel($this->channel());
        $cart->setLocaleCode($this->localeCode());
        $cart->setCurrencyCode((string) $this->channel()->getBaseCurrency()?->getCode());
        $cart->setCustomerWithAuthorization($customer);
        foreach ($lines as [$variant, $quantity]) {
            $item = $itemFactory->createNew();
            $item->setVariant($variant);
            $quantityModifier->modify($item, $quantity);
            $cart->addItem($item);
        }
        $processor->process($cart);
        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        return $cart;
    }
}
