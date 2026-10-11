<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Mailer;

use Psr\Log\LoggerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;

final class QuoteRequestMailer implements EventSubscriberInterface
{
    public const ATTACHMENT_TTL = 172800;

    public const FILE_PREFIX = 'tmonier_quote_';

    public function __construct(
        private readonly SenderInterface $sender,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
        private readonly ?string $adminEmail,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly string $attachmentBaseDirectory,
    ) {
    }

    /**
     * The configured directory (by default in the project, `var/tmonier_quote_request/pdf`).
     * Created with 0775 when missing (group-writable: php-fpm and the mailer workers share a
     * group or an ACL); an existing directory keeps its permissions.
     */
    public function attachmentDirectory(): string
    {
        $directory = rtrim($this->attachmentBaseDirectory, '/');
        if (!is_dir($directory)) {
            $missing = [];
            for ($path = $directory; '' !== $path && '/' !== $path && !is_dir($path); $path = dirname($path)) {
                $missing[] = $path;
            }
            foreach (array_reverse($missing) as $path) {
                if (!@mkdir($path, 0775) && !is_dir($path)) {
                    throw new \RuntimeException(sprintf('Cannot create "%s".', $path));
                }
                chmod($path, 0775);
            }
        }

        return $directory;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            QuoteRequestEvents::CREATED => 'onCreated',
            QuoteRequestEvents::PROPOSED => 'onProposed',
            QuoteRequestEvents::ACCEPTED => 'onAccepted',
            QuoteRequestEvents::CHANGES_REQUESTED => 'onMessagePosted',
            QuoteRequestEvents::MESSAGE_POSTED => 'onMessagePosted',
        ];
    }

    public function onCreated(QuoteRequestEvent $event): void
    {
        $quoteRequest = $event->getQuoteRequest();
        $this->sendToAdmin(QuoteRequestEmails::ADMIN_NEW_REQUEST, $quoteRequest);
        $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_REQUEST_RECEIVED, $quoteRequest);
    }

    public function onProposed(QuoteRequestEvent $event): void
    {
        $quoteRequest = $event->getQuoteRequest();
        $attachment = $this->writePdf($quoteRequest);

        $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_PROPOSAL, $quoteRequest, null === $attachment ? [] : [$attachment]);
    }

    /**
     * The Sylius mailer attaches files by path, possibly read later by an async worker: the PDF
     * is kept in a dedicated directory and removed after ATTACHMENT_TTL. A PDF failure never
     * prevents the proposal e-mail: it is sent without attachment and the merchant is warned.
     */
    private function writePdf(QuoteRequestInterface $quoteRequest): ?string
    {
        if (!$this->pdfGenerator->isAvailable()) {
            return null;
        }

        try {
            $content = $this->pdfGenerator->generate($quoteRequest);
            $this->removeOldAttachments();
            // One uniquely named file per PDF, readable by an async mailer worker (0644).
            $path = sprintf('%s/%s%s-%s.pdf', $this->attachmentDirectory(), self::FILE_PREFIX, preg_replace('/[^A-Za-z0-9_-]/', '', (string) $quoteRequest->getNumber()), bin2hex(random_bytes(8)));
            if (false === file_put_contents($path, $content)) {
                throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
            }
            chmod($path, 0644);

            return $path;
        } catch (\Throwable $exception) {
            $this->logger->warning('The PDF of quote {number} could not be generated: {message}', [
                'number' => $quoteRequest->getNumber(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->warn('tmonier_quote_request.flash.pdf_failed');

            return null;
        }
    }

    private function removeOldAttachments(): void
    {
        $limit = time() - self::ATTACHMENT_TTL;
        // Only the files of the plugin: the directory may hold other PDFs.
        foreach (glob($this->attachmentDirectory() . '/' . self::FILE_PREFIX . '*.pdf') ?: [] as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    private function warn(string $message): void
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return;
        }
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $message);
        }
    }

    public function onAccepted(QuoteRequestEvent $event): void
    {
        $this->sendToAdmin(QuoteRequestEmails::ADMIN_ACCEPTED, $event->getQuoteRequest());
    }

    public function onMessagePosted(QuoteRequestEvent $event): void
    {
        $quoteRequest = $event->getQuoteRequest();
        $message = $quoteRequest->getMessages()->last();
        if (false === $message) {
            return;
        }

        if ($message->isFromCustomer()) {
            $this->sendToAdmin(QuoteRequestEmails::ADMIN_CUSTOMER_MESSAGE, $quoteRequest);

            return;
        }

        $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_MERCHANT_MESSAGE, $quoteRequest);
    }

    private function sendToAdmin(string $code, QuoteRequestInterface $quoteRequest): void
    {
        $recipient = $this->adminEmail ?? $quoteRequest->getChannel()?->getContactEmail();
        if (null === $recipient || '' === $recipient) {
            return;
        }

        $this->sender->send($code, [$recipient], $this->data($quoteRequest), [], array_filter([$quoteRequest->getEmail()]));
    }

    /** @param list<string> $attachments */
    private function sendToCustomer(string $code, QuoteRequestInterface $quoteRequest, array $attachments = []): void
    {
        $recipient = $quoteRequest->getEmail();
        if (null === $recipient || '' === $recipient) {
            return;
        }

        $this->sender->send($code, [$recipient], $this->data($quoteRequest), $attachments);
    }

    /** @return array<string, mixed> */
    private function data(QuoteRequestInterface $quoteRequest): array
    {
        return [
            'quoteRequest' => $quoteRequest,
            'channel' => $quoteRequest->getChannel(),
            'localeCode' => $quoteRequest->getLocaleCode(),
        ];
    }
}
