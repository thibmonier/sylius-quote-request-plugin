<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class ShopQuoteSecurityTest extends WebTestCase
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

    public function testTheTokenOfAnAccountQuoteNeedsItsOwner(): void
    {
        $owner = $this->shopCustomer();
        $other = $this->shopCustomer($owner);
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5, 1000]], $owner, QuoteRequestStatuses::PROPOSED);

        $this->client->request('GET', $this->tokenUrl($quote));
        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', $this->tokenUrl($quote, '/accept'), ['_csrf_token' => 'whatever']);
        self::assertResponseRedirects();
        self::assertSame(QuoteRequestStatuses::PROPOSED, $this->reloadQuote($quote)->getStatus());

        $this->loginShopUser($other);
        $this->client->request('GET', $this->tokenUrl($quote));
        self::assertResponseStatusCodeSame(403);

        $this->loginShopUser($owner);
        $this->client->request('GET', $this->tokenUrl($quote));
        self::assertResponseIsSuccessful();
    }

    public function testATokenIsCaseSensitive(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5]]);
        $token = (string) $quote->getToken();
        self::assertMatchesRegularExpression('/[a-zA-Z]/', $token);
        $swapped = preg_replace_callback('/[a-zA-Z]/', static fn (array $match): string => ctype_upper($match[0]) ? strtolower($match[0]) : strtoupper($match[0]), $token, 1);
        self::assertIsString($swapped);
        self::assertSame(strlen($token), strlen($swapped));

        $this->client->request('GET', $this->shopUrl('/quotes/' . $swapped));

        self::assertResponseStatusCodeSame(404);
    }

    public function testACustomerCannotDeclineARequestThatWasNotProposed(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5, 1000]], null, QuoteRequestStatuses::PROPOSED);
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $form = $crawler->filter('[data-test-tmonier-quote-decline]')->form();

        $quote = $this->reloadQuote($quote);
        $this->quoteManager()->apply($quote, QuoteRequestTransitions::TRANSITION_REVISE);
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertSame(['This action is not possible in the current status of the quote.'], $this->flashes($crawler));
        self::assertSame(QuoteRequestStatuses::IN_REVIEW, $this->reloadQuote($quote)->getStatus());
    }

    public function testTheQuoteCartIsNotHandedToSomebodyElse(): void
    {
        $customer = $this->shopCustomer();
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5, 1000]], null, QuoteRequestStatuses::PROPOSED);
        $this->loginShopUser($customer);
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-accept]')->form());
        $cartId = $this->reloadQuote($quote)->getOrder()?->getId();
        self::assertNotNull($cartId);

        // Somebody else with the forwarded link, not logged in.
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $this->client->submit($crawler->filter('[data-test-tmonier-quote-checkout]')->form());

        $crawler = $this->client->followRedirect();
        self::assertSame(['The cart of this quote belongs to another customer.'], $this->flashes($crawler));
        $quote = $this->reloadQuote($quote);
        self::assertSame($cartId, $quote->getOrder()?->getId());
        $order = $quote->getOrder();
        self::assertSame($customer->getId(), $order->getCustomer()?->getId());
    }

    public function testAProposalWithAProductNoLongerSoldCannotBeAccepted(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 5, 1000]], null, QuoteRequestStatuses::PROPOSED);
        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        $form = $crawler->filter('[data-test-tmonier-quote-accept]')->form();

        $product = $variant->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        $product->setEnabled(false);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', $this->tokenUrl($quote));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-quote-unavailable]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-quote-accept]'));

        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSame(['This proposal can no longer be accepted.'], $this->flashes($crawler));
        self::assertSame(QuoteRequestStatuses::PROPOSED, $this->reloadQuote($quote)->getStatus());
    }
}
