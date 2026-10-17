<?php

declare(strict_types=1);

namespace App\Application\Marketing\RecordExposure;

use Rasuvaeff\Yii3AbTesting\Assignment;

final readonly class RecordExposureCommand
{
    public function __construct(public Assignment $assignment) {}
}
