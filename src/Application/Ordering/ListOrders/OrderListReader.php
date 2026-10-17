<?php

declare(strict_types=1);

namespace App\Application\Ordering\ListOrders;

use App\Application\Ordering\GetOrder\OrderView;
use Rasuvaeff\Specification\Specification;

interface OrderListReader
{
    /**
     * @return list<OrderView>
     */
    public function matching(Specification $specification): array;
}
