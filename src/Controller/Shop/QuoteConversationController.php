<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteConversationController extends AbstractController
{
    use ShopQuoteHelperTrait;

    private const ACTIONS = [
        'accept' => QuoteRequestTransitions::TRANSITION_ACCEPT,
        'decline' => QuoteRequestTransitions::TRANSITION_DECLINE,
        'cancel' => QuoteRequestTransitions::TRANSITION_CANCEL,
    ];

    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestFactoryInterface $quoteRequestFactory,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteCartManagerInterface $quoteCartManager,
        private readonly ChannelContextInterface $channelContext,
        private readonly CustomerContextInterface $customerContext,
        private readonly CartContextInterface $cartContext,
        private readonly ?RateLimiterFactoryInterface $messageIpLimiter = null,
        private readonly ?RateLimiterFactoryInterface $messageQuoteLimiter = null,
    ) {
    }

    public function transitionAction(Request $request, string $token, string $action): Response
    {
        $quoteRequest = $this->findByToken($token);
        $transition = self::ACTIONS[$action] ?? null;
        if (null === $transition) {
            throw new NotFoundHttpException();
        }
        $this->checkCsrf($request, $action, $quoteRequest);

        if ($this->isRateLimited($this->messageIpLimiter, $this->messageQuoteLimiter, $request, 'quote-' . $quoteRequest->getId())) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

            return $this->redirectToQuote($quoteRequest);
        }

        if ((QuoteRequestTransitions::TRANSITION_CANCEL === $transition && !in_array($quoteRequest->getStatus(), QuoteRequestStatuses::EDITABLE, true)) ||
            (QuoteRequestTransitions::TRANSITION_DECLINE === $transition && QuoteRequestStatuses::PROPOSED !== $quoteRequest->getStatus())) {
            $this->addFlash('error', 'tmonier_quote_request.flash.transition_not_allowed');

            return $this->redirectToQuote($quoteRequest);
        }

        try {
            if (QuoteRequestTransitions::TRANSITION_ACCEPT === $transition) {
                $this->quoteRequestManager->accept($quoteRequest, $this->shopCustomer());
                $this->addFlash('success', 'tmonier_quote_request.flash.accepted');

                return $this->redirectToRoute('sylius_shop_cart_summary');
            }

            $this->quoteRequestManager->apply($quoteRequest, $transition);
        } catch (QuoteTransitionException $exception) {
            $this->addFlash('error', match (true) {
                $exception->isConcurrentChange() => 'tmonier_quote_request.flash.quote_changed',
                QuoteRequestTransitions::TRANSITION_ACCEPT === $transition => 'tmonier_quote_request.flash.cannot_accept',
                default => 'tmonier_quote_request.flash.transition_not_allowed',
            });

            return $this->redirectToQuote($quoteRequest);
        }

        $this->addFlash('success', sprintf('tmonier_quote_request.flash.%s', $transition === QuoteRequestTransitions::TRANSITION_DECLINE ? 'declined' : 'cancelled'));

        return $this->redirectToQuote($quoteRequest);
    }

    public function messageAction(Request $request, string $token): Response
    {
        $quoteRequest = $this->findByToken($token);
        $form = $this->createForm(QuoteMessageType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'tmonier_quote_request.flash.message_invalid');

            return $this->redirectToQuote($quoteRequest);
        }
        if ($this->isRateLimited($this->messageIpLimiter, $this->messageQuoteLimiter, $request, 'quote-' . $quoteRequest->getId())) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

            return $this->redirectToQuote($quoteRequest);
        }

        /** @var array{body: string} $data */
        $data = $form->getData();
        $message = $this->quoteRequestFactory->createMessage(QuoteMessageInterface::AUTHOR_CUSTOMER, $quoteRequest->getFullName(), trim($data['body']));

        if ($request->request->has('request_changes') && $this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES)) {
            try {
                $this->quoteRequestManager->apply($quoteRequest, QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES, $message);
                $this->addFlash('success', 'tmonier_quote_request.flash.changes_requested');
            } catch (QuoteTransitionException) {
                $this->addFlash('error', 'tmonier_quote_request.flash.quote_changed');

                return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $token]);
            }
        } elseif ($this->isOpen($quoteRequest)) {
            $this->quoteRequestManager->postMessage($quoteRequest, $message);
            $this->addFlash('success', 'tmonier_quote_request.flash.message_sent');
        } else {
            $this->addFlash('error', 'tmonier_quote_request.flash.transition_not_allowed');
        }

        return $this->redirectToQuote($quoteRequest);
    }

    public function checkoutAction(Request $request, string $token): Response
    {
        $quoteRequest = $this->findByToken($token);
        $this->checkCsrf($request, 'checkout', $quoteRequest);
        if (QuoteRequestStatuses::ACCEPTED !== $quoteRequest->getStatus()) {
            $this->addFlash('error', 'tmonier_quote_request.flash.transition_not_allowed');

            return $this->redirectToQuote($quoteRequest);
        }

        try {
            $this->quoteRequestManager->startCheckout($quoteRequest, $this->shopCustomer());
        } catch (QuoteTransitionException $exception) {
            $this->addFlash('error', $exception->isConcurrentChange() ? 'tmonier_quote_request.flash.quote_changed' : 'tmonier_quote_request.flash.cart_of_another_customer');

            return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $token]);
        }

        return $this->redirectToRoute('sylius_shop_cart_summary');
    }

    public function releaseCartAction(Request $request): RedirectResponse
    {
        $cart = $this->currentCart();
        $quoteRequest = $cart instanceof \Sylius\Component\Core\Model\OrderInterface ? $this->quoteRequestRepository->findOneByOrder($cart) : null;
        if (!$quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface || !$this->isCsrfTokenValid('tmonier_quote_request_release_cart', (string) $request->request->get('_csrf_token'))) {
            throw new NotFoundHttpException();
        }

        $this->quoteCartManager->release($quoteRequest);
        $this->addFlash('info', 'tmonier_quote_request.flash.cart_released');

        return $this->redirectToRoute('sylius_shop_cart_summary');
    }
}
