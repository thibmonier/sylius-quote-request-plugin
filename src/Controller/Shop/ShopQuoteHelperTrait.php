<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\RateLimit\RateLimitKeys;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

trait ShopQuoteHelperTrait
{
    private function channel(): ChannelInterface
    {
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();

        return $channel;
    }

    private function shopCustomer(): ?CustomerInterface
    {
        $customer = $this->customerContext->getCustomer();

        return $customer instanceof CustomerInterface && $customer->getUser() instanceof \Sylius\Component\User\Model\UserInterface ? $customer : null;
    }

    private function findByToken(string $token): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestRepository->findOneByToken($token);
        if (!$quoteRequest instanceof QuoteRequestInterface || $quoteRequest->getChannel() !== $this->channel()) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        $owner = $quoteRequest->getCustomer();
        if ($owner instanceof CustomerInterface && $owner->getUser() instanceof \Sylius\Component\User\Model\UserInterface && $this->shopCustomer() !== $owner) {
            throw $this->createAccessDeniedException('This quote belongs to a shop account.');
        }

        return $quoteRequest;
    }

    private function redirectToQuote(QuoteRequestInterface $quoteRequest): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $customer = $this->shopCustomer();
        if ($customer instanceof CustomerInterface && $quoteRequest->getCustomer() === $customer) {
            return $this->redirectToRoute('tmonier_quote_request_shop_account_show', ['number' => $quoteRequest->getNumber()]);
        }

        return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $quoteRequest->getToken()]);
    }

    private function checkCsrf(Request $request, string $action, QuoteRequestInterface $quoteRequest): void
    {
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_%s_%s', $action, (string) $quoteRequest->getId()), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function isRateLimited(?RateLimiterFactoryInterface $ipLimiter, ?RateLimiterFactoryInterface $keyLimiter, Request $request, string $key): bool
    {
        $limited = false;
        if ($ipLimiter instanceof RateLimiterFactoryInterface) {
            $limited = !$ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
        }
        if ($keyLimiter instanceof RateLimiterFactoryInterface && '' !== $key) {
            return !$keyLimiter->create($key)->consume()->isAccepted() || $limited;
        }

        return $limited;
    }

    private function isOpen(QuoteRequestInterface $quoteRequest): bool
    {
        return in_array($quoteRequest->getStatus(), [QuoteRequestStatuses::NEW, QuoteRequestStatuses::IN_REVIEW, QuoteRequestStatuses::PROPOSED, QuoteRequestStatuses::ACCEPTED], true);
    }

    private function currentCart(): ?OrderInterface
    {
        try {
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return null;
        }

        return $cart instanceof OrderInterface && null !== $cart->getId() ? $cart : null;
    }
}
