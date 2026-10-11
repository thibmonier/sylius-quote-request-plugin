<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class ExpireQuoteRequestsCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testItExpiresTheProposalsPastTheirValidity(): void
    {
        $past = $this->proposal(new \DateTimeImmutable('-1 hour'));
        $future = $this->proposal(new \DateTimeImmutable('+1 day'));
        $notProposed = $this->quote();
        $notProposed->setValidUntil(new \DateTimeImmutable('-3 days'));
        $this->entityManager()->flush();

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(QuoteRequestStatuses::PROPOSED, $past->getStatus());

        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString((string) $past->getNumber(), $tester->getDisplay());
        self::assertStringNotContainsString((string) $future->getNumber(), $tester->getDisplay());

        self::assertSame(QuoteRequestStatuses::EXPIRED, $past->getStatus());
        self::assertSame(QuoteRequestStatuses::PROPOSED, $future->getStatus());
        self::assertSame(QuoteRequestStatuses::NEW, $notProposed->getStatus());

        // Expired quotes are not proposed any more: a second run changes nothing.
        $tester->execute([]);
        self::assertStringNotContainsString((string) $past->getNumber(), $tester->getDisplay());
    }

    private function proposal(\DateTimeImmutable $validUntil): QuoteRequestInterface
    {
        $quote = $this->quote();
        $quote->setValidUntil(new \DateTimeImmutable('+10 days'));
        $this->manager()->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE);
        $quote->setValidUntil($validUntil);
        $this->entityManager()->flush();

        return $quote;
    }

    private function quote(): QuoteRequestInterface
    {
        /** @var QuoteRequestFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_quote_request.factory');
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $this->entityManager()->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.channelPricings', 'pricing')
            ->andWhere('pricing.channelCode = :channelCode')
            ->setParameter('channelCode', $channel->getCode())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        $quote = $factory->createForChannel($channel, 'en_US');
        $quote->setEmail('expiry@example.com');
        $line = $factory->createLine($quote, $variant, 5);
        self::assertNotNull($line);
        $quote->addLine($line);
        $this->manager()->submit($quote);

        return $quote;
    }

    private function commandTester(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('tmonier:quote-request:expire'));
    }

    private function manager(): QuoteRequestManagerInterface
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
}
