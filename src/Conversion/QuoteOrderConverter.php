<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Conversion;

use Doctrine\Persistence\ObjectManager;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteCartManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
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
        private readonly ObjectManager $objectManager,
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
        $customer = $this->resolveCustomer($quoteRequest);

        if (QuoteRequestStatuses::ACCEPTED === $quoteRequest->getStatus()) {
            $cart = $this->quoteCartManager->start($quoteRequest, $customer, false);
        } else {
            $cart = $this->quoteRequestManager->accept($quoteRequest, $customer, false);
        }

        $cart->setCustomer($customer);
        $billingAddress = clone $address;
        $billingAddress->setCustomer(null);
        $shippingAddress = clone $billingAddress;
        $cart->setBillingAddress($billingAddress);
        $cart->setShippingAddress($shippingAddress);
        $this->applyCheckout($cart, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        $this->orderProcessor->process($cart);

        if (null !== $shippingMethod && $cart->hasShipments()) {
            foreach ($cart->getShipments() as $shipment) {
                $shipment->setMethod($shippingMethod);
            }
        }
        $this->applyFirstPossible($cart, [OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING, OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING]);
        $this->orderProcessor->process($cart);

        if (null !== $paymentMethod) {
            $cart->getLastPayment()?->setMethod($paymentMethod);
        }
        $this->applyFirstPossible($cart, [OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT, OrderCheckoutTransitions::TRANSITION_SKIP_PAYMENT]);

        // Completing the checkout marks the quote "ordered" (OrderCheckoutCompletedListener).
        $this->applyCheckout($cart, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->objectManager->flush();

        return $cart;
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
            $this->objectManager->persist($customer);
        }

        return $customer;
    }

    /** @param list<string> $transitions */
    private function applyFirstPossible(OrderInterface $cart, array $transitions): void
    {
        foreach ($transitions as $transition) {
            if ($this->stateMachine->can($cart, OrderCheckoutTransitions::GRAPH, $transition)) {
                $this->stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, $transition);

                return;
            }
        }

        throw new \RuntimeException(sprintf('None of the "%s" checkout transitions can be applied to the quote cart (checkout state "%s").', implode('", "', $transitions), (string) $cart->getCheckoutState()));
    }

    private function applyCheckout(OrderInterface $cart, string $transition): void
    {
        $this->applyFirstPossible($cart, [$transition]);
    }
}
