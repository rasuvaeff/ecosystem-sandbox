<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

final readonly class GetTranscriptQuery
{
    public function __construct(public string $orderId) {}
}
