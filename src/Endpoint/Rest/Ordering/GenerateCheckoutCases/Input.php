<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\GenerateCheckoutCases;

use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\Input\Http\Attribute\Data\FromQuery;

/**
 * The generator seed: the same seed gives the same cases, so a page can be
 * reproduced; none (or one that is not a number) draws a fresh one.
 */
#[FromQuery]
final class Input extends AbstractInput
{
    public string $seed = '';

    /**
     * @return int<0, max>
     */
    public function seed(): int
    {
        return ctype_digit(text: $this->seed) ? max(0, (int) $this->seed) : random_int(min: 1, max: 1_000_000);
    }
}
