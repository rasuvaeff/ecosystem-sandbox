<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Shared\Transaction\TransactionManager;

/**
 * Runs the operation as it is: the tests that use it hold no database.
 */
final class ImmediateTransactions implements TransactionManager
{
    public function execute(callable $operation): mixed
    {
        return $operation();
    }
}
