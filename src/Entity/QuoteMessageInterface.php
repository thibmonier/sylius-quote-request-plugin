<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

interface QuoteMessageInterface extends ResourceInterface
{
    public const AUTHOR_CUSTOMER = 'customer';

    public const AUTHOR_ADMIN = 'admin';

    public const MAX_LENGTH = 5000;

    public function getId(): ?int;

    public function getQuoteRequest(): ?QuoteRequestInterface;

    public function setQuoteRequest(?QuoteRequestInterface $quoteRequest): void;

    public function getAuthorType(): string;

    public function setAuthorType(string $authorType): void;

    public function isFromCustomer(): bool;

    public function getAuthorName(): ?string;

    public function setAuthorName(?string $authorName): void;

    public function getBody(): ?string;

    public function setBody(?string $body): void;

    public function getCreatedAt(): ?\DateTimeImmutable;

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void;
}
