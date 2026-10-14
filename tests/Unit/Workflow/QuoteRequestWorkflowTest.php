<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;
use Tmonier\SyliusQuoteRequestPlugin\Checkout\QuoteOrderabilityCheckerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequest;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLine;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;
use Tmonier\SyliusQuoteRequestPlugin\EventListener\QuoteRequestGuardListener;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

/**
 * The workflow of `config/app/workflow.yaml`, with the guard listener of the plugin.
 */
final class QuoteRequestWorkflowTest extends TestCase
{
    private StateMachine $stateMachine;

    private MockClock $clock;

    protected function setUp(): void
    {
        /** @var array{framework: array{workflows: array<string, array{places: list<string>, initial_marking: string, transitions: array<string, array{from: string|list<string>, to: string}>}>}} $config */
        $config = Yaml::parseFile(__DIR__ . '/../../../config/app/workflow.yaml');
        $workflow = $config['framework']['workflows'][QuoteRequestTransitions::GRAPH];

        $transitions = [];
        foreach ($workflow['transitions'] as $name => $transition) {
            foreach ((array) $transition['from'] as $from) {
                $transitions[] = new Transition($name, $from, $transition['to']);
            }
        }

        $this->clock = new MockClock('2026-10-11 12:00:00');
        $guard = new QuoteRequestGuardListener($this->clock, new class() implements QuoteOrderabilityCheckerInterface {
            public function isSellable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool
            {
                return $line->getVariant() instanceof \Sylius\Component\Core\Model\ProductVariantInterface;
            }

            public function isOrderable(QuoteRequestLineInterface $line, ?ChannelInterface $channel): bool
            {
                return $line->getVariant() instanceof \Sylius\Component\Core\Model\ProductVariantInterface && 'OUT' !== $line->getVariantCode();
            }

            public function getUnorderableLines(QuoteRequestInterface $quoteRequest): array
            {
                return array_values(array_filter($quoteRequest->getLines()->toArray(), fn (QuoteRequestLineInterface $line): bool => !$this->isOrderable($line, null)));
            }
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('workflow.tmonier_quote_request.guard.propose', $guard->guardPropose(...));
        $dispatcher->addListener('workflow.tmonier_quote_request.guard.accept', $guard->guardAccept(...));

        $this->stateMachine = new StateMachine(
            new Definition($workflow['places'], $transitions, $workflow['initial_marking']),
            new MethodMarkingStore(true, 'status'),
            $dispatcher,
            QuoteRequestTransitions::GRAPH,
        );
    }

    public function testThePlacesAreTheStatuses(): void
    {
        self::assertSame(QuoteRequestStatuses::ALL, $this->stateMachine->getDefinition()->getPlaces() ? array_values($this->stateMachine->getDefinition()->getPlaces()) : []);
        self::assertSame([QuoteRequestStatuses::NEW], $this->stateMachine->getDefinition()->getInitialPlaces());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function enabledTransitions(): iterable
    {
        yield 'new' => [QuoteRequestStatuses::NEW, ['review', 'propose', 'decline', 'cancel']];
        yield 'in review' => [QuoteRequestStatuses::IN_REVIEW, ['propose', 'decline', 'cancel']];
        yield 'proposed' => [QuoteRequestStatuses::PROPOSED, ['revise', 'request_changes', 'accept', 'decline', 'expire', 'cancel']];
        yield 'accepted' => [QuoteRequestStatuses::ACCEPTED, ['order', 'cancel']];
        yield 'ordered' => [QuoteRequestStatuses::ORDERED, []];
        yield 'declined' => [QuoteRequestStatuses::DECLINED, []];
        yield 'expired' => [QuoteRequestStatuses::EXPIRED, []];
        yield 'cancelled' => [QuoteRequestStatuses::CANCELLED, []];
    }

    /** @param list<string> $expected */
    #[DataProvider('enabledTransitions')]
    public function testEnabledTransitions(string $status, array $expected): void
    {
        $quote = $this->quoteWithLine();
        $quote->setStatus($status);

        $enabled = array_map(static fn (Transition $transition): string => $transition->getName(), $this->stateMachine->getEnabledTransitions($quote));

        self::assertEqualsCanonicalizing($expected, $enabled);
    }

    public function testTheFullHappyPath(): void
    {
        $quote = $this->quoteWithLine();

        foreach (['review', 'propose', 'request_changes', 'propose', 'accept', 'order'] as $transition) {
            $this->stateMachine->apply($quote, $transition);
        }

        self::assertSame(QuoteRequestStatuses::ORDERED, $quote->getStatus());
    }

    public function testAProposalNeedsAnOrderableLine(): void
    {
        $quote = new QuoteRequest();
        self::assertFalse($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_PROPOSE));

        $line = new QuoteRequestLine();
        $quote->addLine($line); // variant deleted
        self::assertFalse($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_PROPOSE));

        $line->setVariant(new ProductVariant());
        self::assertTrue($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_PROPOSE));
    }

    public function testAProposalCanBeAcceptedOnlyUntilItsValidityDate(): void
    {
        $quote = $this->quoteWithLine();
        $quote->setStatus(QuoteRequestStatuses::PROPOSED);
        $quote->setValidUntil(new \DateTimeImmutable('2026-10-11 23:59:59'));

        self::assertTrue($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_ACCEPT));

        $this->clock->modify('2026-10-12 00:00:00');
        self::assertFalse($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_ACCEPT));
        $blockers = iterator_to_array($this->stateMachine->buildTransitionBlockerList($quote, QuoteRequestTransitions::TRANSITION_ACCEPT));
        self::assertSame(QuoteRequestGuardListener::CODE_EXPIRED, $blockers[0]->getCode());
        // It can still be declined or expired.
        self::assertTrue($this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_EXPIRE));
    }

    private function quoteWithLine(): QuoteRequest
    {
        $quote = new QuoteRequest();
        $line = new QuoteRequestLine();
        $line->setVariant(new ProductVariant());
        $quote->addLine($line);

        return $quote;
    }

    public function testAProposalWithAnUnorderableLineCannotBeAccepted(): void
    {
        $quote = $this->quoteWithLine();
        $quote->setStatus(QuoteRequestStatuses::PROPOSED);
        $this->assertCanAccept($quote, true);

        $line = new QuoteRequestLine();
        $line->setVariant(new ProductVariant());
        $line->setVariantCode('OUT');
        $quote->addLine($line);

        $this->assertCanAccept($quote, false);
        $blockers = iterator_to_array($this->stateMachine->buildTransitionBlockerList($quote, QuoteRequestTransitions::TRANSITION_ACCEPT));
        self::assertSame(QuoteRequestGuardListener::CODE_UNAVAILABLE, $blockers[0]->getCode());
    }

    private function assertCanAccept(QuoteRequest $quote, bool $expected): void
    {
        self::assertSame($expected, $this->stateMachine->can($quote, QuoteRequestTransitions::TRANSITION_ACCEPT));
    }
}
