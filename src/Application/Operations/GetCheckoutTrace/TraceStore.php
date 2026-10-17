<?php

declare(strict_types=1);

namespace App\Application\Operations\GetCheckoutTrace;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextException;

interface TraceStore
{
    /**
     * The spans of one trace, oldest first, with whitelisted attributes only;
     * empty while the store has not received them.
     *
     * @throws ContextException the request's budget ran out first
     *
     * @return list<array{name: string, service: string, kind: string, startMs: float, durationMs: float, depth: int, attributes: array<string, scalar>}>
     */
    public function spans(string $traceId, Context $budget): array;
}
