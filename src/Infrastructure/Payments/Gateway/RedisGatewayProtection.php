<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateway;

use App\Application\Payments\GetGatewayProtection\GatewayProtection;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitState;

/**
 * Read from Redis through the same breaker and bulkhead services the payment
 * pipeline uses.
 */
final readonly class RedisGatewayProtection implements GatewayProtection
{
    public function __construct(
        private CircuitBreaker $breaker,
        private BreakerConfig $config,
        private SharedBulkhead $bulkhead,
        private ClockInterface $clock,
    ) {}

    public function snapshot(): array
    {
        $metrics = $this->breaker->metrics();
        $opened = $metrics->openedAt()->getTimestamp() > 0;
        $halfOpenAt = $opened
            ? $metrics->openedAt()->modify('+' . $this->config->cooldown()->toMicros() . ' microseconds')
            : null;
        $threshold = $this->config->failureThreshold();

        return [
            'name' => $this->config->name(),
            'breaker' => [
                // state() keeps saying "open" after the cooldown until the next
                // call probes the gateway; canCall() tells the two apart.
                'state' => $metrics->state()->value,
                'readyToProbe' => $metrics->state() === CircuitState::Open && $this->breaker->canCall(),
                'failures' => $metrics->failures(),
                'successes' => $metrics->successes(),
                'rejected' => $metrics->rejected(),
                'failureThreshold' => [
                    'failures' => $threshold->failures(),
                    'window' => $threshold->window(),
                    'withinSeconds' => $threshold->within()->toSeconds(),
                ],
                'cooldownSeconds' => $this->config->cooldown()->toSeconds(),
                'openedAt' => $opened ? $metrics->openedAt()->format(DATE_RFC3339_EXTENDED) : null,
                'halfOpenAt' => $halfOpenAt?->format(DATE_RFC3339_EXTENDED),
            ],
            'bulkhead' => [
                'maxConcurrent' => $this->bulkhead->maxConcurrent(),
                'inUse' => $this->bulkhead->maxConcurrent() - $this->bulkhead->availableSlots(),
            ],
            'now' => $this->clock->now()->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
