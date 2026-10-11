<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;
use Webmozart\Assert\Assert;

final class QuoteCartManager implements QuoteCartManagerInterface
{
    /** @param FactoryInterface<OrderInterface> $orderFactory */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly EntityManagerInterface $objectManager,
        private readonly CartStorageInterface $cartStorage,
        private readonly QuoteOrderResolverInterface $quoteOrderResolver,
    ) {
    }

    public function start(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface
    {
        $channel = $quoteRequest->getChannel();
        Assert::notNull($channel);

        $cart = $quoteRequest->getOrder();
        if (null !== $cart && OrderInterface::STATE_CART === $cart->getState() && !$this->mayResume($cart, $customer)) {
            throw QuoteTransitionException::cartOfAnotherCustomer();
        }
        if (null === $cart || OrderInterface::STATE_CART !== $cart->getState()) {
            $cart = $this->orderFactory->createNew();
            $cart->setChannel($channel);
            $cart->setLocaleCode($quoteRequest->getLocaleCode());
            $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? $quoteRequest->getCurrencyCode());
            $this->objectManager->persist($cart);
            $quoteRequest->setOrder($cart);
        }

        if (null !== $customer) {
            $cart->setCustomerWithAuthorization($customer);
            $defaultAddress = $customer->getDefaultAddress();
            if (null !== $defaultAddress && null === $cart->getBillingAddress()) {
                $address = clone $defaultAddress;
                $address->setCustomer(null);
                $cart->setBillingAddress($address);
            }
        }

        // The cart needs an id before processing: the price lock processor finds the quote by order.
        $this->objectManager->flush();
        $this->quoteOrderResolver->remember($cart, $quoteRequest);
        $this->orderProcessor->process($cart);
        $this->objectManager->flush();

        if (!$storeInSession) {
            return $cart;
        }

        try {
            $this->cartStorage->setForChannel($channel, $cart);
        } catch (SessionNotFoundException) {
            // CLI or API: no shop session to update.
        }

        return $cart;
    }

    public function release(QuoteRequestInterface $quoteRequest, bool $removeItems = false): void
    {
        $cart = $quoteRequest->getOrder();
        if (null === $cart || OrderInterface::STATE_CART !== $cart->getState()) {
            return;
        }

        $quoteRequest->setOrder(null);
        $this->quoteOrderResolver->forget($cart);
        $this->objectManager->flush();

        foreach ($cart->getItems()->toArray() as $item) {
            /** @var OrderItemInterface $item */
            $item->setImmutable(false);
            foreach ($item->getAdjustments(QuoteRequestInterface::LOCK_ADJUSTMENT)->toArray() as $marker) {
                $item->removeAdjustment($marker);
            }
            if ($removeItems) {
                $cart->removeItem($item);
            }
        }
        $this->orderProcessor->process($cart);
        $this->objectManager->flush();
    }

    public function removeQuotedProducts(OrderInterface $cart, QuoteRequestInterface $quoteRequest): void
    {
        if (OrderInterface::STATE_CART !== $cart->getState() || null === $cart->getId() || $cart === $quoteRequest->getOrder()) {
            return;
        }

        $variants = [];
        foreach ($quoteRequest->getLines() as $line) {
            if (null !== $line->getVariant()) {
                $variants[] = $line->getVariant();
            }
        }

        // Waits for a checkout step of the customer on this cart, if any.
        $table = $this->objectManager->getClassMetadata($cart::class)->getTableName();
        $this->objectManager->getConnection()->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', $table), [$cart->getId()]);

        $removed = false;
        foreach ($cart->getItems()->toArray() as $item) {
            /** @var OrderItemInterface $item */
            if (in_array($item->getVariant(), $variants, true)) {
                $cart->removeItem($item);
                $removed = true;
            }
        }
        if ($removed) {
            $this->orderProcessor->process($cart);
            $this->objectManager->flush();
        }
    }

    /**
     * The cart of a quote stays with the customer who started it: a registered customer's cart
     * is never handed to somebody else (forwarded link), a guest cart only to another guest.
     */
    private function mayResume(OrderInterface $cart, ?CustomerInterface $customer): bool
    {
        $owner = $cart->getCustomer();
        if (null === $owner || $owner === $customer) {
            return true;
        }

        return null === $customer && $owner instanceof CustomerInterface && null === $owner->getUser();
    }
}
