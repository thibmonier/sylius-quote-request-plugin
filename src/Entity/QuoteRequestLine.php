<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;

class QuoteRequestLine implements QuoteRequestLineInterface
{
    protected ?int $id = null;

    protected ?QuoteRequestInterface $quoteRequest = null;

    protected ?ProductVariantInterface $variant = null;

    protected ?string $productName = null;

    protected ?string $variantName = null;

    protected ?string $variantCode = null;

    protected int $quantity = 1;

    protected ?int $requestedQuantity = null;

    protected int $catalogUnitPrice = 0;

    protected ?int $unitPrice = null;

    protected ?string $discountType = null;

    /** @var list<array{min_quantity: int, value: float}>|null */
    protected ?array $discountTiers = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuoteRequest(): ?QuoteRequestInterface
    {
        return $this->quoteRequest;
    }

    public function setQuoteRequest(?QuoteRequestInterface $quoteRequest): void
    {
        $this->quoteRequest = $quoteRequest;
    }

    public function getVariant(): ?ProductVariantInterface
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariantInterface $variant): void
    {
        $this->variant = $variant;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): void
    {
        $this->productName = $productName;
    }

    public function getVariantName(): ?string
    {
        return $this->variantName;
    }

    public function setVariantName(?string $variantName): void
    {
        $this->variantName = $variantName;
    }

    public function getVariantCode(): ?string
    {
        return $this->variantCode;
    }

    public function setVariantCode(?string $variantCode): void
    {
        $this->variantCode = $variantCode;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getRequestedQuantity(): ?int
    {
        return $this->requestedQuantity;
    }

    public function setRequestedQuantity(?int $requestedQuantity): void
    {
        $this->requestedQuantity = $requestedQuantity;
    }

    public function getCatalogUnitPrice(): int
    {
        return $this->catalogUnitPrice;
    }

    public function setCatalogUnitPrice(int $catalogUnitPrice): void
    {
        $this->catalogUnitPrice = $catalogUnitPrice;
    }

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?int $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function getDiscountType(): ?string
    {
        return $this->discountType;
    }

    public function setDiscountType(?string $discountType): void
    {
        $this->discountType = $discountType;
    }

    public function getDiscountTiers(): array
    {
        // JSON columns may come back with other key orders or integer values.
        return array_map(
            static fn (array $tier): array => ['min_quantity' => (int) $tier['min_quantity'], 'value' => (float) $tier['value']],
            $this->discountTiers ?? [],
        );
    }

    public function setDiscountTiers(array $discountTiers): void
    {
        usort($discountTiers, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);
        $this->discountTiers = $discountTiers;
    }

    public function getApplicableDiscountTier(): ?array
    {
        if (null === $this->discountType) {
            return null;
        }

        $applicable = null;
        foreach ($this->getDiscountTiers() as $tier) {
            if ($tier['min_quantity'] <= $this->quantity && $tier['value'] > 0) {
                $applicable = $tier;
            }
        }

        return $applicable;
    }

    public function getBaseUnitPrice(): int
    {
        return $this->unitPrice ?? $this->catalogUnitPrice;
    }

    public function getUnitDiscount(): int
    {
        $tier = $this->getApplicableDiscountTier();
        if (null === $tier) {
            return 0;
        }

        $base = $this->getBaseUnitPrice();
        $discount = self::DISCOUNT_PERCENTAGE === $this->discountType
            ? (int) round($base * min($tier['value'], 100.0) / 100)
            : (int) round($tier['value'] * 100);

        return max(0, min($discount, $base));
    }

    public function getEffectiveUnitPrice(): int
    {
        return $this->getBaseUnitPrice() - $this->getUnitDiscount();
    }

    public function getSubtotal(): int
    {
        return $this->getEffectiveUnitPrice() * $this->quantity;
    }
}
