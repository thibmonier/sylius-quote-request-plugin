<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class ShopQuoteAccountTest extends WebTestCase
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

    public function testTheCustomerSeesOnlyTheirQuotes(): void
    {
        $customer = $this->shopCustomer();
        $other = $this->shopCustomer($customer);
        [$variant] = $this->sellableVariants(1);
        $own = $this->createQuote([[$variant, 10]], $customer);
        $foreign = $this->createQuote([[$variant, 20]], $other);
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/account/quotes'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-quote-row="%s"]', $own->getNumber())));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-quote-row="%s"]', $foreign->getNumber())));

        $this->client->request('GET', $this->shopUrl('/account/quotes/' . $own->getNumber()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->shopUrl('/account/quotes/' . $foreign->getNumber()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAccountPagesNeedALogin(): void
    {
        $this->client->request('GET', $this->shopUrl('/account/quotes'));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAGuestNeedsTheExactToken(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10]]);
        $token = (string) $quote->getToken();

        $this->client->request('GET', $this->tokenUrl($quote));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->shopUrl('/quotes/' . strtoupper($token) . 'x'));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->shopUrl('/quotes/' . str_repeat('a', 43)));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->shopUrl('/quotes/' . $quote->getNumber()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPricesAreShownOnlyOnceProposed(): void
    {
        [$variant] = $this->sellableVariants(1);
        $catalogPrice = $this->quoteFactory()->createLine($this->quoteFactory()->createForChannel($this->channel(), $this->localeCode()), $variant, 1)?->getCatalogUnitPrice();
        self::assertNotNull($catalogPrice);
        $negotiated = intdiv($catalogPrice, 2);
        $quote = $this->createQuote([[$variant, 10, $negotiated]], null, QuoteRequestStatuses::IN_REVIEW);

        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-line-price]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-accept]'));

        $this->quoteManager()->apply($quote, 'propose');
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-line-price]'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-accept]'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-valid-until]'));
    }

    public function testAGuestAcceptsTheProposalAndGetsACartWithTheNegotiatedPrices(): void
    {
        [$first, $second] = $this->sellableVariants(2);
        $quote = $this->createQuote([[$first, 3, 1234], [$second, 2, 2500]], null, QuoteRequestStatuses::PROPOSED);

        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-accept]')->form());

        self::assertResponseRedirects($this->shopUrl('/cart/'));
        self::assertEmailCount(1); // to the merchant
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-cart-notice]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-request-quote-from-cart]'));

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        $cart = $quote->getOrder();
        self::assertInstanceOf(OrderInterface::class, $cart);
        self::assertSame(OrderInterface::STATE_CART, $cart->getState());
        self::assertSame([1234, 2500], array_map(static fn (OrderItemInterface $item): int => $item->getUnitPrice(), array_values($cart->getItems()->toArray())));
        self::assertSame(3 * 1234 + 2 * 2500, $cart->getItemsSubtotal());

        // The checkout starts from this cart.
        $this->client->request('GET', $this->shopUrl('/checkout/address'));
        self::assertResponseIsSuccessful();
    }

    public function testTheCustomerLeavesTheQuotePricesAndComesBackLater(): void
    {
        $customer = $this->shopCustomer();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 4, 999]], $customer, QuoteRequestStatuses::PROPOSED);
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/account/quotes/' . $quote->getNumber()));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-accept]')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-release-cart]')->form());
        self::assertResponseRedirects($this->shopUrl('/cart/'));

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::ACCEPTED, $quote->getStatus());
        self::assertNull($quote->getOrder());

        $crawler = $this->client->request('GET', $this->shopUrl('/account/quotes/' . $quote->getNumber()));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-checkout]')->form());
        self::assertResponseRedirects($this->shopUrl('/cart/'));
        $quote = $this->reloadQuote($quote);
        $item = $quote->getOrder()?->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $item);
        self::assertSame(999, $item->getUnitPrice());
    }

    public function testTheCustomerAsksForChangesWithAMessage(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10, 1000]], null, QuoteRequestStatuses::PROPOSED);

        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $form = $crawler->filter('[data-test-tmonier-quote-request-changes]')->form([
            'tmonier_quote_request_message[body]' => 'Could you do 900 for 20 units?',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects($this->tokenUrl($quote));
        self::assertEmailCount(1);
        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::IN_REVIEW, $quote->getStatus());
        self::assertSame('Could you do 900 for 20 units?', $quote->getMessages()->last() ? $quote->getMessages()->last()->getBody() : null);
    }

    public function testTheCustomerDeclinesTheProposal(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10, 1000]], null, QuoteRequestStatuses::PROPOSED);

        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-decline]')->form());

        self::assertSame(QuoteRequestStatuses::DECLINED, $this->reloadQuote($quote)->getStatus());
    }

    public function testAnExpiredProposalCannotBeAccepted(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10, 1000]], null, QuoteRequestStatuses::PROPOSED);
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $form = $crawler->filter('[data-test-tmonier-quote-accept]')->form();

        $quote->setValidUntil(new \DateTimeImmutable('-1 day'));
        $this->entityManager()->flush();
        $this->client->submit($form);

        self::assertResponseRedirects($this->tokenUrl($quote));
        $crawler = $this->client->followRedirect();
        self::assertSame(['This proposal can no longer be accepted.'], $this->flashes($crawler));
        self::assertSame(QuoteRequestStatuses::PROPOSED, $this->reloadQuote($quote)->getStatus());
    }

    public function testActionsRequireAValidCsrfToken(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 10, 1000]], null, QuoteRequestStatuses::PROPOSED);

        $this->client->request('POST', $this->tokenUrl($quote, '/accept'), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->tokenUrl($quote, '/message'), ['tmonier_quote_request_message' => ['body' => 'Hi', '_token' => 'forged']]);
        self::assertResponseRedirects($this->tokenUrl($quote));

        $quote = $this->reloadQuote($quote);
        self::assertSame(QuoteRequestStatuses::PROPOSED, $quote->getStatus());
        self::assertCount(1, $quote->getMessages());
    }
}
