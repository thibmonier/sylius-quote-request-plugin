<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Fixture;

use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * Demo quote requests in every status of the workflow, for the customers and products of the
 * Sylius fixtures. Usage in a suite: `tmonier_quote_request: { options: { amount: 8 } }`.
 */
final class QuoteRequestFixture extends AbstractFixture
{
    private const SCENARIOS = [
        QuoteRequestStatuses::NEW,
        QuoteRequestStatuses::IN_REVIEW,
        QuoteRequestStatuses::PROPOSED,
        QuoteRequestStatuses::ACCEPTED,
        QuoteRequestStatuses::DECLINED,
        QuoteRequestStatuses::EXPIRED,
        QuoteRequestStatuses::CANCELLED,
        QuoteRequestStatuses::NEW,
    ];

    private const COMPANIES = ['Acme Industries', 'Globex Corporation', 'Initech', 'Umbrella Supplies', 'Stark Logistics', 'Wayne Office Furniture', 'Hooli Services', 'Vandelay Imports'];

    /**
     * @param RepositoryInterface<ChannelInterface> $channelRepository
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     * @param RepositoryInterface<ProductVariantInterface> $variantRepository
     */
    public function __construct(
        private readonly QuoteRequestFactoryInterface $factory,
        private readonly QuoteRequestManagerInterface $manager,
        private readonly ObjectManager $objectManager,
        private readonly RepositoryInterface $channelRepository,
        private readonly RepositoryInterface $customerRepository,
        private readonly RepositoryInterface $variantRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getName(): string
    {
        return 'tmonier_quote_request';
    }

    /** @param array<string, mixed> $options */
    public function load(array $options): void
    {
        /** @var int $amount */
        $amount = $options['amount'];
        /** @var list<ChannelInterface> $channels */
        $channels = $this->channelRepository->findAll();
        /** @var list<CustomerInterface> $customers */
        $customers = array_values(array_filter(
            $this->customerRepository->findBy([], ['id' => 'ASC'], 20),
            static fn (CustomerInterface $customer): bool => null !== $customer->getUser(),
        ));
        /** @var list<ProductVariantInterface> $variants */
        $variants = $this->variantRepository->findBy(['enabled' => true], ['id' => 'ASC'], 60);

        $channel = $channels[0] ?? null;
        if (null === $channel || [] === $variants) {
            return;
        }

        for ($index = 0; $index < $amount; ++$index) {
            $status = self::SCENARIOS[$index % count(self::SCENARIOS)];
            // Every other request comes from a guest.
            $customer = 0 === $index % 2 ? ($customers[$index % max(1, count($customers))] ?? null) : null;
            $this->createQuote($channel, $customer, $variants, $index, $status);
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('amount')->defaultValue(8)->min(0)->end()
            ->end()
        ;
    }

    /** @param list<ProductVariantInterface> $variants */
    private function createQuote(ChannelInterface $channel, ?CustomerInterface $customer, array $variants, int $index, string $status): void
    {
        $localeCode = (string) $channel->getDefaultLocale()?->getCode();
        $quote = $this->factory->createForChannel($channel, $localeCode, $customer);
        $quote->setCompany(self::COMPANIES[$index % count(self::COMPANIES)]);
        if (null === $customer) {
            $quote->setEmail(sprintf('buyer%d@example.com', $index + 1));
            $quote->setFirstName('Alex');
            $quote->setLastName(sprintf('Buyer %d', $index + 1));
            $quote->setPhoneNumber('+33 1 23 45 67 89');
        }
        $quote->setVatNumber(0 === $index % 3 ? sprintf('FR%011d', 40303265045 + $index) : null);
        $quote->setCreatedAt($this->clock->now()->modify(sprintf('-%d days', 2 * ($index + 1))));

        for ($line = 0; $line < 2 + $index % 3; ++$line) {
            $variant = $variants[($index * 3 + $line * 7) % count($variants)];
            $quoteLine = $this->factory->createLine($quote, $variant, 10 * ($line + 1) + 5 * $index);
            if (null !== $quoteLine && !$this->hasVariant($quote, $variant)) {
                $quote->addLine($quoteLine);
            }
        }
        if ($quote->getLines()->isEmpty()) {
            return;
        }

        $this->manager->submit($quote, $this->factory->createMessage(
            QuoteMessageInterface::AUTHOR_CUSTOMER,
            $quote->getFullName(),
            'Hello, we need these products for our new offices. Could you make us an offer for these quantities, with delivery before the end of the month?',
        ));

        if (QuoteRequestStatuses::NEW === $status) {
            return;
        }

        $this->manager->apply($quote, QuoteRequestTransitions::TRANSITION_REVIEW);
        if (QuoteRequestStatuses::CANCELLED === $status) {
            $this->manager->apply($quote, QuoteRequestTransitions::TRANSITION_CANCEL);

            return;
        }

        foreach ($quote->getLines() as $quoteLine) {
            $quoteLine->setUnitPrice((int) round($quoteLine->getCatalogUnitPrice() * 0.85));
            if (0 === $index % 3) {
                $quoteLine->setDiscountType(QuoteRequestLineInterface::DISCOUNT_PERCENTAGE);
                $quoteLine->setDiscountTiers([['min_quantity' => 20, 'value' => 3.0], ['min_quantity' => 50, 'value' => 6.0]]);
            }
        }
        $quote->setDiscountPercentage(0 === $index % 2 ? 5.0 : null);
        $quote->setInternalNote('Strategic account: keep the margin above 20%.');
        $this->manager->save($quote);

        if (QuoteRequestStatuses::IN_REVIEW === $status) {
            return;
        }

        $proposal = $this->factory->createMessage(QuoteMessageInterface::AUTHOR_ADMIN, 'Sales team', 'Please find our best prices for these quantities. Delivery within 10 working days.');
        $this->manager->apply($quote, QuoteRequestTransitions::TRANSITION_PROPOSE, $proposal);

        match ($status) {
            QuoteRequestStatuses::ACCEPTED => $this->manager->accept($quote, $quote->getCustomer(), false),
            QuoteRequestStatuses::DECLINED => $this->manager->apply($quote, QuoteRequestTransitions::TRANSITION_DECLINE),
            QuoteRequestStatuses::EXPIRED => $this->expire($quote),
            default => null,
        };
    }

    private function expire(QuoteRequestInterface $quote): void
    {
        $quote->setValidUntil($this->clock->now()->modify('-1 day'));
        $this->objectManager->flush();
        $this->manager->apply($quote, QuoteRequestTransitions::TRANSITION_EXPIRE);
    }

    private function hasVariant(QuoteRequestInterface $quote, ProductVariantInterface $variant): bool
    {
        foreach ($quote->getLines() as $line) {
            if ($line->getVariant() === $variant) {
                return true;
            }
        }

        return false;
    }
}
