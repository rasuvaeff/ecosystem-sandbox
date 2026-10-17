<?php

declare(strict_types=1);

namespace App\Application\Marketing\RecordExposure;

use App\Domain\Shared\Transaction\TransactionManager;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * A visitor was shown a variant: the exposure goes to the outbox
 * (yii3-ab-testing-outbox), which refuses a write outside a transaction —
 * the exposure is its own small business fact, so it gets its own.
 */
final readonly class RecordExposureHandler
{
    public function __construct(
        private AbTesting $abTesting,
        private TransactionManager $transactions,
    ) {}

    public function handle(RecordExposureCommand $command): ExposureEvent
    {
        return $this->transactions->execute(
            operation: fn (): ExposureEvent => $this->abTesting->trackExposure($command->assignment),
        );
    }
}
