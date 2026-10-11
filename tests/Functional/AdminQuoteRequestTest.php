<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class AdminQuoteRequestTest extends WebTestCase
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

    public function testTheGridListsAndFiltersTheQuotes(): void
    {
        [$variant] = $this->sellableVariants(1);
        $new = $this->createQuote([[$variant, 10]]);
        $proposed = $this->createQuote([[$variant, 20, 1000]], null, QuoteRequestStatuses::PROPOSED);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/quote-requests/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $new->getNumber())));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $proposed->getNumber())));
        self::assertStringContainsString('Quote requests', $crawler->filter('title')->text());

        $crawler = $this->client->request('GET', '/admin/quote-requests/?criteria[status]=proposed&limit=50');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $new->getNumber())));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $proposed->getNumber())));

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/?criteria[customer][type]=contains&criteria[customer][value]=%s', urlencode((string) $new->getEmail())));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $new->getNumber())));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-quote-number="%s"]', $proposed->getNumber())));
    }

    public function testTheMerchantEditsTheLinesAndSendsTheProposal(): void
    {
        $customer = $this->shopCustomer();
        [$first, $second, $third] = $this->sellableVariants(3);
        $quote = $this->createQuote([[$first, 10], [$second, 5]], $customer);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-test-tmonier-quote-line]'));

        $form = $crawler->filter('#tmonier_quote_request_admin_propose')->form([
            'tmonier_quote_request_admin[lines][0][quantity]' => '12',
            'tmonier_quote_request_admin[lines][0][unitPrice]' => '15.50',
            'tmonier_quote_request_admin[lines][1][remove]' => '1',
            'tmonier_quote_request_admin[discountPercentage]' => '10',
            'tmonier_quote_request_admin[validUntil]' => (new \DateTimeImmutable('+15 days'))->format('Y-m-d'),
            'tmonier_quote_request_admin[internalNote]' => 'Margin OK',
            'tmonier_quote_request_admin[message]' => 'Here is our best offer.',
        ]);
        // The autocomplete field is filled by JavaScript in the browser.
        $values = $form->getPhpValues();
        $fields = $values['tmonier_quote_request_admin'] ?? null;
        self::assertIsArray($fields);
        $fields['newVariant'] = (string) $this->idOf($third);
        $fields['newQuantity'] = '7';
        $fields['propose'] = '';
        $this->client->request('POST', $form->getUri(), ['tmonier_quote_request_admin' => $fields]);

        self::assertResponseRedirects(sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', (string) $customer->getEmail());
        self::assertEmailHtmlBodyContains($email, 'Here is our best offer.');
        self::assertEmailAttachmentCount($email, 1);

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::PROPOSED, $quote->getStatus());
        self::assertSame('Margin OK', $quote->getInternalNote());
        self::assertSame(10.0, $quote->getDiscountPercentage());
        self::assertNotNull($quote->getProposedAt());
        $lines = array_values($quote->getLines()->toArray());
        self::assertCount(2, $lines);
        self::assertSame([$first->getCode(), $third->getCode()], array_map(static fn (QuoteRequestLineInterface $line): ?string => $line->getVariantCode(), $lines));
        self::assertSame([12, 7], array_map(static fn (QuoteRequestLineInterface $line): int => $line->getQuantity(), $lines));
        self::assertSame(1550, $lines[0]->getUnitPrice());
        self::assertNull($lines[1]->getUnitPrice());
        self::assertSame(10, $lines[0]->getRequestedQuantity());

        $crawler = $this->client->followRedirect();
        self::assertSame(['The proposal was sent to the customer.'], $this->flashes($crawler));
        // A sent proposal is read-only until it is revised.
        self::assertCount(0, $crawler->filter('#tmonier_quote_request_admin_propose'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-transition="revise"]'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-discount]'));
    }

    public function testTheMerchantCanSaveWithoutSendingAndWriteToTheCustomer(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_save')->form([
            'tmonier_quote_request_admin[lines][0][unitPrice]' => '9.99',
        ]));
        self::assertResponseRedirects();
        self::assertEmailCount(0);
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::NEW, $quote->getStatus());
        self::assertSame(999, $quote->getLines()->first() ? $quote->getLines()->first()->getUnitPrice() : null);

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-send-message]')->form([
            'tmonier_quote_request_message[body]' => 'Which delivery address?',
        ]));
        self::assertEmailCount(1);
        $quote = $this->reloadQuote($quote);
        self::assertSame('admin', $quote->getMessages()->last() ? $quote->getMessages()->last()->getAuthorType() : null);
    }

    public function testTheMerchantAppliesTheWorkflowTransitions(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-transition="expire"]'));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-transition="review"]')->form());
        self::assertSame(QuoteRequestStatuses::IN_REVIEW, $this->reloadQuote($quote)->getStatus());

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-transition="decline"]')->form());
        self::assertSame(QuoteRequestStatuses::DECLINED, $this->reloadQuote($quote)->getStatus());

        $crawler = $this->client->followRedirect();
        self::assertSame(['The quote was declined.'], $this->flashes($crawler));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-transition]'));
    }

    public function testTransitionsRequireAValidCsrfTokenAndAnAdmin(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);

        $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertResponseRedirects('http://localhost/admin/login');

        $this->loginAdmin();
        $this->client->request('POST', sprintf('/admin/quote-requests/%d/transition/decline', $this->idOf($quote)), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/quote-requests/%d/transition/order', $this->idOf($quote)), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(QuoteRequestStatuses::NEW, $this->reloadQuote($quote)->getStatus());
    }

    public function testTheMerchantConvertsAProposalToAnOrder(): void
    {
        $customer = $this->shopCustomer();
        [$first, $second] = $this->sellableVariants(2);
        $quote = $this->createQuote([[$first, 3, 1111], [$second, 2, 2222]], $customer, QuoteRequestStatuses::PROPOSED);
        $shippingMethod = $this->shippingMethodOfZone('US');
        $paymentMethod = $this->firstEnabled($this->paymentMethods());
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $crawler = $this->client->click($crawler->filter('[data-test-tmonier-quote-convert]')->link());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('[data-test-tmonier-quote-convert-submit]')->form([
            'tmonier_quote_request_admin_convert[address][firstName]' => 'Ada',
            'tmonier_quote_request_admin_convert[address][lastName]' => 'Buyer',
            'tmonier_quote_request_admin_convert[address][street]' => '1 Main Street',
            'tmonier_quote_request_admin_convert[address][city]' => 'New York',
            'tmonier_quote_request_admin_convert[address][postcode]' => '10001',
            'tmonier_quote_request_admin_convert[address][countryCode]' => 'US',
            'tmonier_quote_request_admin_convert[shippingMethod]' => (string) $shippingMethod->getCode(),
            'tmonier_quote_request_admin_convert[paymentMethod]' => (string) $paymentMethod->getCode(),
        ]);
        $this->client->submit($form);

        $quote = $this->reloadQuote($quote);
        $order = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $this->idOf($order)));
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertNotNull($order->getNumber());
        self::assertSame($customer->getId(), $order->getCustomer()?->getId());
        self::assertSame([1111, 2222], array_map(static fn (OrderItemInterface $item): int => $item->getUnitPrice(), array_values($order->getItems()->toArray())));
        self::assertSame(3 * 1111 + 2 * 2222, $order->getItemsSubtotal());

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-order]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-convert]'));
    }

    public function testTheMerchantSetsAVolumeDiscountAndAppliesTheGroupSuggestion(): void
    {
        $customer = $this->shopCustomer();
        $group = $this->entityManager()->getRepository(\Sylius\Component\Customer\Model\CustomerGroupInterface::class)->findOneBy(['code' => 'wholesale']);
        self::assertInstanceOf(\Sylius\Component\Customer\Model\CustomerGroupInterface::class, $group);
        $customer->setGroup($group);
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 60, 2000]], $customer);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        self::assertStringContainsString('12.5', $crawler->filter('[data-test-tmonier-quote-group-discount]')->text());

        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_save')->form([
            'tmonier_quote_request_admin[lines][0][discountType]' => 'percentage',
            'tmonier_quote_request_admin[lines][0][discountTiers]' => '10: 5; 50: 10',
        ]));
        self::assertResponseRedirects();
        $line = $this->reloadQuote($quote)->getLines()->first();
        self::assertNotFalse($line);
        self::assertSame([['min_quantity' => 10, 'value' => 5.0], ['min_quantity' => 50, 'value' => 10.0]], $line->getDiscountTiers());
        self::assertSame(1800, $line->getEffectiveUnitPrice());

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_applyGroupDiscount')->form());
        self::assertSame(12.5, $this->reloadQuote($quote)->getDiscountPercentage());

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_save')->form([
            'tmonier_quote_request_admin[lines][0][discountTiers]' => 'ten percent',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheMerchantDownloadsTheQuotePdf(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10, 1000]]);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->click($crawler->filter('[data-test-tmonier-quote-pdf]')->link());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString(sprintf('quote-%s.pdf', (string) $quote->getNumber()), (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $content);
        self::assertStringContainsString(sprintf('Quote %s', (string) $quote->getNumber()), $content);
    }

    public function testTheMerchantConvertsAnAcceptedQuoteInOneClick(): void
    {
        $customer = $this->customerWithDefaultAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 4, 1500]], $customer, QuoteRequestStatuses::ACCEPTED);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $quote = $this->reloadQuote($quote);
        $order = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $this->idOf($order)));
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertSame('awaiting_payment', $order->getPaymentState());
        self::assertSame([1500], array_map(static fn (OrderItemInterface $item): int => $item->getUnitPrice(), array_values($order->getItems()->toArray())));
    }

    public function testOneClickConversionRequiresAValidCsrfToken(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 4, 1500]], $this->shopCustomer(), QuoteRequestStatuses::ACCEPTED);
        $this->loginAdmin();

        $this->client->request('POST', sprintf('/admin/quote-requests/%d/convert-now', $this->idOf($quote)), ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $this->reloadQuote($quote)->getStatus());
    }

    public function testAnIneligibleShippingMethodIsRefusedAndTheQuoteIsUnchanged(): void
    {
        $customer = $this->shopCustomer();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3, 1111]], $customer, QuoteRequestStatuses::PROPOSED);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d/convert', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-submit]')->form([
            'tmonier_quote_request_admin_convert[address][firstName]' => 'Ada',
            'tmonier_quote_request_admin_convert[address][lastName]' => 'Buyer',
            'tmonier_quote_request_admin_convert[address][street]' => '1 Main Street',
            'tmonier_quote_request_admin_convert[address][city]' => 'New York',
            'tmonier_quote_request_admin_convert[address][postcode]' => '10001',
            'tmonier_quote_request_admin_convert[address][countryCode]' => 'US',
            'tmonier_quote_request_admin_convert[shippingMethod]' => (string) $this->shippingMethodOfZone('WORLD')->getCode(),
            'tmonier_quote_request_admin_convert[paymentMethod]' => (string) $this->firstEnabled($this->paymentMethods())->getCode(),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The order could not be placed', (string) $this->client->getResponse()->getContent());
        // Atomic: the quote is not accepted on the customer's behalf, no e-mail, no cart.
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::PROPOSED, $quote->getStatus());
        self::assertNull($quote->getOrder());
        self::assertEmailCount(0);
    }

    public function testAQuoteWithNothingToShipIsConvertedInOneClick(): void
    {
        $customer = $this->customerWithDefaultAddress();
        [$variant] = $this->sellableVariants(1);
        $variant->setShippingRequired(false);
        $this->entityManager()->flush();
        $quote = $this->createQuote([[$variant, 2, 900]], $customer, QuoteRequestStatuses::PROPOSED);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $quote = $this->reloadQuote($quote);
        $order = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $this->idOf($order)));
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        self::assertFalse($order->hasShipments());
        self::assertSame('completed', $order->getCheckoutState());
    }

    public function testAnOutOfStockProductStopsTheConversion(): void
    {
        $customer = $this->customerWithDefaultAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5, 900]], $customer, QuoteRequestStatuses::ACCEPTED);
        $variant->setTracked(true);
        $variant->setOnHand(1);
        $variant->setOnHold(0);
        $this->entityManager()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        self::assertResponseRedirects(sprintf('/admin/quote-requests/%d/convert', $this->idOf($quote)));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The order could not be placed', implode(' ', $this->flashes($crawler)));
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertNotSame('completed', $quote->getOrder()?->getCheckoutState());
    }

    public function testConvertingAnAcceptedQuoteUsesADedicatedCart(): void
    {
        $customer = $this->customerWithDefaultAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3, 1200]], $customer, QuoteRequestStatuses::ACCEPTED);
        $customerCart = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $customerCart);
        $customerCartId = $this->idOf($customerCart);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $quote = $this->reloadQuote($quote);
        $order = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertNotSame($customerCartId, $order->getId());
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        $customerCart = $this->entityManager()->find(OrderInterface::class, $customerCartId);
        self::assertInstanceOf(OrderInterface::class, $customerCart);
        self::assertSame(OrderInterface::STATE_CART, $customerCart->getState());
        self::assertCount(0, $customerCart->getItems(), 'The quote products leave the customer cart.');
    }

    public function testTheValidityDateLastsUntilTheEndOfTheDay(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);
        $this->loginAdmin();
        $date = new \DateTimeImmutable('+5 days');

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_save')->form([
            'tmonier_quote_request_admin[validUntil]' => $date->format('Y-m-d'),
        ]));

        self::assertSame($date->format('Y-m-d') . ' 23:59:59', $this->reloadQuote($quote)->getValidUntil()?->format('Y-m-d H:i:s'));
    }

    /** @return list<ShippingMethodInterface> */
    private function shippingMethods(): array
    {
        /** @var ShippingMethodRepositoryInterface<ShippingMethodInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.shipping_method');

        /** @var list<ShippingMethodInterface> $methods */
        $methods = $repository->findEnabledForChannel($this->channel());

        return $methods;
    }

    private function customerWithDefaultAddress(): \Sylius\Component\Core\Model\CustomerInterface
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
        $this->entityManager()->flush();

        return $customer;
    }

    private function shippingMethodOfZone(string $zoneCode): ShippingMethodInterface
    {
        foreach ($this->shippingMethods() as $method) {
            if ($zoneCode === $method->getZone()?->getCode()) {
                return $method;
            }
        }

        self::markTestSkipped(sprintf('No shipping method of the "%s" zone in the fixtures.', $zoneCode));
    }

    /** @return list<PaymentMethodInterface> */
    private function paymentMethods(): array
    {
        /** @var PaymentMethodRepositoryInterface<PaymentMethodInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.payment_method');

        /** @var list<PaymentMethodInterface> $methods */
        $methods = $repository->findEnabledForChannel($this->channel());

        return $methods;
    }

    /**
     * @template T of object
     *
     * @param list<T> $methods
     *
     * @return T
     */
    private function firstEnabled(array $methods): object
    {
        if ([] === $methods) {
            self::markTestSkipped('No enabled method in the channel.');
        }

        return $methods[0];
    }
}
