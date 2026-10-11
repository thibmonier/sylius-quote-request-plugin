<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Conversion;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

interface QuoteOrderConverterInterface
{
    public function canConvert(QuoteRequestInterface $quoteRequest): bool;

    /**
     * Places an order from a proposed (accepted on the customer's behalf) or accepted quote: the
     * quote cart goes through the checkout steps with the given address and methods (null: the
     * default method resolved by the Sylius order processing). The payment stays awaiting.
     *
     * @throws \Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException
     * @throws \RuntimeException when a checkout step cannot be applied
     */
    public function convert(
        QuoteRequestInterface $quoteRequest,
        AddressInterface $address,
        ?ShippingMethodInterface $shippingMethod,
        ?PaymentMethodInterface $paymentMethod,
    ): OrderInterface;
}
