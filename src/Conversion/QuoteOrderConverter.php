<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Conversion;

use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
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
            $customer = $this->resolveCustomer($quoteRequest);

            if (QuoteRequestStatuses::ACCEPTED === $quoteRequest->getStatus()) {
                // Never complete a cart the customer may be checking out: start a dedicated one.
                $this->quoteCartManager->release($quoteRequest);
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

            // Sylius may have skipped the shipping step already (nothing to ship, single method).
            if (OrderCheckoutStates::STATE_ADDRESSED === $cart->getCheckoutState()) {
                if (null !== $shippingMethod && $cart->hasShipments()) {
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
        });
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
