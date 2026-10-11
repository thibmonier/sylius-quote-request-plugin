<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Conversion;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteOrderabilityCheckerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;
use Webmozart\Assert\Assert;

final class QuoteOrderConverter implements QuoteOrderConverterInterface
{
    /**
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     * @param FactoryInterface<CustomerInterface> $customerFactory
     */
    public function __construct(
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteCartManagerInterface $quoteCartManager,
        private readonly StateMachineInterface $stateMachine,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly RepositoryInterface $customerRepository,
        private readonly FactoryInterface $customerFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly QuoteOrderabilityCheckerInterface $orderabilityChecker,
        private readonly ClockInterface $clock,
        private readonly int $checkoutIdleMinutes = 30,
    ) {
    }

    public function canConvert(QuoteRequestInterface $quoteRequest): bool
    {
        return QuoteRequestStatuses::ACCEPTED === $quoteRequest->getStatus() ||
            $this->quoteRequestManager->can($quoteRequest, QuoteRequestTransitions::TRANSITION_ACCEPT);
    }

    public function convert(
        QuoteRequestInterface $quoteRequest,
        AddressInterface $address,
        ?ShippingMethodInterface $shippingMethod,
        ?PaymentMethodInterface $paymentMethod,
    ): OrderInterface {
        // One transaction holding the quote lock: a failed step leaves the quote as it was, and
        // the accepted / ordered events are dispatched only once the order exists.
        return $this->quoteRequestManager->transactional($quoteRequest, function () use ($quoteRequest, $address, $shippingMethod, $paymentMethod): OrderInterface {
            // An accepted quote is not re-checked by the accept guard: every line must still be
            // orderable, never place a partial or empty order.
            $unorderable = $this->orderabilityChecker->getUnorderableLines($quoteRequest);
            if ([] !== $unorderable) {
                throw new \RuntimeException(sprintf('These products can no longer be ordered: %s.', implode(', ', array_map(
                    static fn (QuoteRequestLineInterface $line): string => (string) ($line->getProductName() ?? $line->getVariantCode()),
                    $unorderable,
                ))));
            }

            $customer = $this->resolveCustomer($quoteRequest);

            if (QuoteRequestStatuses::ACCEPTED === $quoteRequest->getStatus()) {
                $this->takeOverCustomerCart($quoteRequest);
                $cart = $this->quoteCartManager->start($quoteRequest, $customer, false);
            } else {
                $cart = $this->quoteRequestManager->accept($quoteRequest, $customer, false);
            }

            $cart->setCustomer($customer);
            $billingAddress = clone $address;
            $billingAddress->setCustomer(null);
            $cart->setBillingAddress($billingAddress);
            $cart->setShippingAddress(clone $billingAddress);
            $this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_ADDRESS, true);
            $this->orderProcessor->process($cart);

            // The chosen method, whatever Sylius did on the address step (it may have skipped the
            // shipping step with its own single eligible method).
            $this->useShippingMethod($cart, $shippingMethod);

            if (OrderCheckoutStates::STATE_ADDRESSED === $cart->getCheckoutState()) {
                if (!$this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING)) {
                    $this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING, true);
                }
                $this->orderProcessor->process($cart);
            }

