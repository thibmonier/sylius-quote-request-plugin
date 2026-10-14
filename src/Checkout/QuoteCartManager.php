<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Checkout;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;
use Webmozart\Assert\Assert;

final readonly class QuoteCartManager implements QuoteCartManagerInterface
{
    /** @param FactoryInterface<OrderInterface> $orderFactory */
    public function __construct(
        private FactoryInterface $orderFactory,
        private OrderProcessorInterface $orderProcessor,
        private EntityManagerInterface $objectManager,
        private CartStorageInterface $cartStorage,
        private QuoteOrderResolverInterface $quoteOrderResolver,
        private OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private ClockInterface $clock,
        private int $checkoutIdleMinutes = 30,
    ) {
    }

    public function start(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface
    {
        $channel = $quoteRequest->getChannel();
        Assert::notNull($channel);

        $cart = $quoteRequest->getOrder();
        if ($cart instanceof \Sylius\Component\Core\Model\OrderInterface && OrderInterface::STATE_CART === $cart->getState() && !$this->mayResume($cart, $customer)) {
            throw QuoteTransitionException::cartOfAnotherCustomer();
        }
        if (!$cart instanceof \Sylius\Component\Core\Model\OrderInterface || OrderInterface::STATE_CART !== $cart->getState()) {
            $cart = $this->orderFactory->createNew();
            $cart->setChannel($channel);
            $cart->setLocaleCode($quoteRequest->getLocaleCode());
            $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? $quoteRequest->getCurrencyCode());
            $this->objectManager->persist($cart);
            $quoteRequest->setOrder($cart);
        }

        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            $cart->setCustomerWithAuthorization($customer);
            $defaultAddress = $customer->getDefaultAddress();
            if ($defaultAddress instanceof \Sylius\Component\Core\Model\AddressInterface && null === $cart->getBillingAddress()) {
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
        if (!$cart instanceof \Sylius\Component\Core\Model\OrderInterface || OrderInterface::STATE_CART !== $cart->getState()) {
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

        // Locks the cart row (a checkout step of the customer waits) and reads its real state: a
        // cart at the payment step, used recently, is never changed under the customer's feet.
        $table = $this->objectManager->getClassMetadata($cart::class)->getTableName();
        $row = $this->objectManager->getConnection()->fetchAssociative(sprintf('SELECT checkout_state, updated_at FROM %s WHERE id = ? FOR UPDATE', $table), [$cart->getId()]);
        if (!is_array($row) || $this->isInActiveCheckout($row)) {
            return;
        }

        // The quoted quantities only: what the customer added on top stays.
        $toRemove = [];
        foreach ($quoteRequest->getLines() as $line) {
            $variant = $line->getVariant();
            if (null !== $variant) {
                $key = spl_object_id($variant);
                $toRemove[$key] = ($toRemove[$key] ?? 0) + ($line->getRequestedQuantity() ?? $line->getQuantity());
            }
        }

        $changed = false;
        foreach ($cart->getItems()->toArray() as $item) {
            /** @var OrderItemInterface $item */
            $variant = $item->getVariant();
            $key = null === $variant ? null : spl_object_id($variant);
            if (null === $key || ($toRemove[$key] ?? 0) <= 0) {
                continue;
            }
            $removed = min($toRemove[$key], $item->getQuantity());
            $toRemove[$key] -= $removed;
            if ($removed >= $item->getQuantity()) {
                $cart->removeItem($item);
            } else {
                $this->orderItemQuantityModifier->modify($item, $item->getQuantity() - $removed);
            }
            $changed = true;
        }
        if ($changed) {
            $this->orderProcessor->process($cart);
            $this->objectManager->flush();
        }
    }

    /** @param array<string, mixed> $row */
    private function isInActiveCheckout(array $row): bool
    {
        $notAtPaymentYet = [OrderCheckoutStates::STATE_CART, OrderCheckoutStates::STATE_ADDRESSED, OrderCheckoutStates::STATE_SHIPPING_SELECTED, OrderCheckoutStates::STATE_SHIPPING_SKIPPED];
        if (in_array($row['checkout_state'] ?? null, $notAtPaymentYet, true)) {
            return false;
        }

        $updatedAt = is_string($row['updated_at'] ?? null) ? new \DateTimeImmutable($row['updated_at']) : null;

        return !$updatedAt instanceof \DateTimeImmutable || $updatedAt > $this->clock->now()->modify(sprintf('-%d minutes', $this->checkoutIdleMinutes));
    }

    /**
     * The cart of a quote stays with the customer who started it: a registered customer's cart
     * is never handed to somebody else (forwarded link), a guest cart only to another guest.
     */
    private function mayResume(OrderInterface $cart, ?CustomerInterface $customer): bool
    {
        $owner = $cart->getCustomer();
        if (!$owner instanceof \Sylius\Component\Customer\Model\CustomerInterface || $owner === $customer) {
            return true;
        }

        return !$customer instanceof \Sylius\Component\Core\Model\CustomerInterface && $owner instanceof CustomerInterface && !$owner->getUser() instanceof \Sylius\Component\User\Model\UserInterface;
    }
}
