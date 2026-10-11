<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Twig;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Symfony\Component\HttpFoundation\UrlHelper;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteOrderabilityCheckerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\OrderProcessing\QuoteOrderResolverInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteTotals;
use Tmonier\SyliusQuoteRequestPlugin\Pricing\QuoteTotalsCalculatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class QuoteRequestExtension extends AbstractExtension
{
    private const STATUS_COLORS = [
        QuoteRequestStatuses::NEW => 'blue',
        QuoteRequestStatuses::IN_REVIEW => 'azure',
        QuoteRequestStatuses::PROPOSED => 'yellow',
        QuoteRequestStatuses::ACCEPTED => 'lime',
        QuoteRequestStatuses::ORDERED => 'green',
        QuoteRequestStatuses::DECLINED => 'red',
        QuoteRequestStatuses::EXPIRED => 'secondary',
        QuoteRequestStatuses::CANCELLED => 'secondary',
    ];

    /** @var \WeakMap<QuoteRequestInterface, QuoteTotals> */
    private \WeakMap $totals;

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly QuoteTotalsCalculatorInterface $totalsCalculator,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteOrderResolverInterface $quoteOrderResolver,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
        private readonly QuoteOrderabilityCheckerInterface $orderabilityChecker,
        private readonly array $settings,
        private readonly ?string $baseUrl = null,
        private readonly ?UrlHelper $urlHelper = null,
        private readonly bool $unsecuredUrls = false,
    ) {
        $this->totals = new \WeakMap();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_quote_request_totals', $this->totals(...)),
            new TwigFunction('tmonier_quote_request_can', $this->quoteRequestManager->can(...)),
            new TwigFunction('tmonier_quote_request_cart_quote', $this->cartQuote(...)),
            new TwigFunction('tmonier_quote_request_setting', $this->setting(...)),
            new TwigFunction('tmonier_quote_request_pdf_available', $this->pdfGenerator->isAvailable(...)),
            new TwigFunction('tmonier_quote_request_absolute_url', $this->absoluteUrl(...)),
            new TwigFunction('tmonier_quote_request_unorderable_lines', $this->orderabilityChecker->getUnorderableLines(...)),
            new TwigFunction('tmonier_quote_request_has_volume_discount', static fn (QuoteRequestInterface $quoteRequest): bool => $quoteRequest->getLines()->exists(static fn (int $key, QuoteRequestLineInterface $line): bool => $line->getUnitDiscount() > 0)),
            new TwigFunction('tmonier_quote_request_status_color', static fn (string $status): string => self::STATUS_COLORS[$status] ?? 'secondary'),
            new TwigFunction('tmonier_quote_request_shows_prices', static fn (QuoteRequestInterface $quoteRequest): bool => in_array($quoteRequest->getStatus(), QuoteRequestStatuses::WITH_PROPOSAL, true)),
        ];
    }

    public function totals(QuoteRequestInterface $quoteRequest): QuoteTotals
    {
        return $this->totals[$quoteRequest] ??= $this->totalsCalculator->calculate($quoteRequest);
    }

    public function cartQuote(mixed $cart): ?QuoteRequestInterface
    {
        return $cart instanceof OrderInterface ? $this->quoteOrderResolver->resolveLockingQuote($cart) : null;
    }

    /**
     * Absolute URL for the e-mails: `base_url` when configured, the channel hostname otherwise
     * (scheme following `sylius.unsecured_urls`), as `sylius_channel_url` does; without either,
     * the Symfony URL helper (router default URI, or the request: set framework.trusted_hosts).
     */
    public function absoluteUrl(string $path, ?ChannelInterface $channel = null): string
    {
        if (null !== $this->baseUrl && '' !== $this->baseUrl) {
            return rtrim($this->baseUrl, '/') . $path;
        }

        $hostname = $channel?->getHostname();
        if (null !== $hostname && '' !== $hostname) {
            return ($this->unsecuredUrls ? 'http://' : 'https://') . $hostname . $path;
        }

        return null !== $this->urlHelper ? $this->urlHelper->getAbsoluteUrl($path) : $path;
    }

    public function setting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }
}
