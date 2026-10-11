<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

class QuoteRequest implements QuoteRequestInterface
{
    protected ?int $id = null;

    protected ?string $number = null;

    protected string $status = QuoteRequestStatuses::NEW;

    protected ?string $token = null;

    protected ?ChannelInterface $channel = null;

    protected ?string $localeCode = null;

    protected ?string $currencyCode = null;

    protected ?CustomerInterface $customer = null;

    protected ?string $email = null;

    protected ?string $company = null;

    protected ?string $firstName = null;

    protected ?string $lastName = null;

    protected ?string $phoneNumber = null;

    protected ?string $vatNumber = null;

    protected ?int $discountAmount = null;

    protected ?float $discountPercentage = null;

    protected ?\DateTimeImmutable $validUntil = null;

    protected ?string $internalNote = null;

    protected ?OrderInterface $order = null;

    /** @var Collection<int, QuoteRequestLineInterface> */
    protected Collection $lines;

    /** @var Collection<int, QuoteMessageInterface> */
    protected Collection $messages;

    protected ?\DateTimeImmutable $createdAt = null;

    protected ?\DateTimeImmutable $updatedAt = null;

    protected ?\DateTimeImmutable $proposedAt = null;

    protected ?\DateTimeImmutable $acceptedAt = null;

    protected ?\DateTimeImmutable $orderedAt = null;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): void
    {
        $this->number = $number;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getLocaleCode(): ?string
    {
        return $this->localeCode;
    }

    public function setLocaleCode(?string $localeCode): void
    {
        $this->localeCode = $localeCode;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getFullName(): string
    {
        return trim(sprintf('%s %s', (string) $this->firstName, (string) $this->lastName));
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): void
    {
        $this->vatNumber = $vatNumber;
    }

    public function getDiscountAmount(): ?int
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(?int $discountAmount): void
    {
        $this->discountAmount = $discountAmount;
    }

    public function getDiscountPercentage(): ?float
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(?float $discountPercentage): void
    {
        $this->discountPercentage = $discountPercentage;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): void
    {
        // A quote is valid until the end of the day shown to the customer.
        $this->validUntil = $validUntil?->setTime(23, 59, 59);
    }

    public function isValidAt(\DateTimeInterface $date): bool
    {
        return null === $this->validUntil || $this->validUntil >= $date;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): void
    {
        $this->internalNote = $internalNote;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function hasLine(QuoteRequestLineInterface $line): bool
    {
        return $this->lines->contains($line);
    }

    public function addLine(QuoteRequestLineInterface $line): void
    {
        if (!$this->hasLine($line)) {
            $this->lines->add($line);
            $line->setQuoteRequest($this);
        }
    }

    public function removeLine(QuoteRequestLineInterface $line): void
    {
        if ($this->hasLine($line)) {
            $this->lines->removeElement($line);
            $line->setQuoteRequest(null);
        }
    }

    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(QuoteMessageInterface $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setQuoteRequest($this);
        }
    }

    public function removeMessage(QuoteMessageInterface $message): void
    {
        if ($this->messages->contains($message)) {
            $this->messages->removeElement($message);
            $message->setQuoteRequest(null);
        }
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getProposedAt(): ?\DateTimeImmutable
    {
        return $this->proposedAt;
    }

    public function setProposedAt(?\DateTimeImmutable $proposedAt): void
    {
        $this->proposedAt = $proposedAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): void
    {
        $this->acceptedAt = $acceptedAt;
    }

    public function getOrderedAt(): ?\DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function setOrderedAt(?\DateTimeImmutable $orderedAt): void
    {
        $this->orderedAt = $orderedAt;
    }
}
