<?php

declare(strict_types=1);

namespace App\Domain\Shared\Refusal;

/**
 * A command the domain declines, as a value: the expected "no" of a business
 * rule travels in a Result (rasuvaeff/result) and the caller has to look at
 * it. Exceptions are kept for what nobody expects — a lookup that finds
 * nothing at a query boundary, a broken invariant, a failing system.
 */
interface Refusal
{
    /**
     * @return non-empty-string what was refused and why, safe to show
     */
    public function reason(): string;
}
