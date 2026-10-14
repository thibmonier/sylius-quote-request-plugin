<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Tmonier\SyliusQuoteRequestPlugin\Basket\QuoteBasketInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\AddToQuoteType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteRequestType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\RateLimit\RateLimitKeys;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestController extends AbstractController
{
    private const SOURCE_CART_KEY = '_tmonier_quote_request.source_cart.';

    /** Customer actions on a quote, by URL action name. */
    private const ACTIONS = [
        'accept' => QuoteRequestTransitions::TRANSITION_ACCEPT,
        'decline' => QuoteRequestTransitions::TRANSITION_DECLINE,
        'cancel' => QuoteRequestTransitions::TRANSITION_CANCEL,
    ];

    /**
     * @param RepositoryInterface<ProductInterface> $productRepository
     * @param RepositoryInterface<ProductVariantInterface> $variantRepository
     * @param RepositoryInterface<OrderInterface>|null $orderRepository
     */
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestFactoryInterface $quoteRequestFactory,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteCartManagerInterface $quoteCartManager,
        private readonly QuoteBasketInterface $basket,
        private readonly RepositoryInterface $productRepository,
        private readonly RepositoryInterface $variantRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
        private readonly CustomerContextInterface $customerContext,
        private readonly CartContextInterface $cartContext,
        private readonly bool $allowGuests,
        private readonly bool $fromProductPage,
        private readonly bool $fromCart,
        private readonly int $maxLines,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
        private readonly ?RateLimiterFactory $ipLimiter = null,
        private readonly ?RateLimiterFactory $emailLimiter = null,
        private readonly ?RateLimiterFactory $messageIpLimiter = null,
        private readonly ?RateLimiterFactory $messageQuoteLimiter = null,
        private readonly ?RepositoryInterface $orderRepository = null,
    ) {
    }

    public function addToBasketAction(Request $request, int $productId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $channel = $this->channel();
        $product = $this->productRepository->find($productId);
        if (!$this->fromProductPage || !$product instanceof ProductInterface || !$product->isEnabled() || !$product->hasChannel($channel)) {
            throw new NotFoundHttpException('Product not found.');
        }

        $form = $this->createForm(AddToQuoteType::class, null, ['variants' => $this->quotableVariants($product, $channel)]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'tmonier_quote_request.flash.invalid_product');

            return $this->redirectToRoute('sylius_shop_product_show', ['slug' => $product->getSlug()]);
        }

        /** @var array{variant: ProductVariantInterface, quantity: int} $data */
        $data = $form->getData();
        $variantId = $data['variant']->getId();
        if (!is_int($variantId)) {
            throw new NotFoundHttpException('Variant not found.');
        }
        if ($this->basket->count($channel) >= $this->maxLines && !isset($this->basket->all($channel)[$variantId])) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_lines');

            return $this->redirectToRoute('tmonier_quote_request_shop_new');
        }

        $this->basket->add($channel, $variantId, $data['quantity']);
        $this->addFlash('success', 'tmonier_quote_request.flash.added_to_quote');

        return $this->redirectToRoute('tmonier_quote_request_shop_new');
    }

    public function fromCartAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$this->fromCart || !$this->isCsrfTokenValid('tmonier_quote_request_from_cart', (string) $request->request->get('_csrf_token'))) {
            throw new NotFoundHttpException();
        }

        $cart = $this->currentCart();
        $quantities = [];
        foreach ($cart?->getItems() ?? [] as $item) {
            /** @var OrderItemInterface $item */
            $variantId = $item->getVariant()?->getId();
            if (is_int($variantId)) {
                $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $item->getQuantity();
            }
        }

        if ([] === $quantities) {
            $this->addFlash('error', 'tmonier_quote_request.flash.empty_cart');

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        $this->basket->replace($this->channel(), $quantities);
        // Remembered so that the cart loses these products if the merchant places the order.
        $request->getSession()->set(self::SOURCE_CART_KEY . $this->channel()->getCode(), $cart?->getId());

        return $this->redirectToRoute('tmonier_quote_request_shop_new');
    }

    public function newAction(Request $request): Response
    {
        $customer = $this->shopCustomer();
        if (!$customer instanceof \Sylius\Component\Core\Model\CustomerInterface && !$this->allowGuests) {
            $this->addFlash('info', 'tmonier_quote_request.flash.login_required');

            return $this->redirectToRoute('sylius_shop_login');
        }

        $channel = $this->channel();
        $quoteRequest = $this->quoteRequestFactory->createForChannel($channel, $this->localeContext->getLocaleCode(), $customer);
        foreach ($this->basket->all($channel) as $variantId => $quantity) {
            $variant = $this->variantRepository->find($variantId);
            if ($variant instanceof ProductVariantInterface) {
                $line = $this->quoteRequestFactory->createLine($quoteRequest, $variant, $quantity);
                if ($line instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface) {
                    $quoteRequest->addLine($line);
                }
            }
        }

        $form = $this->createForm(QuoteRequestType::class, $quoteRequest, ['email_editable' => !$customer instanceof \Sylius\Component\Core\Model\CustomerInterface]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            // Honeypot: robots fill the hidden "website" field; they get the usual answer, nothing is sent.
            $honeypot = $form->get(QuoteRequestType::HONEYPOT)->getData();
            if (is_string($honeypot) && '' !== trim($honeypot)) {
                $this->addFlash('success', 'tmonier_quote_request.flash.request_sent');

                return $this->redirectToRoute('sylius_shop_homepage');
            }
        }

        // Only valid requests count; customers with an account are limited too (registration
        // may not need an e-mail verification).
        if ($form->isSubmitted() && $form->isValid() && $this->isRateLimited($this->ipLimiter, $this->emailLimiter, $request, RateLimitKeys::email((string) $quoteRequest->getEmail()))) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

            return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/new.html.twig', [
                'quote_request' => $quoteRequest,
                'form' => $form->createView(),
                'is_guest' => !$customer instanceof \Sylius\Component\Core\Model\CustomerInterface,
            ], new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($quoteRequest->getLines()->toArray() as $line) {
                if ($line->getQuantity() <= 0) {
                    $quoteRequest->removeLine($line);

                    continue;
                }
                $line->setRequestedQuantity($line->getQuantity());
            }

            if ($quoteRequest->getLines()->isEmpty()) {
                $this->basket->clear($channel);
                $this->addFlash('error', 'tmonier_quote_request.flash.no_product');

                return $this->redirectToRoute('tmonier_quote_request_shop_new');
            }

            /** @var string|null $body */
            $body = $form->get('message')->getData();
            $message = null;
            if (null !== $body && '' !== trim($body)) {
                $message = $this->quoteRequestFactory->createMessage(QuoteMessageInterface::AUTHOR_CUSTOMER, $quoteRequest->getFullName(), trim($body));
            }

            $sourceCartId = $request->getSession()->remove(self::SOURCE_CART_KEY . $channel->getCode());
            $sourceCart = null !== $sourceCartId && $this->orderRepository instanceof \Sylius\Resource\Doctrine\Persistence\RepositoryInterface ? $this->orderRepository->find($sourceCartId) : null;
            if ($sourceCart instanceof OrderInterface && OrderInterface::STATE_CART === $sourceCart->getState()) {
                $quoteRequest->setSourceOrder($sourceCart);
            }

            $this->quoteRequestManager->submit($quoteRequest, $message);
            $this->basket->clear($channel);
            $this->addFlash('success', 'tmonier_quote_request.flash.request_sent');

            return $this->redirectToQuote($quoteRequest);
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/new.html.twig', [
            'quote_request' => $quoteRequest,
            'form' => $form->createView(),
            'is_guest' => !$customer instanceof \Sylius\Component\Core\Model\CustomerInterface,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    public function showByTokenAction(Request $request, string $token): Response
    {
        return $this->renderQuote($this->findByToken($token), false);
    }

    /** PDF of the proposal (from the proposal on: proposed, accepted, ordered, expired). */
    public function pdfAction(string $token): Response
    {
        $quoteRequest = $this->findByToken($token);
        if (!$this->pdfGenerator->isAvailable() || !in_array($quoteRequest->getStatus(), QuoteRequestStatuses::WITH_PROPOSAL, true)) {
            throw new NotFoundHttpException('No PDF for this quote.');
        }

        return new Response($this->pdfGenerator->generate($quoteRequest), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->pdfGenerator->getFilename($quoteRequest)),
        ]);
    }

    public function accountIndexAction(): Response
    {
        $customer = $this->shopCustomer();
        if (!$customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/account/index.html.twig', [
            'quote_requests' => $this->quoteRequestRepository->findByCustomer($customer),
        ]);
    }

    public function accountShowAction(Request $request, string $number): Response
    {
        $customer = $this->shopCustomer();
        if (!$customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByNumberAndCustomer($number, $customer);
        if (!$quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        return $this->renderQuote($quoteRequest, true);
    }

    public function transitionAction(Request $request, string $token, string $action): Response
    {
        $quoteRequest = $this->findByToken($token);
        $transition = self::ACTIONS[$action] ?? null;
        if (null === $transition) {
            throw new NotFoundHttpException();
        }
        $this->checkCsrf($request, $action, $quoteRequest);

        // Transitions on a quote (accept / decline / cancel) share the conversation budget so
        // that a token holder cannot hammer the transition endpoint.
        if ($this->isRateLimited($this->messageIpLimiter, $this->messageQuoteLimiter, $request, 'quote-' . $quoteRequest->getId())) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

            return $this->redirectToQuote($quoteRequest);
        }

        // Customers cancel a request in progress and decline a proposal, nothing else.
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
        // Conversations have their own budget, per quote (and per IP).
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
                // Changed meanwhile (e.g. proposal revised by the merchant): the customer retries.
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

    /** Back to the cart of an accepted quote (the previous one, or a new one when it is gone). */
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

    /** Leaves the quote mode of the current cart: the items get back the catalog prices. */
    public function releaseCartAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
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

    private function renderQuote(QuoteRequestInterface $quoteRequest, bool $fromAccount): Response
    {
        $messageForm = $this->createForm(QuoteMessageType::class, null, [
            'action' => $this->generateUrl('tmonier_quote_request_shop_message', ['token' => $quoteRequest->getToken()]),
        ]);

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/show.html.twig', [
            'quote_request' => $quoteRequest,
            'message_form' => $this->isOpen($quoteRequest) ? $messageForm->createView() : null,
            'from_account' => $fromAccount,
        ]);
    }

    /** Consumes one token per limiter (IP or IPv6 /64, and the given key). */
    private function isRateLimited(?RateLimiterFactory $ipLimiter, ?RateLimiterFactory $keyLimiter, Request $request, string $key): bool
    {
        $limited = false;
        if ($ipLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory) {
            $limited = !$ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
        }
        if ($keyLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory && '' !== $key) {
            return !$keyLimiter->create($key)->consume()->isAccepted() || $limited;
        }

        return $limited;
    }

    private function isOpen(QuoteRequestInterface $quoteRequest): bool
    {
        return in_array($quoteRequest->getStatus(), [QuoteRequestStatuses::NEW, QuoteRequestStatuses::IN_REVIEW, QuoteRequestStatuses::PROPOSED, QuoteRequestStatuses::ACCEPTED], true);
    }

    private function redirectToQuote(QuoteRequestInterface $quoteRequest): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $customer = $this->shopCustomer();
        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface && $quoteRequest->getCustomer() === $customer) {
            return $this->redirectToRoute('tmonier_quote_request_shop_account_show', ['number' => $quoteRequest->getNumber()]);
        }

        return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $quoteRequest->getToken()]);
    }

    private function findByToken(string $token): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestRepository->findOneByToken($token);
        if (!$quoteRequest instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface || $quoteRequest->getChannel() !== $this->channel()) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        // The token is the key of guest quotes only: the quote of a shop account needs its owner
        // logged in (anonymous visitors are sent to the login page, other customers get a 403).
        $owner = $quoteRequest->getCustomer();
        if ($owner instanceof \Sylius\Component\Core\Model\CustomerInterface && $owner->getUser() instanceof \Sylius\Component\User\Model\UserInterface && $this->shopCustomer() !== $owner) {
            throw $this->createAccessDeniedException('This quote belongs to a shop account.');
        }

        return $quoteRequest;
    }

    private function checkCsrf(Request $request, string $action, QuoteRequestInterface $quoteRequest): void
    {
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_%s_%s', $action, (string) $quoteRequest->getId()), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /** @return list<ProductVariantInterface> */
    private function quotableVariants(ProductInterface $product, ChannelInterface $channel): array
    {
        $variants = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface && $variant->getChannelPricingForChannel($channel) instanceof \Sylius\Component\Core\Model\ChannelPricingInterface) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    private function channel(): ChannelInterface
    {
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();

        return $channel;
    }

    /** Customer of the logged-in shop user (a guest customer of the session is ignored). */
    private function shopCustomer(): ?CustomerInterface
    {
        $customer = $this->customerContext->getCustomer();

        return $customer instanceof CustomerInterface && $customer->getUser() instanceof \Sylius\Component\User\Model\UserInterface ? $customer : null;
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
