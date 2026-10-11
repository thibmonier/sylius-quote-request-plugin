<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Manager;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * Single entry point of every change of a quote request: applies the workflow transition, sets
 * the dates, flushes, then dispatches the {@see \Tmonier\SyliusQuoteRequestPlugin\Event\QuoteRequestEvents}.
 */
interface QuoteRequestManagerInterface
{
    public function submit(QuoteRequestInterface $quoteRequest, ?QuoteMessageInterface $message = null): void;

    public function can(QuoteRequestInterface $quoteRequest, string $transition): bool;

    /** @throws QuoteTransitionException */
    public function apply(QuoteRequestInterface $quoteRequest, string $transition, ?QuoteMessageInterface $message = null): void;

    /**
     * Accepts the proposal and returns the cart holding the negotiated prices (also stored as
     * the shop cart of the current session unless `$storeInSession` is false).
     *
     * @throws QuoteTransitionException
     */
    public function accept(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null, bool $storeInSession = true): OrderInterface;

    /**
     * Applies the "order" transition without flushing nor event: called while the order
     * completes its checkout, the change and the "ordered" event follow the order flush.
     */
    public function markOrdered(QuoteRequestInterface $quoteRequest): void;

    /**
     * Back to the cart of an accepted quote (reused, or a new one when it is gone).
     *
     * @throws QuoteTransitionException
     */
    public function startCheckout(QuoteRequestInterface $quoteRequest, ?CustomerInterface $customer = null): OrderInterface;

    /**
     * Runs the operation in a transaction holding a lock on the quote row; the plugin events are
     * dispatched once committed. On failure everything is rolled back and the entity manager is
     * cleared. Nested calls join the running transaction. A decision on the quote cart (accept,
     * checkout start, conversion) also checks and bumps the quote version.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(QuoteRequestInterface $quoteRequest, callable $operation, bool $decision = false): mixed;

    public function postMessage(QuoteRequestInterface $quoteRequest, QuoteMessageInterface $message): void;

    /** Saves the merchant changes (lines, prices, conditions) without changing the status. */
    public function save(QuoteRequestInterface $quoteRequest): void;
}
