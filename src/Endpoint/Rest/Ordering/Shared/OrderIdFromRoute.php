<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Shared;

use App\Domain\Ordering\OrderId;
use App\Shared\Http\Problem;
use Yiisoft\Router\CurrentRoute;

/**
 * The `{id}` of an order route. The router hands over the decoded argument
 * (the raw path is still percent-encoded, so "order 1" would never match);
 * an id no order can have is a 404, like an unknown one.
 */
final readonly class OrderIdFromRoute
{
    public static function of(CurrentRoute $route): OrderId
    {
        return OrderId::tryFrom(value: $route->getArgument(name: 'id') ?? '') ?? throw Problem::orderNotFound();
    }
}
