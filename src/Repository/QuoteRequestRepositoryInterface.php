<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * @extends RepositoryInterface<QuoteRequestInterface>
 */
interface QuoteRequestRepositoryInterface extends RepositoryInterface
{
    public function findOneByToken(string $token): ?QuoteRequestInterface;

    public function findOneByNumberAndCustomer(string $number, CustomerInterface $customer): ?QuoteRequestInterface;

    public function findOneByOrder(OrderInterface $order): ?QuoteRequestInterface;

    /** @return list<QuoteRequestInterface> */
    public function findByCustomer(CustomerInterface $customer): array;

    /**
     * Proposals whose validity date is over.
     *
     * @return list<QuoteRequestInterface>
     */
    public function findExpiredProposals(\DateTimeInterface $now): array;

    /** Admin grid. */
    public function createListQueryBuilder(): QueryBuilder;
}
