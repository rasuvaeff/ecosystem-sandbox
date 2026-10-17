<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\Grafana;

use App\Application\Analytics\GetAnalyticsReport\DashboardStatus;
use App\Environment;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

final readonly class GrafanaDashboardStatus implements DashboardStatus
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
    ) {}

    public function isUp(): bool
    {
        try {
            return $this->client->sendRequest($this->requestFactory->createRequest(
                'GET',
                Environment::string(name: 'GRAFANA_URL', default: 'http://grafana:3000') . '/api/health',
            ))->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}
