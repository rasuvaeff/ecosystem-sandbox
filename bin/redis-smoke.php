<?php

declare(strict_types=1);

use Predis\Client;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner as BulkheadRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner as CircuitRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

$redis = new Client((string) (getenv('REDIS_URL') ?: 'redis://redis:6379'));
if ((string) $redis->ping() !== 'PONG') {
    throw new RuntimeException('Redis did not answer PONG');
}
$bulkhead = new RedisBulkheadStore(new BulkheadRunner($redis), 'sandbox-smoke:bulkhead:');
$token = $bulkhead->tryAcquire('payment', 1, \Rasuvaeff\Duration\Duration::seconds(5));
if ($token === null || $bulkhead->activeCount('payment') !== 1) {
    throw new RuntimeException('Redis bulkhead did not acquire a slot');
}
$bulkhead->release('payment', $token);
$breaker = new RedisStorage(new CircuitRunner($redis), 'sandbox-smoke:circuit:');
$config = new BreakerConfig(
    name: 'payment',
    failureThreshold: Ratio::of(failures: 1, window: 2, within: \Rasuvaeff\Duration\Duration::seconds(30)),
    cooldown: \Rasuvaeff\Duration\Duration::seconds(5),
    successThreshold: 1,
    isFailure: static fn (mixed $value): bool => $value instanceof Throwable,
);
$breaker->forceState('payment', CircuitState::Closed, new DateTimeImmutable());
$snapshot = $breaker->snapshot('payment');
if ($snapshot->state() !== CircuitState::Closed) {
    throw new RuntimeException('Redis circuit breaker state was not persisted');
}
$redis->flushdb();
echo "REDIS PASS: ping=ok bulkhead=acquire-release circuit=closed\n";
