<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusQuoteRequestPlugin\Basket\QuoteBasketInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\AddToQuoteType;

final class QuoteBasketController extends AbstractController
{
    use ShopQuoteHelperTrait;

    private const SOURCE_CART_KEY = '_tmonier_quote_request.source_cart.';

    /**
     * @param RepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private readonly QuoteBasketInterface $basket,
        private readonly RepositoryInterface $productRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly CartContextInterface $cartContext,
        private readonly bool $fromProductPage,
        private readonly bool $fromCart,
        private readonly int $maxLines,
    ) {
    }

    public function addToBasketAction(Request $request, int $productId): RedirectResponse
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

    public function fromCartAction(Request $request): RedirectResponse
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
        $request->getSession()->set(self::SOURCE_CART_KEY . $this->channel()->getCode(), $cart?->getId());

        return $this->redirectToRoute('tmonier_quote_request_shop_new');
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
}
