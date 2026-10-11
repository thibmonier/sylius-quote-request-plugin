<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessage;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLine;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class QuoteRequestTest extends TestCase
{
    public function testANewQuoteIsNewAndValidWithoutDate(): void
    {
        $quote = new QuoteRequest();

        self::assertSame(QuoteRequestStatuses::NEW, $quote->getStatus());
        self::assertTrue($quote->isValidAt(new \DateTimeImmutable('+10 years')));
    }

    public function testValidityIncludesTheLastSecond(): void
    {
        $quote = new QuoteRequest();
        $quote->setValidUntil(new \DateTimeImmutable('2026-10-31 23:59:59'));

        self::assertTrue($quote->isValidAt(new \DateTimeImmutable('2026-10-31 23:59:59')));
        self::assertFalse($quote->isValidAt(new \DateTimeImmutable('2026-11-01 00:00:00')));
    }

    public function testTheNegotiatedPriceReplacesTheCatalogPrice(): void
    {
        $line = new QuoteRequestLine();
        $line->setCatalogUnitPrice(1000);
        $line->setQuantity(3);

        self::assertSame(1000, $line->getEffectiveUnitPrice());
        self::assertSame(3000, $line->getSubtotal());

        $line->setUnitPrice(850);
        self::assertSame(850, $line->getEffectiveUnitPrice());
        self::assertSame(2550, $line->getSubtotal());

        $line->setUnitPrice(0);
        self::assertSame(0, $line->getSubtotal());
    }

    public function testLinesAndMessagesKeepTheirQuote(): void
    {
        $quote = new QuoteRequest();
        $line = new QuoteRequestLine();
        $message = new QuoteMessage();
        $message->setAuthorType(QuoteMessageInterface::AUTHOR_ADMIN);

        $quote->addLine($line);
        $quote->addLine($line);
        $quote->addMessage($message);

        self::assertCount(1, $quote->getLines());
        self::assertSame($quote, $line->getQuoteRequest());
        self::assertSame($quote, $message->getQuoteRequest());
        self::assertFalse($message->isFromCustomer());

        $quote->removeLine($line);
        $quote->removeMessage($message);
        self::assertNull($line->getQuoteRequest());
        self::assertNull($message->getQuoteRequest());
        self::assertCount(0, $quote->getMessages());
    }

    public function testFullName(): void
    {
        $quote = new QuoteRequest();
        self::assertSame('', $quote->getFullName());

        $quote->setFirstName('Ada');
        $quote->setLastName('Lovelace');
        self::assertSame('Ada Lovelace', $quote->getFullName());
    }

    public function testTheVolumeDiscountOfTheReachedTierApplies(): void
    {
        $line = new QuoteRequestLine();
        $line->setCatalogUnitPrice(2000);
        $line->setUnitPrice(1800);
        $line->setDiscountTiers([['min_quantity' => 50, 'value' => 10.0], ['min_quantity' => 10, 'value' => 5.0]]);
        $line->setQuantity(30);

        self::assertNull($line->getApplicableDiscountTier(), 'No discount type: tiers are ignored.');
        self::assertSame(1800, $line->getEffectiveUnitPrice());

        $line->setDiscountType(QuoteRequestLine::DISCOUNT_PERCENTAGE);
        self::assertSame(['min_quantity' => 10, 'value' => 5.0], $line->getApplicableDiscountTier());
        self::assertSame(90, $line->getUnitDiscount());
        self::assertSame(1710, $line->getEffectiveUnitPrice());
        self::assertSame(1800, $line->getBaseUnitPrice());
        self::assertSame(30 * 1710, $line->getSubtotal());

        $line->setQuantity(50);
        self::assertSame(1620, $line->getEffectiveUnitPrice());

        $line->setQuantity(5);
        self::assertSame(1800, $line->getEffectiveUnitPrice());
    }

    public function testAnAmountVolumeDiscountIsPerUnitAndNeverNegative(): void
    {
        $line = new QuoteRequestLine();
        $line->setCatalogUnitPrice(1000);
        $line->setQuantity(20);
        $line->setDiscountType(QuoteRequestLine::DISCOUNT_AMOUNT);
        $line->setDiscountTiers([['min_quantity' => 1, 'value' => 1.5]]);

        self::assertSame(150, $line->getUnitDiscount());
        self::assertSame(850, $line->getEffectiveUnitPrice());

        $line->setDiscountTiers([['min_quantity' => 1, 'value' => 25.0]]);
        self::assertSame(0, $line->getEffectiveUnitPrice());
    }
}
