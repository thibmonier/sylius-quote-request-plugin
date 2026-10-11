<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

interface QuoteRequestInterface extends ResourceInterface
{
    public const DISCOUNT_ADJUSTMENT_ORIGIN = 'tmonier_quote_request';

    public function getId(): ?int;

    public function getNumber(): ?string;

    public function setNumber(?string $number): void;

    public function getStatus(): string;

    public function setStatus(string $status): void;

    public function getToken(): ?string;

    public function setToken(?string $token): void;

    public function getChannel(): ?ChannelInterface;

    public function setChannel(?ChannelInterface $channel): void;

    public function getLocaleCode(): ?string;

    public function setLocaleCode(?string $localeCode): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getEmail(): ?string;

    public function setEmail(?string $email): void;

    public function getCompany(): ?string;

    public function setCompany(?string $company): void;

    public function getFirstName(): ?string;

    public function setFirstName(?string $firstName): void;

    public function getLastName(): ?string;

    public function setLastName(?string $lastName): void;

    public function getFullName(): string;

    public function getPhoneNumber(): ?string;

    public function setPhoneNumber(?string $phoneNumber): void;

    public function getVatNumber(): ?string;

    public function setVatNumber(?string $vatNumber): void;

    /** Fixed discount on the whole quote, in the quote currency (minor units). */
    public function getDiscountAmount(): ?int;

    public function setDiscountAmount(?int $discountAmount): void;

    /** Percentage discount on the whole quote (0-100), used when no fixed amount is set. */
    public function getDiscountPercentage(): ?float;

    public function setDiscountPercentage(?float $discountPercentage): void;

    public function getValidUntil(): ?\DateTimeImmutable;

    public function setValidUntil(?\DateTimeImmutable $validUntil): void;

    public function isValidAt(\DateTimeInterface $date): bool;

    public function getInternalNote(): ?string;

    public function setInternalNote(?string $internalNote): void;

    /** The cart created when the quote was accepted, then the order once checked out. */
    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    /** @return Collection<int, QuoteRequestLineInterface> */
    public function getLines(): Collection;

    public function hasLine(QuoteRequestLineInterface $line): bool;

    public function addLine(QuoteRequestLineInterface $line): void;

    public function removeLine(QuoteRequestLineInterface $line): void;

    /** @return Collection<int, QuoteMessageInterface> */
    public function getMessages(): Collection;

    public function addMessage(QuoteMessageInterface $message): void;

    public function removeMessage(QuoteMessageInterface $message): void;

    public function getCreatedAt(): ?\DateTimeImmutable;

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void;

    public function getUpdatedAt(): ?\DateTimeImmutable;

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void;

    public function getProposedAt(): ?\DateTimeImmutable;

    public function setProposedAt(?\DateTimeImmutable $proposedAt): void;

    public function getAcceptedAt(): ?\DateTimeImmutable;

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): void;

    public function getOrderedAt(): ?\DateTimeImmutable;

    public function setOrderedAt(?\DateTimeImmutable $orderedAt): void;
}
