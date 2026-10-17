<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Correlation;

use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;

/**
 * The correlation id of the work in progress: the HTTP request's (set by
 * CorrelationIdMiddleware), or the checkout's in the worker (re-opened from
 * the outbox payload). Outside any scope a random id still ties the rows of
 * one operation together.
 */
final readonly class CurrentRequestId
{
    public function __construct(private CorrelationIdHolder $holder) {}

    /**
     * @return non-empty-string
     */
    public function get(): string
    {
        $id = $this->holder->tryGet();

        return $id !== null && $id !== '' ? $id : bin2hex(string: random_bytes(length: 8));
    }
}
