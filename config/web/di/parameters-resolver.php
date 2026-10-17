<?php

declare(strict_types=1);

use Yiisoft\Definitions\Reference;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;

// The yiisoft/app template's wiring: actions may take hydrator attributes
// (#[RouteArgument], #[Query], #[Body]) as parameters. No package here
// requires it any more: the flag and settings admin pages read their route
// argument from CurrentRoute since their 2.0.
return [
    ParametersResolverInterface::class => [
        'class' => CompositeParametersResolver::class,
        '__construct()' => [
            Reference::to(HydratorAttributeParametersResolver::class),
            Reference::to(RequestInputParametersResolver::class),
        ],
    ],
];
