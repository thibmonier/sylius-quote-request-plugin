<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuoteRequestPlugin\Integration;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Parallel requests must not get past the limits: the limiters run with a lock.
 */
final class RateLimitParallelTest extends KernelTestCase
{
    public function testParallelRequestsRespectThePerEmailLimit(): void
    {
        self::bootKernel();
        $pool = self::getContainer()->get('cache.tmonier_quote_request_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $php = (new PhpExecutableFinder())->find();
        self::assertIsString($php);
        $start = microtime(true) + 4;
        $key = 'parallel-' . bin2hex(random_bytes(4)) . '@example.com';
        $processes = [];
        for ($i = 0; $i < 10; ++$i) {
            $process = new Process([$php, '-d', 'memory_limit=1G', __DIR__ . '/Process/consume_rate_limit.php', (string) $start, $key]);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $accepted = 0;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $accepted += (int) trim($process->getOutput());
        }

        self::assertSame(3, $accepted, 'per_email is 3: exactly 3 of 10 simultaneous requests get through.');
    }
}
