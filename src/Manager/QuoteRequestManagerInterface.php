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
     * Applies the "order" transition without flushing: called while the order completes its
     * checkout, the change is flushed with the order.
     */
    public function markOrdered(QuoteRequestInterface $quoteRequest): void;

    public function postMessage(QuoteRequestInterface $quoteRequest, QuoteMessageInterface $message): void;

    /** Saves the merchant changes (lines, prices, conditions) without changing the status. */
    public function save(QuoteRequestInterface $quoteRequest): void;
}
