<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `allow_guests: false`: the test application reads the option from TMONIER_QUOTE_ALLOW_GUESTS,
 * resolved when the kernel of the test is booted.
 */
final class ShopGuestsDisabledTest extends WebTestCase
{
    use QuoteRequestTestTrait;

    protected function setUp(): void
    {
        $_SERVER['TMONIER_QUOTE_ALLOW_GUESTS'] = $_ENV['TMONIER_QUOTE_ALLOW_GUESTS'] = '0';
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        unset($_SERVER['TMONIER_QUOTE_ALLOW_GUESTS'], $_ENV['TMONIER_QUOTE_ALLOW_GUESTS']);
        parent::tearDown();
    }

    public function testAGuestIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', $this->shopUrl('/quote-request'));

        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Log in to request a quote.'], $this->flashes($crawler));
    }

    public function testAGuestCannotPostTheRequestForm(): void
    {
        $this->client->request('POST', $this->shopUrl('/quote-request'), ['tmonier_quote_request' => ['email' => 'guest@example.com']]);

        self::assertResponseRedirects();
        self::assertEmailCount(0);
    }

    public function testALoggedInCustomerCanStillRequestAQuote(): void
    {
        $this->loginShopUser($this->shopCustomer());

        $this->client->request('GET', $this->shopUrl('/quote-request'));

        self::assertResponseIsSuccessful();
    }

    public function testTheTokenLinkStillWorksForExistingGuestQuotes(): void
    {
        [$variant] = $this->sellableVariants(1);
        $quote = $this->createQuote([[$variant, 3]]);

        $this->client->request('GET', $this->tokenUrl($quote));

        self::assertResponseIsSuccessful();
    }
}
