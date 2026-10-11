<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * Tests run against the test application loaded with the Sylius fixtures. The kernel is not
 * rebooted between requests and every test runs in a transaction rolled back at the end.
 * Fixtures are random: each test creates the quotes, carts and customers it needs.
 */
trait QuoteRequestTestTrait
{
    private KernelBrowser $client;

    private function setUpClient(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
        // Rate limiter state of the previous tests.
        $pool = self::getContainer()->get('cache.tmonier_quote_request_limiter');
        self::assertInstanceOf(\Psr\Cache\CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    private function tearDownClient(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function loginAdmin(string $username = 'sylius'): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    private function loginShopUser(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');
    }

    /** A customer with an enabled shop account, other than `$except`. */
    private function shopCustomer(?CustomerInterface $except = null): CustomerInterface
    {
        $queryBuilder = $this->entityManager()->createQueryBuilder()
            ->select('c')
            ->from(CustomerInterface::class, 'c')
            ->innerJoin('c.user', 'u')
            ->andWhere('u.enabled = true')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
        ;
        if (null !== $except) {
            $queryBuilder->andWhere('c != :except')->setParameter('except', $except);
        }

        $customer = $queryBuilder->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        if (!$channel instanceof ChannelInterface) {
            $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['enabled' => true]);
        }
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function localeCode(): string
    {
        return (string) $this->channel()->getDefaultLocale()?->getCode();
    }

    /**
     * Enabled variants of enabled products of the channel, with a price, not tracked (no stock
     * check at checkout).
     *
     * @return list<ProductVariantInterface>
     */
    private function sellableVariants(int $count = 2): array
    {
        $channel = $this->channel();
        /** @var list<ProductVariantInterface> $candidates */
        $candidates = $this->entityManager()->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.channels', 'channel')
            ->innerJoin('v.channelPricings', 'pricing')
            ->andWhere('v.enabled = true')
            ->andWhere('p.enabled = true')
            ->andWhere('v.tracked = false')
            ->andWhere('channel = :channel')
            ->andWhere('pricing.channelCode = :channelCode')
            ->andWhere('pricing.price > 1000')
            ->setParameter('channel', $channel)
            ->setParameter('channelCode', $channel->getCode())
            ->orderBy('v.id', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult()
        ;

        $variants = [];
        $products = [];
        foreach ($candidates as $variant) {
            $productId = $variant->getProduct()?->getId();
            if (in_array($productId, $products, true)) {
                continue;
            }
            $products[] = $productId;
            $variants[] = $variant;
            if (count($variants) === $count) {
                return $variants;
            }
        }

        self::markTestSkipped('The fixtures contain not enough sellable untracked variants.');
    }

    /**
     * @param array<int, array{0: ProductVariantInterface, 1: int, 2?: int|null}> $lines variant, quantity, negotiated price
     */
    private function createQuote(array $lines, ?CustomerInterface $customer = null, string $status = QuoteRequestStatuses::NEW, ?float $discountPercentage = null): QuoteRequestInterface
    {
        $quote = $this->quoteFactory()->createForChannel($this->channel(), $this->localeCode(), $customer);
        if (null === $customer) {
            $quote->setEmail(sprintf('guest-%s@example.com', bin2hex(random_bytes(4))));
            $quote->setFirstName('Grace');
            $quote->setLastName('Guest');
        }
        $quote->setCompany('Test Company');
        foreach ($lines as $line) {
            $quoteLine = $this->quoteFactory()->createLine($quote, $line[0], $line[1]);
            self::assertNotNull($quoteLine);
            $quoteLine->setUnitPrice($line[2] ?? null);
            $quote->addLine($quoteLine);
        }
        $quote->setDiscountPercentage($discountPercentage);

        $this->quoteManager()->submit($quote, $this->quoteFactory()->createMessage(QuoteMessageInterface::AUTHOR_CUSTOMER, $quote->getFullName(), 'Please quote these products.'));

        if (in_array($status, [QuoteRequestStatuses::IN_REVIEW, QuoteRequestStatuses::PROPOSED, QuoteRequestStatuses::ACCEPTED], true)) {
            $this->quoteManager()->apply($quote, QuoteRequestTransitions::TRANSITION_REVIEW);
        }
        if (in_array($status, [QuoteRequestStatuses::PROPOSED, QuoteRequestStatuses::ACCEPTED], true)) {
            $this->quoteManager()->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);
        }
        if (QuoteRequestStatuses::ACCEPTED === $status) {
            $this->quoteManager()->accept($quote, $customer, false);
        }

        return $quote;
    }

    private function reloadQuote(QuoteRequestInterface $quote): QuoteRequestInterface
    {
        $id = $quote->getId();
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(QuoteRequest::class, $id);
        self::assertInstanceOf(QuoteRequestInterface::class, $reloaded);

        return $reloaded;
    }

    private function tokenUrl(QuoteRequestInterface $quote, string $suffix = ''): string
    {
        return sprintf('/%s/quotes/%s%s', $this->localeCode(), (string) $quote->getToken(), $suffix);
    }

    private function shopUrl(string $path): string
    {
        return sprintf('/%s%s', $this->localeCode(), $path);
    }

    private function idOf(ResourceInterface $resource): int
    {
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }

    /** @return list<string> */
    private function flashes(Crawler $crawler): array
    {
        /** @var list<string> $flashes */
        $flashes = $crawler->filter('[data-test-flash-messages], [data-test-sylius-flash-message] .alert-description')->each(static fn (Crawler $node): string => trim($node->text()));

        return $flashes;
    }

    private function quoteFactory(): QuoteRequestFactoryInterface
    {
        /** @var QuoteRequestFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_quote_request.factory');

        return $factory;
    }

    private function quoteManager(): QuoteRequestManagerInterface
    {
        /** @var QuoteRequestManagerInterface $manager */
        $manager = self::getContainer()->get('tmonier_quote_request.manager');

        return $manager;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
