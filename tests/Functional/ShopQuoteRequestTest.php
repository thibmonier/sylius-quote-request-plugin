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
        $form['tmonier_quote_request_add[variant]']->setValue((string) $variant->getId());
        $form['tmonier_quote_request_add[quantity]']->setValue('40');
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
        self::assertSame($customer->getFirstName() ?? '', $form['tmonier_quote_request[firstName]']->getValue());
        self::assertTrue($crawler->filter('#tmonier_quote_request_email')->getNode(0)?->hasAttribute('disabled'));

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

        $this->client->request('POST', $this->shopUrl(sprintf('/quote-request/products/%d', (int) $product->getId())), [
            'tmonier_quote_request_add' => ['variant' => (string) $variant->getId(), 'quantity' => '5', '_token' => 'forged'],
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

    private function addToBasket(ProductVariantInterface $variant, int $quantity): void
    {
        $product = $variant->getProduct();
        self::assertNotNull($product);
        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));
        $form = $crawler->filter('[data-test-tmonier-add-to-quote-button]')->form();
        $form['tmonier_quote_request_add[variant]']->setValue((string) $variant->getId());
        $form['tmonier_quote_request_add[quantity]']->setValue((string) $quantity);
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
