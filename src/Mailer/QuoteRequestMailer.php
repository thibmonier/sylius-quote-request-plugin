<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Mailer;

use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents;

final class QuoteRequestMailer implements EventSubscriberInterface
{
    public function __construct(
        private readonly SenderInterface $sender,
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
        $this->sendToCustomer(QuoteRequestEmails::CUSTOMER_PROPOSAL, $event->getQuoteRequest());
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

    private function sendToCustomer(string $code, QuoteRequestInterface $quoteRequest): void
    {
        $recipient = $quoteRequest->getEmail();
        if (null === $recipient || '' === $recipient) {
            return;
        }

        $this->sender->send($code, [$recipient], $this->data($quoteRequest));
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
