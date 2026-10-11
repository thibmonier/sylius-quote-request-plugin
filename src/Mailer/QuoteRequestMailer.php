<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Mailer;

use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;

final class QuoteRequestMailer implements EventSubscriberInterface
{
    public function __construct(
        private readonly SenderInterface $sender,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
        private readonly ?string $adminEmail,
    ) {
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
        if (!$this->pdfGenerator->isAvailable()) {
            $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_PROPOSAL, $quoteRequest);

            return;
        }

        // The Sylius mailer attaches files by path: the PDF lives in a temporary directory.
        $directory = sprintf('%s/tmonier_quote_%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        $path = $directory . '/' . $this->pdfGenerator->getFilename($quoteRequest);
        mkdir($directory, 0700);

        try {
            file_put_contents($path, $this->pdfGenerator->generate($quoteRequest));
            $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_PROPOSAL, $quoteRequest, [$path]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
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
