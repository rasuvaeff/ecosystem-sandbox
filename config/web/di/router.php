<?php

declare(strict_types=1);

use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\UrlMatcherInterface;

return [
    // routes.php reads $params (the admin pages build their routes from it).
    RouteCollectionInterface::class => static function (RouteCollector $collector) use ($params): RouteCollectionInterface {
        $collector->addRoute(...require dirname(__DIR__) . '/routes.php');

        return new RouteCollection($collector);
    },
    UrlMatcherInterface::class => ['class' => UrlMatcher::class, '__construct()' => ['cache' => null]],
];
