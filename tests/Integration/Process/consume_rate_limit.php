<?php

declare(strict_types=1);

// Helper of RateLimitParallelTest: one "request" consuming the e-mail limiter at a given instant.
// Arguments: start timestamp (float), limiter key.

use Sylius\TestApplication\Kernel;
use Symfony\Component\RateLimiter\RateLimiterFactory;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require $root . '/vendor/sylius/test-application/config/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();
$limiter = $kernel->getContainer()->get('test.service_container')->get('limiter.tmonier_quote_request_email');
assert($limiter instanceof RateLimiterFactory);

$start = (float) ($argv[1] ?? 0);
while (microtime(true) < $start) {
    usleep(1000);
}

echo $limiter->create((string) ($argv[2] ?? 'key'))->consume()->isAccepted() ? '1' : '0';
