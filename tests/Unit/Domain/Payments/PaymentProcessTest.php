<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Payments;

use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessStep;
use App\Tests\Support\PaymentProcessMachine;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PaymentProcess::class)]
#[Covers(PaymentProcessStep::class)]
final class PaymentProcessTest
{
    #[Property(runs: 300)]
    public function theSagaTakesEachStepOnceWhateverTheOrderOfEvents(RuleSequence $sequence): void
    {
        $sequence->run(factory: static fn (): PaymentProcessMachine => new PaymentProcessMachine());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theSagaTakesEachStepOnceWhateverTheOrderOfEventsGenerators(): array
    {
        return ['sequence' => Gen::rules(PaymentProcessMachine::class, minLength: 1, maxLength: 10)];
    }
}
