<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Transaction;

use App\Domain\Shared\Transaction\TransactionManager;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * One database transaction around a use case. A nested call joins the outer
 * transaction (yiisoft/db savepoints), which is how a lifecycle transition run
 * inside the checkout commits with it.
 */
final readonly class DbTransactionManager implements TransactionManager
{
    public function __construct(private ConnectionInterface $db) {}

    public function execute(callable $operation): mixed
    {
        return $this->db->transaction(closure: static fn (): mixed => $operation());
    }
}
