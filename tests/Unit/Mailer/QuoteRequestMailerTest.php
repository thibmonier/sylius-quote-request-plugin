<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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

    private RequestStack $requestStack;

    private string $directory;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->directory = sprintf('%s/tmonier_mailer_test_%s', sys_get_temp_dir(), bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($this->directory);
        }
    }

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
        self::assertMatchesRegularExpression('#/tmonier_quote_Q000042-[0-9a-f]{16}\.pdf$#', $path);
        self::assertSame('%PDF-fake Q000042', $this->attachments[$path]);
        self::assertFileExists($path, 'The file stays for an async mailer worker.');
        self::assertSame($this->directory, dirname($path));
        self::assertSame('0644', substr(sprintf('%o', fileperms($path)), -4), 'Readable by a worker running as another user.');
    }

    public function testAPdfFailureDoesNotPreventTheProposalEmail(): void
    {
        $quote = $this->quote();
        $quote->setNumber('Q000043');
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $this->requestStack->push($request);

        $this->mailer(null, true, true)->onProposed(new QuoteRequestEvent($quote));

        self::assertSame([[QuoteRequestEmails::CUSTOMER_PROPOSAL, ['buyer@example.com'], []]], $this->sent);
        self::assertSame([], $this->attachments);
        self::assertSame(['tmonier_quote_request.flash.pdf_failed'], $session->getFlashBag()->get('warning'));
    }

    private function mailer(?string $adminEmail, bool $pdf = false, bool $pdfFails = false): QuoteRequestMailer
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

        return new QuoteRequestMailer(
            $sender,
            new FakePdfGenerator($pdf, $pdfFails),
            $adminEmail,
            new NullLogger(),
            $this->requestStack,
            $this->directory,
        );
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

    public function testTheConfiguredDirectoryIsCreatedWithExplicitPermissionsWhateverTheUmask(): void
    {
        $base = $this->directory . '/nested/pdf';
        $previous = umask(077);

        try {
            $mailer = new QuoteRequestMailer($this->recordingSender(), new FakePdfGenerator(true), null, new NullLogger(), $this->requestStack, $base);
            self::assertSame($base, $mailer->attachmentDirectory(), 'The injected path, not a temporary directory.');
            $quote = $this->quote();
            $quote->setNumber('Q000044');
            $mailer->onProposed(new QuoteRequestEvent($quote));
        } finally {
            umask($previous);
        }

        $path = (string) array_key_first($this->attachments);
        self::assertSame($base, dirname($path), 'One file directly in the configured directory.');
        self::assertSame('0775', substr(sprintf('%o', fileperms($this->directory . '/nested')), -4));
        self::assertSame('0775', substr(sprintf('%o', fileperms($base)), -4));
        self::assertSame('0644', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testAnExistingDirectoryKeepsItsPermissions(): void
    {
        mkdir($this->directory, 0750);
        chmod($this->directory, 0750);
        $mailer = new QuoteRequestMailer($this->recordingSender(), new FakePdfGenerator(true), null, new NullLogger(), $this->requestStack, $this->directory);
        $quote = $this->quote();
        $quote->setNumber('Q000045');

        $mailer->onProposed(new QuoteRequestEvent($quote));

        self::assertSame('0750', substr(sprintf('%o', fileperms($this->directory)), -4));
        self::assertCount(1, $this->attachments);
    }

    private function recordingSender(): SenderInterface
    {
        $record = function (string $code, array $recipients, array $replyTo, array $attachments): void {
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    $this->attachments[$attachment] = (string) file_get_contents($attachment);
                }
            }
        };

        return new class($record) implements SenderInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function send(string $code, array $recipients, array $data = [], array $attachments = [], array $replyTo = []): void
            {
                ($this->record)($code, $recipients, $replyTo, $attachments);
            }
        };
    }

    public function testOnlyThePluginPdfsAreCleanedUp(): void
    {
        mkdir($this->directory, 0775);
        $foreign = $this->directory . '/invoice-2026-001.pdf';
        $old = $this->directory . '/' . QuoteRequestMailer::FILE_PREFIX . 'Q000001-0123456789abcdef.pdf';
        file_put_contents($foreign, '%PDF-invoice');
        file_put_contents($old, '%PDF-old quote');
        touch($foreign, time() - 3 * 86400);
        touch($old, time() - 3 * 86400);
        $mailer = new QuoteRequestMailer($this->recordingSender(), new FakePdfGenerator(true), null, new NullLogger(), $this->requestStack, $this->directory);
        $quote = $this->quote();
        $quote->setNumber('Q000046');

        $mailer->onProposed(new QuoteRequestEvent($quote));

        self::assertFileExists($foreign, 'Another PDF of the directory is never deleted.');
        self::assertFileDoesNotExist($old, 'An old quote PDF is cleaned up.');
    }
}
