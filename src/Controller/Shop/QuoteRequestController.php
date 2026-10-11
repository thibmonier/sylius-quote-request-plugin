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
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestController extends AbstractController
{
    /** Customer actions on a quote, by URL action name. */
    private const ACTIONS = [
        'accept' => QuoteRequestTransitions::TRANSITION_ACCEPT,
        'decline' => QuoteRequestTransitions::TRANSITION_DECLINE,
        'cancel' => QuoteRequestTransitions::TRANSITION_CANCEL,
    ];

    /**
     * @param RepositoryInterface<ProductInterface> $productRepository
     * @param RepositoryInterface<ProductVariantInterface> $variantRepository
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
    ) {
    }

    public function addToBasketAction(Request $request, int $productId): Response
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

    public function fromCartAction(Request $request): Response
    {
        if (!$this->fromCart || !$this->isCsrfTokenValid('tmonier_quote_request_from_cart', (string) $request->request->get('_csrf_token'))) {
            throw new NotFoundHttpException();
        }

        $quantities = [];
        foreach ($this->currentCart()?->getItems() ?? [] as $item) {
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

        return $this->redirectToRoute('tmonier_quote_request_shop_new');
    }

    public function newAction(Request $request): Response
    {
        $customer = $this->shopCustomer();
        if (null === $customer && !$this->allowGuests) {
            $this->addFlash('info', 'tmonier_quote_request.flash.login_required');

            return $this->redirectToRoute('sylius_shop_login');
        }

        $channel = $this->channel();
        $quoteRequest = $this->quoteRequestFactory->createForChannel($channel, $this->localeContext->getLocaleCode(), $customer);
        foreach ($this->basket->all($channel) as $variantId => $quantity) {
            $variant = $this->variantRepository->find($variantId);
            if ($variant instanceof ProductVariantInterface) {
                $line = $this->quoteRequestFactory->createLine($quoteRequest, $variant, $quantity);
                if (null !== $line) {
                    $quoteRequest->addLine($line);
                }
            }
        }

        $form = $this->createForm(QuoteRequestType::class, $quoteRequest, ['email_editable' => null === $customer]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && null === $customer) {
            // Honeypot: robots fill the hidden "website" field; they get the usual answer, nothing is sent.
            $honeypot = $form->get(QuoteRequestType::HONEYPOT)->getData();
            if (is_string($honeypot) && '' !== trim($honeypot)) {
                $this->addFlash('success', 'tmonier_quote_request.flash.request_sent');

                return $this->redirectToRoute('sylius_shop_homepage');
            }
            if ($this->isRateLimited($request, $quoteRequest->getEmail())) {
                $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

                return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/new.html.twig', [
                    'quote_request' => $quoteRequest,
                    'form' => $form->createView(),
                    'is_guest' => true,
                ], new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
            }
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

            $this->quoteRequestManager->submit($quoteRequest, $message);
            $this->basket->clear($channel);
            $this->addFlash('success', 'tmonier_quote_request.flash.request_sent');

            return $this->redirectToQuote($quoteRequest);
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/new.html.twig', [
            'quote_request' => $quoteRequest,
            'form' => $form->createView(),
            'is_guest' => null === $customer,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    public function showByTokenAction(Request $request, string $token): Response
    {
        return $this->renderQuote($request, $this->findByToken($token), false);
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
        if (null === $customer) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/account/index.html.twig', [
            'quote_requests' => $this->quoteRequestRepository->findByCustomer($customer),
        ]);
    }

    public function accountShowAction(Request $request, string $number): Response
    {
        $customer = $this->shopCustomer();
        if (null === $customer) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByNumberAndCustomer($number, $customer);
        if (null === $quoteRequest) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        return $this->renderQuote($request, $quoteRequest, true);
    }

    public function transitionAction(Request $request, string $token, string $action): Response
    {
        $quoteRequest = $this->findByToken($token);
        $transition = self::ACTIONS[$action] ?? null;
        if (null === $transition) {
            throw new NotFoundHttpException();
        }
        $this->checkCsrf($request, $action, $quoteRequest);

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
        } catch (QuoteTransitionException) {
            $this->addFlash('error', QuoteRequestTransitions::TRANSITION_ACCEPT === $transition ? 'tmonier_quote_request.flash.cannot_accept' : 'tmonier_quote_request.flash.transition_not_allowed');

            return $this->redirectToQuote($quoteRequest);
        }

        $this->addFlash('success', sprintf('tmonier_quote_request.flash.%s', $transition === QuoteRequestTransitions::TRANSITION_DECLINE ? 'declined' : 'cancelled'));

        return $this->redirectToQuote($quoteRequest);
    }

    public function messageAction(Request $request, string $token): Response
    {
        $quoteRequest = $this->findByToken($token);
        if (null === $quoteRequest->getCustomer() && $this->isRateLimited($request, $quoteRequest->getEmail())) {
            $this->addFlash('error', 'tmonier_quote_request.flash.too_many_requests');

            return $this->redirectToQuote($quoteRequest);
        }
        $form = $this->createForm(QuoteMessageType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'tmonier_quote_request.flash.message_invalid');

            return $this->redirectToQuote($quoteRequest);
        }

        /** @var array{body: string} $data */
        $data = $form->getData();
        $message = $this->quoteRequestFactory->createMessage(QuoteMessageInterface::AUTHOR_CUSTOMER, $quoteRequest->getFullName(), trim($data['body']));

        if ($request->request->has('request_changes') && $this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES)) {
            $this->quoteRequestManager->apply($quoteRequest, QuoteRequestTransitions::TRANSITION_REQUEST_CHANGES, $message);
            $this->addFlash('success', 'tmonier_quote_request.flash.changes_requested');
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
        } catch (QuoteTransitionException) {
            $this->addFlash('error', 'tmonier_quote_request.flash.cart_of_another_customer');

            return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $token]);
        }

        return $this->redirectToRoute('sylius_shop_cart_summary');
    }

    /** Leaves the quote mode of the current cart: the items get back the catalog prices. */
    public function releaseCartAction(Request $request): Response
    {
        $cart = $this->currentCart();
        $quoteRequest = null === $cart ? null : $this->quoteRequestRepository->findOneByOrder($cart);
        if (null === $quoteRequest || !$this->isCsrfTokenValid('tmonier_quote_request_release_cart', (string) $request->request->get('_csrf_token'))) {
            throw new NotFoundHttpException();
        }

        $this->quoteCartManager->release($quoteRequest);
        $this->addFlash('info', 'tmonier_quote_request.flash.cart_released');

        return $this->redirectToRoute('sylius_shop_cart_summary');
    }

    private function renderQuote(Request $request, QuoteRequestInterface $quoteRequest, bool $fromAccount): Response
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

    /** Guests only: per IP and per e-mail (consumes one token of each limiter). */
    private function isRateLimited(Request $request, ?string $email): bool
    {
        $limited = false;
        if (null !== $this->ipLimiter) {
            $limited = !$this->ipLimiter->create((string) $request->getClientIp())->consume()->isAccepted();
        }
        if (null !== $this->emailLimiter && null !== $email && '' !== $email) {
            $limited = !$this->emailLimiter->create(mb_strtolower(trim($email)))->consume()->isAccepted() || $limited;
        }

        return $limited;
    }

    private function isOpen(QuoteRequestInterface $quoteRequest): bool
    {
        return in_array($quoteRequest->getStatus(), [QuoteRequestStatuses::NEW, QuoteRequestStatuses::IN_REVIEW, QuoteRequestStatuses::PROPOSED, QuoteRequestStatuses::ACCEPTED], true);
    }

    private function redirectToQuote(QuoteRequestInterface $quoteRequest): Response
    {
        $customer = $this->shopCustomer();
        if (null !== $customer && $quoteRequest->getCustomer() === $customer) {
            return $this->redirectToRoute('tmonier_quote_request_shop_account_show', ['number' => $quoteRequest->getNumber()]);
        }

        return $this->redirectToRoute('tmonier_quote_request_shop_show_by_token', ['token' => $quoteRequest->getToken()]);
    }

    private function findByToken(string $token): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestRepository->findOneByToken($token);
        if (null === $quoteRequest || $quoteRequest->getChannel() !== $this->channel()) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        // The token is the key of guest quotes only: the quote of a shop account needs its owner
        // logged in (anonymous visitors are sent to the login page, other customers get a 403).
        $owner = $quoteRequest->getCustomer();
        if (null !== $owner && null !== $owner->getUser() && $this->shopCustomer() !== $owner) {
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
            if ($variant instanceof ProductVariantInterface && null !== $variant->getChannelPricingForChannel($channel)) {
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

        return $customer instanceof CustomerInterface && null !== $customer->getUser() ? $customer : null;
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
