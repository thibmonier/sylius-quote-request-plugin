<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

class QuoteMessage implements QuoteMessageInterface
{
    protected ?int $id = null;

    protected ?QuoteRequestInterface $quoteRequest = null;

    protected string $authorType = self::AUTHOR_CUSTOMER;

    protected ?string $authorName = null;

    protected ?string $body = null;

    protected ?\DateTimeImmutable $createdAt = null;

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

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function setAuthorType(string $authorType): void
    {
        $this->authorType = $authorType;
    }

    public function isFromCustomer(): bool
    {
        return self::AUTHOR_CUSTOMER === $this->authorType;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): void
    {
        $this->authorName = $authorName;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): void
    {
        $this->body = $body;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
