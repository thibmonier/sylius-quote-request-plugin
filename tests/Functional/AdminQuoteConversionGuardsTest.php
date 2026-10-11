<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
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
        $usMethods = array_filter($this->shippingMethods(), static fn (ShippingMethodInterface $method): bool => 'US' === $method->getZone()?->getCode());
        $worldMethods = array_filter($this->shippingMethods(), static fn (ShippingMethodInterface $method): bool => 'WORLD' === $method->getZone()?->getCode());
        if (1 !== count($usMethods) || [] === $worldMethods) {
            self::markTestSkipped('Needs a single US shipping method and a WORLD one.');
        }

        $crawler = $this->client->request('GET', sprintf('/admin/quote-requests/%d/convert', $this->idOf($quote)));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-convert-submit]')->form([
            'tmonier_quote_request_admin_convert[address][street]' => '1 Main Street',
            'tmonier_quote_request_admin_convert[address][city]' => 'New York',
            'tmonier_quote_request_admin_convert[address][postcode]' => '10001',
            'tmonier_quote_request_admin_convert[address][countryCode]' => 'US',
            'tmonier_quote_request_admin_convert[shippingMethod]' => (string) array_values($worldMethods)[0]->getCode(),
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

    /** @return list<ShippingMethodInterface> */
    private function shippingMethods(): array
    {
        /** @var ShippingMethodRepositoryInterface<ShippingMethodInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.shipping_method');

        /** @var list<ShippingMethodInterface> $methods */
        $methods = $repository->findEnabledForChannel($this->channel());

        return $methods;
    }
}
