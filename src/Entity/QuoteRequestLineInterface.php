<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ResourceInterface;

interface QuoteRequestLineInterface extends ResourceInterface
{
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

    public function getEffectiveUnitPrice(): int;

    public function getSubtotal(): int;
}
