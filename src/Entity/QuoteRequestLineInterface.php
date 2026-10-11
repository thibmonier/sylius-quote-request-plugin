<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ResourceInterface;

interface QuoteRequestLineInterface extends ResourceInterface
{
    /** Volume discount: percentage of the unit price. */
    public const DISCOUNT_PERCENTAGE = 'percentage';

    /** Volume discount: amount off each unit (major units, e.g. 1.50). */
    public const DISCOUNT_AMOUNT = 'amount';

    public function getId(): ?int;

    public function getQuoteRequest(): ?QuoteRequestInterface;

    public function setQuoteRequest(?QuoteRequestInterface $quoteRequest): void;

    /** Null when the variant was deleted after the request: the line can no longer be ordered. */
    public function getVariant(): ?ProductVariantInterface;

    public function setVariant(?ProductVariantInterface $variant): void;

    public function getProductName(): ?string;

    public function setProductName(?string $productName): void;

    public function getVariantName(): ?string;

    public function setVariantName(?string $variantName): void;

    public function getVariantCode(): ?string;

    public function setVariantCode(?string $variantCode): void;

    public function getQuantity(): int;

    public function setQuantity(int $quantity): void;

    /** Quantity asked by the customer, kept when the merchant changes the quantity. */
    public function getRequestedQuantity(): ?int;

    public function setRequestedQuantity(?int $requestedQuantity): void;

    /** Channel price of the variant when the line was added. */
    public function getCatalogUnitPrice(): int;

    public function setCatalogUnitPrice(int $catalogUnitPrice): void;

    /** Negotiated unit price; null keeps the catalog price. */
    public function getUnitPrice(): ?int;

    public function setUnitPrice(?int $unitPrice): void;

    public function getDiscountType(): ?string;

    public function setDiscountType(?string $discountType): void;

    /**
     * Volume discount tiers, applied from their minimum quantity (the highest reachable tier
     * wins). Values are percentages or amounts in major units, according to the discount type.
     *
     * @return list<array{min_quantity: int, value: float}>
     */
    public function getDiscountTiers(): array;

    /** @param list<array{min_quantity: int, value: float}> $discountTiers */
    public function setDiscountTiers(array $discountTiers): void;

    /** @return array{min_quantity: int, value: float}|null */
    public function getApplicableDiscountTier(): ?array;

    /** Negotiated price before the volume discount (catalog price when not negotiated). */
    public function getBaseUnitPrice(): int;

    /** Volume discount on one unit, never more than the base unit price. */
    public function getUnitDiscount(): int;

    /** Price locked in the order: base unit price minus the volume discount. */
    public function getEffectiveUnitPrice(): int;

    public function getSubtotal(): int;
}
