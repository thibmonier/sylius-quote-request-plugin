<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

class QuoteRequestRepository extends EntityRepository implements QuoteRequestRepositoryInterface
{
    public function findOneByToken(string $token): ?QuoteRequestInterface
    {
        if ('' === $token) {
            return null;
        }

        $quoteRequest = $this->findOneBy(['token' => $token]);

        // The database comparison may be case-insensitive (MySQL collations): compare again.
        if (!$quoteRequest instanceof QuoteRequestInterface || !hash_equals((string) $quoteRequest->getToken(), $token)) {
            return null;
        }

        return $quoteRequest;
    }

    public function findOneByNumberAndCustomer(string $number, CustomerInterface $customer): ?QuoteRequestInterface
    {
        $quoteRequest = $this->findOneBy(['number' => $number, 'customer' => $customer]);

        return $quoteRequest instanceof QuoteRequestInterface ? $quoteRequest : null;
    }

    public function findOneByOrder(OrderInterface $order): ?QuoteRequestInterface
    {
        if (null === $order->getId()) {
            return null;
        }

        $quoteRequest = $this->findOneBy(['order' => $order]);

        return $quoteRequest instanceof QuoteRequestInterface ? $quoteRequest : null;
    }

    public function findByCustomer(CustomerInterface $customer): array
    {
        /** @var list<QuoteRequestInterface> $quoteRequests */
        $quoteRequests = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;

        return $quoteRequests;
    }

    public function findExpiredProposals(\DateTimeInterface $now): array
    {
        /** @var list<QuoteRequestInterface> $quoteRequests */
        $quoteRequests = $this->createQueryBuilder('o')
            ->andWhere('o.status = :status')
            ->andWhere('o.validUntil IS NOT NULL')
            ->andWhere('o.validUntil < :now')
            ->setParameter('status', QuoteRequestStatuses::PROPOSED)
            ->setParameter('now', \DateTimeImmutable::createFromInterface($now))
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $quoteRequests;
    }

    public function createListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('channel')
            ->addSelect('customer')
            ->innerJoin('o.channel', 'channel')
            ->leftJoin('o.customer', 'customer')
        ;
    }
}
