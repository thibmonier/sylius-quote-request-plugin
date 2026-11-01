<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Basket\QuoteBasketInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteRequestType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\RateLimit\RateLimitKeys;

final class QuoteRequestCreateController extends AbstractController
{
    use ShopQuoteHelperTrait;

    private const SOURCE_CART_KEY = '_tmonier_quote_request.source_cart.';

    /**
     * @param RepositoryInterface<ProductVariantInterface> $variantRepository
     * @param RepositoryInterface<OrderInterface>|null $orderRepository
     */
    public function __construct(
        private readonly QuoteRequestFactoryInterface $quoteRequestFactory,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteBasketInterface $basket,
        private readonly RepositoryInterface $variantRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
        private readonly CustomerContextInterface $customerContext,
        private readonly bool $allowGuests,
        private readonly ?RateLimiterFactoryInterface $ipLimiter = null,
        private readonly ?RateLimiterFactoryInterface $emailLimiter = null,
        private readonly ?RepositoryInterface $orderRepository = null,
    ) {
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
            $honeypot = $form->get(QuoteRequestType::HONEYPOT)->getData();
            if (is_string($honeypot) && '' !== trim($honeypot)) {
                $this->addFlash('success', 'tmonier_quote_request.flash.request_sent');

                return $this->redirectToRoute('sylius_shop_homepage');
            }
        }

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
            $sourceCart = null !== $sourceCartId && $this->orderRepository instanceof RepositoryInterface ? $this->orderRepository->find($sourceCartId) : null;
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
}
