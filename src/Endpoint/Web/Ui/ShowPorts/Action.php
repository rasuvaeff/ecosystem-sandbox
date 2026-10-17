<?php

declare(strict_types=1);

namespace App\Endpoint\Web\Ui\ShowPorts;

use App\Environment;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The host ports of the side services the pages link to; compose publishes
 * them under overridable variables. The WebSocket address travels with the
 * realtime token instead, next to the channel it opens.
 */
final readonly class Action
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function __invoke(): ResponseInterface
    {
        $response = $this->responses->createResponse()->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(value: [
            'jaeger' => Environment::int(name: 'JAEGER_UI_PORT', default: 16686),
            'prometheus' => Environment::int(name: 'PROMETHEUS_PORT', default: 9090),
            'grafana' => Environment::int(name: 'GRAFANA_PORT', default: 13000),
        ], flags: JSON_THROW_ON_ERROR));

        return $response;
    }
}
