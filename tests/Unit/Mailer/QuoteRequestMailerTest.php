<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessage;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Mailer\QuoteRequestEmails;
use Tmonier\SyliusQuoteRequestPlugin\Mailer\QuoteRequestMailer;

final class QuoteRequestMailerTest extends TestCase
{
    /** @var list<array{string, list<string>, list<string>}> */
    private array $sent = [];

    public function testANewRequestNotifiesTheConfiguredAdminAndTheCustomer(): void
    {
        $this->mailer('sales@example.com')->onCreated(new QuoteRequestEvent($this->quote()));

        self::assertSame([
            [QuoteRequestEmails::ADMIN_NEW_REQUEST, ['sales@example.com'], ['buyer@example.com']],
            [QuoteRequestEmails::CUSTOMER_REQUEST_RECEIVED, ['buyer@example.com'], []],
        ], $this->sent);
    }

    public function testTheChannelContactEmailIsTheDefaultAdminRecipient(): void
    {
        $this->mailer(null)->onAccepted(new QuoteRequestEvent($this->quote()));

        self::assertSame([[QuoteRequestEmails::ADMIN_ACCEPTED, ['contact@example.com'], ['buyer@example.com']]], $this->sent);
    }

    public function testNoAdminEmailWithoutRecipient(): void
    {
        $quote = $this->quote();
        $quote->getChannel()?->setContactEmail(null);

        $this->mailer(null)->onAccepted(new QuoteRequestEvent($quote));

        self::assertSame([], $this->sent);
    }

    public function testTheProposalGoesToTheCustomer(): void
    {
        $this->mailer(null)->onProposed(new QuoteRequestEvent($this->quote()));

        self::assertSame([[QuoteRequestEmails::CUSTOMER_PROPOSAL, ['buyer@example.com'], []]], $this->sent);
    }

    public function testAMessageGoesToTheOtherParty(): void
    {
        $quote = $this->quote();
        $message = new QuoteMessage();
        $message->setAuthorType(QuoteMessageInterface::AUTHOR_CUSTOMER);
        $quote->addMessage($message);
        $this->mailer(null)->onMessagePosted(new QuoteRequestEvent($quote));

        $answer = new QuoteMessage();
        $answer->setAuthorType(QuoteMessageInterface::AUTHOR_ADMIN);
        $quote->addMessage($answer);
        $this->mailer(null)->onMessagePosted(new QuoteRequestEvent($quote));

        self::assertSame([
            [QuoteRequestEmails::ADMIN_CUSTOMER_MESSAGE, ['contact@example.com'], ['buyer@example.com']],
            [QuoteRequestEmails::CUSTOMER_MERCHANT_MESSAGE, ['buyer@example.com'], []],
        ], $this->sent);
    }

    private function mailer(?string $adminEmail): QuoteRequestMailer
    {
        $sender = new class($this->sent) implements SenderInterface {
            /** @param list<array{string, list<string>, list<string>}> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function send(string $code, array $recipients, array $data = [], array $attachments = [], array $replyTo = []): void
            {
                /** @var list<string> $recipients */
                /** @var list<string> $replyTo */
                $this->sent[] = [$code, array_values($recipients), array_values($replyTo)];
            }
        };

        return new QuoteRequestMailer($sender, $adminEmail);
    }

    private function quote(): QuoteRequest
    {
        $channel = new Channel();
        $channel->setContactEmail('contact@example.com');
        $quote = new QuoteRequest();
        $quote->setChannel($channel);
        $quote->setEmail('buyer@example.com');

        return $quote;
    }
}
