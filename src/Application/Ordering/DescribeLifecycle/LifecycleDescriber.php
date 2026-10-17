<?php

declare(strict_types=1);

namespace App\Application\Ordering\DescribeLifecycle;

/**
 * The order lifecycle as the runtime holds it — what the workflow graph on
 * the order page is drawn from.
 */
interface LifecycleDescriber
{
    public function describe(): LifecycleDescription;
}
