<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Factory;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Token\TokenGeneratorInterface;

final readonly class QuoteRequestFactory implements QuoteRequestFactoryInterface
{
    /**
     * @param FactoryInterface<QuoteRequestInterface> $quoteRequestFactory
     * @param FactoryInterface<QuoteRequestLineInterface> $lineFactory
     * @param FactoryInterface<QuoteMessageInterface> $messageFactory
     */
    public function __construct(
        private FactoryInterface $quoteRequestFactory,
        private FactoryInterface $lineFactory,
        private FactoryInterface $messageFactory,
        private TokenGeneratorInterface $tokenGenerator,
        private ProductVariantPricesCalculatorInterface $pricesCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function createForChannel(ChannelInterface $channel, string $localeCode, ?CustomerInterface $customer = null): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestFactory->createNew();
        $quoteRequest->setChannel($channel);
        $quoteRequest->setLocaleCode($localeCode);
        $quoteRequest->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        $quoteRequest->setToken($this->tokenGenerator->generate());
        $quoteRequest->setCreatedAt($this->clock->now());

        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            $quoteRequest->setCustomer($customer);
            $quoteRequest->setEmail($customer->getEmail());
            $quoteRequest->setFirstName($customer->getFirstName());
            $quoteRequest->setLastName($customer->getLastName());
            $quoteRequest->setPhoneNumber($customer->getPhoneNumber());
            $address = $customer->getDefaultAddress();
            if ($address instanceof \Sylius\Component\Core\Model\AddressInterface) {
                $quoteRequest->setCompany($address->getCompany());
                $quoteRequest->setPhoneNumber($customer->getPhoneNumber() ?? $address->getPhoneNumber());
            }
        }

        return $quoteRequest;
    }

    public function createLine(QuoteRequestInterface $quoteRequest, ProductVariantInterface $variant, int $quantity): ?QuoteRequestLineInterface
    {
        $channel = $quoteRequest->getChannel();
        if (!$channel instanceof \Sylius\Component\Core\Model\ChannelInterface || !$variant->getChannelPricingForChannel($channel) instanceof \Sylius\Component\Core\Model\ChannelPricingInterface) {
            return null;
        }

        $line = $this->lineFactory->createNew();
        $line->setVariant($variant);
        $line->setProductName($variant->getProduct()?->getName());
        $line->setVariantName($variant->getName());
        $line->setVariantCode($variant->getCode());
        $line->setQuantity(max(1, $quantity));
        $line->setRequestedQuantity(max(1, $quantity));
        $line->setCatalogUnitPrice($this->pricesCalculator->calculate($variant, ['channel' => $channel]));

        return $line;
    }

    public function createMessage(string $authorType, ?string $authorName, string $body): QuoteMessageInterface
    {
        $message = $this->messageFactory->createNew();
        $message->setAuthorType($authorType);
        $message->setAuthorName($authorName);
        $message->setBody($body);
        $message->setCreatedAt($this->clock->now());

        return $message;
    }
}