            // Same for the payment step (nothing to pay, single method).
            if (in_array($cart->getCheckoutState(), [OrderCheckoutStates::STATE_SHIPPING_SELECTED, OrderCheckoutStates::STATE_SHIPPING_SKIPPED], true)) {
                if (null !== $paymentMethod) {
                    $cart->getLastPayment()?->setMethod($paymentMethod);
                }
                if (!$this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT)) {
                    $this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_SKIP_PAYMENT, true);
                }
            }

            $this->useShippingMethod($cart, $shippingMethod);
            if ($cart->getItems()->isEmpty() || $cart->getItems()->count() !== $quoteRequest->getLines()->count()) {
                throw new \RuntimeException('The cart of the quote does not hold all the quote lines.');
            }

            // The checks of the shop checkout: products still sold, stock, eligible methods.
            $violations = $this->validator->validate($cart, null, ['sylius_checkout_complete']);
            if (count($violations) > 0) {
                $messages = [];
                foreach ($violations as $violation) {
                    $messages[] = (string) $violation->getMessage();
                }

                throw new \RuntimeException(implode(' ', array_unique($messages)));
            }

            // Completing the checkout marks the quote "ordered" (OrderCheckoutCompletedListener).
            $this->applyIfPossible($cart, OrderCheckoutTransitions::TRANSITION_COMPLETE, true);
            $this->entityManager->flush();

            return $cart;
        }, true);
    }

    private function resolveCustomer(QuoteRequestInterface $quoteRequest): CustomerInterface
    {
        $customer = $quoteRequest->getCustomer();
        if (null !== $customer) {
            return $customer;
        }

        $email = $quoteRequest->getEmail();
        Assert::notNull($email);

        $customer = $this->customerRepository->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if (null === $customer) {
            $customer = $this->customerFactory->createNew();
            $customer->setEmail($email);
            $customer->setFirstName($quoteRequest->getFirstName());
            $customer->setLastName($quoteRequest->getLastName());
            $customer->setPhoneNumber($quoteRequest->getPhoneNumber());
            $this->entityManager->persist($customer);
        }

        return $customer;
    }

    private function useShippingMethod(OrderInterface $cart, ?ShippingMethodInterface $shippingMethod): void
    {
        if (null === $shippingMethod || !$cart->hasShipments()) {
            return;
        }

        foreach ($cart->getShipments() as $shipment) {
            $shipment->setMethod($shippingMethod);
        }
        $this->orderProcessor->process($cart);
        foreach ($cart->getShipments() as $shipment) {
            if ($shipment->getMethod() !== $shippingMethod) {
                throw new \RuntimeException(sprintf('The shipping method "%s" is not available for this address.', (string) $shippingMethod->getName()));
            }
        }
    }

    /**
     * The merchant places the order of an accepted quote in a dedicated cart. The customer's
     * quote cart is emptied (no duplicate order) unless the customer is in its checkout: then
     * the conversion is refused until the cart has been idle for `checkout_idle_minutes`. The
     * cart row is locked so that a checkout step of the customer waits for the conversion; a
     * late write of the customer cannot keep quote prices (QuotePriceLockProcessor and the
     * completion guards).
     */
    private function takeOverCustomerCart(QuoteRequestInterface $quoteRequest): void
    {
        $cart = $quoteRequest->getOrder();
        if (null === $cart || null === $cart->getId()) {
            return;
        }

        $table = $this->entityManager->getClassMetadata($cart::class)->getTableName();
        $row = $this->entityManager->getConnection()->fetchAssociative(sprintf('SELECT state, checkout_state, updated_at FROM %s WHERE id = ? FOR UPDATE', $table), [$cart->getId()]);
        if (!is_array($row) || OrderInterface::STATE_CART !== $row['state']) {
            return;
        }
        // A checkout in progress is left alone; an abandoned one (idle) is taken over.
        $updatedAt = is_string($row['updated_at'] ?? null) ? new \DateTimeImmutable($row['updated_at']) : null;
        $idleSince = $this->clock->now()->modify(sprintf('-%d minutes', $this->checkoutIdleMinutes));
        if (OrderCheckoutStates::STATE_CART !== $row['checkout_state'] && (null === $updatedAt || $updatedAt > $idleSince)) {
            throw new \RuntimeException(sprintf('The customer is checking out this quote: wait for the order, retry once the cart has been idle for %d minutes, or cancel the quote.', $this->checkoutIdleMinutes));
        }

        $this->quoteCartManager->release($quoteRequest, true);
    }

    private function applyIfPossible(OrderInterface $cart, string $transition, bool $required = false): bool
    {
        if ($this->stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);

            return true;
        }
        if ($required) {
            throw new \RuntimeException(sprintf('The "%s" checkout step cannot be applied to the quote cart (checkout state "%s").', $transition, (string) $cart->getCheckoutState()));
        }

        return false;
    }
}
