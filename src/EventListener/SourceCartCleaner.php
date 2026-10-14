<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvent;

/**
 * "ordered" (customer checkout or merchant conversion): the quoted products leave the cart the
 * request was made from ("Request a quote for this cart"), so that they are not bought twice.
 */
final readonly class SourceCartCleaner
{
    public function __construct(
        private QuoteCartManagerInterface $quoteCartManager,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(QuoteRequestEvent $event): void
    {
        // Fresh, managed entities: the event may come after the entity manager was cleared.
        $id = $event->getQuoteRequest()->getId();
        $quoteRequest = null === $id ? null : $this->entityManager->find($event->getQuoteRequest()::class, $id);
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            return;
        }

        $source = $quoteRequest->getSourceOrder();
        if ($source instanceof \Sylius\Component\Core\Model\OrderInterface) {
            $this->quoteCartManager->removeQuotedProducts($source, $quoteRequest);
        }
    }
}
