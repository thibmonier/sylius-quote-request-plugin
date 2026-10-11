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

    /** @var array<string, string> content of the attachments at sending time, by path */
    private array $attachments = [];

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

    public function testTheProposalHasThePdfAttachedWhenAnEngineIsAvailable(): void
    {
        $quote = $this->quote();
        $quote->setNumber('Q000042');

        $this->mailer(null, true)->onProposed(new QuoteRequestEvent($quote));

        self::assertSame([[QuoteRequestEmails::CUSTOMER_PROPOSAL, ['buyer@example.com'], []]], $this->sent);
        self::assertCount(1, $this->attachments);
        $path = (string) array_key_first($this->attachments);
        self::assertStringEndsWith('/quote-Q000042.pdf', $path);
        self::assertSame('%PDF-fake Q000042', $this->attachments[$path]);
        self::assertFileDoesNotExist($path, 'The temporary file is removed once sent.');
    }

    private function mailer(?string $adminEmail, bool $pdf = false): QuoteRequestMailer
    {
        $record = function (string $code, array $recipients, array $replyTo, array $attachments): void {
            /** @var list<string> $recipients */
            /** @var list<string> $replyTo */
            $this->sent[] = [$code, $recipients, $replyTo];
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    $this->attachments[$attachment] = (string) file_get_contents($attachment);
                }
            }
        };
        $sender = new class($record) implements SenderInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function send(string $code, array $recipients, array $data = [], array $attachments = [], array $replyTo = []): void
            {
                ($this->record)($code, array_values($recipients), array_values($replyTo), $attachments);
            }
        };

        return new QuoteRequestMailer($sender, new FakePdfGenerator($pdf), $adminEmail);
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
