<?php

declare(strict_types=1);

namespace App\Domain\Shared\Transaction;

interface TransactionManager
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function execute(callable $operation): mixed;
}
