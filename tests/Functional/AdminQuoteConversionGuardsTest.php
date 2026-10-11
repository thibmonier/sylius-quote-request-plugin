<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

/**
 * Merchant conversions that must be refused, atomically.
 */
final class AdminQuoteConversionGuardsTest extends WebTestCase
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

    /** @return iterable<string, array{string}> */
    public static function noLongerSold(): iterable
    {
        yield 'product disabled' => ['product'];
        yield 'variant disabled' => ['variant'];
        yield 'product removed from the channel' => ['channel'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noLongerSold')]
    public function testAnAcceptedQuoteWithAProductNoLongerSoldIsNotConverted(string $change): void
    {
        $customer = $this->customerWithAddress();
        [$first, $second] = $this->sellableVariants(2);
        $quote = $this->createQuote([[$first, 2, 1000], [$second, 1, 2000]], $customer, QuoteRequestStatuses::ACCEPTED);
        $product = $second->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        match ($change) {
            'product' => $product->setEnabled(false),
            'variant' => $second->setEnabled(false),
            default => $product->removeChannel($this->channel()),
        };
        $this->entityManager()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('can no longer be ordered', implode(' ', $this->flashes($crawler)));
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertSame(0, \Tests\Tmonier\SyliusQuoteRequestPlugin\DbValue::int($this->connection()->fetchOne("SELECT COUNT(*) FROM sylius_order WHERE customer_id = ? AND checkout_state = 'completed' AND checkout_completed_at > ?", [$customer->getId(), (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')])));
    }

    public function testTheChosenShippingMethodIsCheckedWhenSyliusSkipsTheShippingStep(): void
    {
        $channel = $this->channel();
        $channel->setSkippingShippingStepAllowed(true);
        $customer = $this->customerWithAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 2, 1000]], $customer, QuoteRequestStatuses::ACCEPTED);
        $this->loginAdmin();
        // A single method eligible for the address (shipping step skipped by Sylius) and the
        // merchant chooses another one.
        $usMethod = $this->catalog()->shippingMethod($channel, ['US']);
        $frMethod = $this->catalog()->shippingMethod($channel, ['FR']);
        $this->catalog()->disableShippingMethodsBut($channel, $usMethod, $frMethod);
        $this->entityManager()->flush();
        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d/convert', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-submit]')->form([
            'tmonier_quote_request_admin_convert[address][street]' => '1 Main Street',
            'tmonier_quote_request_admin_convert[address][city]' => 'New York',
            'tmonier_quote_request_admin_convert[address][postcode]' => '10001',
            'tmonier_quote_request_admin_convert[address][countryCode]' => 'US',
            'tmonier_quote_request_admin_convert[shippingMethod]' => (string) $frMethod->getCode(),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('is not available for this address', (string) $this->client->getResponse()->getContent());
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $this->reloadQuote($quote)->getStatus());
    }

    public function testTheCustomerCartIsEmptiedByTheConversion(): void
    {
        $customer = $this->customerWithAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3, 1200]], $customer, QuoteRequestStatuses::ACCEPTED);
        $customerCartId = $this->idOf($this->orderOf($quote));
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        $customerCart = $this->entityManager()->find(OrderInterface::class, $customerCartId);
        self::assertInstanceOf(OrderInterface::class, $customerCart);
        self::assertCount(0, $customerCart->getItems(), 'No duplicate order at catalog prices.');
    }

    public function testACustomerAlreadyInTheCheckoutBlocksTheConversion(): void
    {
        $customer = $this->customerWithAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3, 1200]], $customer, QuoteRequestStatuses::ACCEPTED);
        $cart = $this->orderOf($quote);
        $cart->setCheckoutState(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $this->entityManager()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The customer is checking out this quote', implode(' ', $this->flashes($crawler)));
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertSame($this->idOf($cart), $this->idOf($this->orderOf($quote)));
        self::assertCount(1, $this->orderOf($quote)->getItems());
    }

    public function testAnAbandonedCheckoutNoLongerBlocksTheConversion(): void
    {
        $customer = $this->customerWithAddress();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3, 1200]], $customer, QuoteRequestStatuses::ACCEPTED);
        $cart = $this->orderOf($quote);
        $cart->setCheckoutState(OrderCheckoutStates::STATE_ADDRESSED);
        $this->entityManager()->flush();
        // Left 2 hours ago (checkout_idle_minutes: 30).
        $this->connection()->executeStatement('UPDATE sylius_order SET updated_at = ? WHERE id = ?', [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $cart->getId()]);
        $this->entityManager()->refresh($cart);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-now]')->form());

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
        $abandoned = $this->entityManager()->find(OrderInterface::class, $this->idOf($cart));
        self::assertInstanceOf(OrderInterface::class, $abandoned);
        self::assertCount(0, $abandoned->getItems());
    }

    public function testARefusedProposalKeepsTheMerchantEdits(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d', $this->idOf($quote)));
        $this->client->submit($crawler->filter('#tmonier_quote_request_admin_propose')->form([
            'tmonier_quote_request_admin[lines][0][remove]' => '1',
            'tmonier_quote_request_admin[internalNote]' => 'Note typed by the merchant',
            'tmonier_quote_request_admin[discountPercentage]' => '7',
        ]));

        $crawler = $this->client->followRedirect();
        self::assertSame(['The proposal needs at least one product that can be ordered.'], $this->flashes($crawler));
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::NEW, $quote->getStatus());
        self::assertSame('Note typed by the merchant', $quote->getInternalNote());
        self::assertSame(7.0, $quote->getDiscountPercentage());
    }

    private function orderOf(\Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface $quote): OrderInterface
    {
        $order = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);

        return $order;
    }

    private function customerWithAddress(): CustomerInterface
    {
        $customer = $this->shopCustomer();
        $address = new Address();
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
}
