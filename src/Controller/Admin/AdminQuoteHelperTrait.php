<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Admin;

use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

trait AdminQuoteHelperTrait
{
    private function find(int $id): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestRepository->find($id);
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        return $quoteRequest;
    }

    private function redirectToQuote(QuoteRequestInterface $quoteRequest): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('tmonier_quote_request_admin_quote_request_show', ['id' => $quoteRequest->getId()]);
    }

    private function adminMessage(string $body): QuoteMessageInterface
    {
        $user = $this->getUser();
        $name = $user instanceof AdminUserInterface ? trim(sprintf('%s %s', (string) $user->getFirstName(), (string) $user->getLastName())) : '';
        if ('' === $name && $user instanceof AdminUserInterface) {
            $name = (string) $user->getUsername();
        }

        return $this->quoteRequestFactory->createMessage(QuoteMessageInterface::AUTHOR_ADMIN, '' === $name ? null : $name, $body);
    }
}
