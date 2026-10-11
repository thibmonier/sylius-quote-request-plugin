<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Unit\Basket;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;

final class SessionQuoteBasketTest extends TestCase
{
    public function testItKeepsQuantitiesByVariantAndChannel(): void
    {
        [$basket] = $this->basket();
        $web = $this->channel('WEB');
        $b2b = $this->channel('B2B');

        $basket->add($web, 10, 5);
        $basket->add($web, 10, 3);
        $basket->add($web, 11, 1);
        $basket->add($b2b, 12, 2);

        self::assertSame([10 => 8, 11 => 1], $basket->all($web));
        self::assertSame([12 => 2], $basket->all($b2b));
        self::assertSame(2, $basket->count($web));

        $basket->remove($web, 10);
        self::assertSame([11 => 1], $basket->all($web));

        $basket->clear($web);
        self::assertSame([], $basket->all($web));
        self::assertSame([12 => 2], $basket->all($b2b));
    }

    public function testItDropsEmptyLinesCapsQuantitiesAndLines(): void
    {
        [$basket] = $this->basket(2);
        $channel = $this->channel('WEB');

        $basket->replace($channel, [1 => 0, 2 => -4, 3 => 5, 4 => SessionQuoteBasket::MAX_QUANTITY + 10, 5 => 1]);

        self::assertSame([3 => 5, 4 => SessionQuoteBasket::MAX_QUANTITY], $basket->all($channel));
    }

    public function testItIgnoresCorruptedSessionData(): void
    {
        [$basket, $session] = $this->basket();
        $channel = $this->channel('WEB');
        $session->set(SessionQuoteBasket::SESSION_KEY . '.WEB', ['a' => 1, 2 => 'x', 3 => 4]);

        self::assertSame([3 => 4], $basket->all($channel));

        $session->set(SessionQuoteBasket::SESSION_KEY . '.WEB', 'nonsense');
        self::assertSame([], $basket->all($channel));
    }

    public function testItIsEmptyWithoutSession(): void
    {
        $basket = new SessionQuoteBasket(new RequestStack(), 10);
        $channel = $this->channel('WEB');

        $basket->add($channel, 1, 1);

        self::assertSame([], $basket->all($channel));
    }

    /** @return array{SessionQuoteBasket, Session} */
    private function basket(int $maxLines = 100): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [new SessionQuoteBasket($requestStack, $maxLines), $session];
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);

        return $channel;
    }
}
