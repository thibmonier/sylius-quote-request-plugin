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

    public function getEffectiveUnitPrice(): int
    {
        return $this->unitPrice ?? $this->catalogUnitPrice;
    }

    public function getSubtotal(): int
    {
        return $this->getEffectiveUnitPrice() * $this->quantity;
    }
}
