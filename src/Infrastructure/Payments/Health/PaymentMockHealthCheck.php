<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Health;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Yii3HealthCheck\HealthCheck;
use Rasuvaeff\Yii3HealthCheck\HealthResult;

final readonly class PaymentMockHealthCheck implements HealthCheck
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private string $endpoint,
    ) {}

    public function name(): string
    {
        return 'payment-mock';
    }

    public function check(): HealthResult
    {
        try {
            $response = $this->client->sendRequest(
                $this->requestFactory->createRequest('GET', $this->endpoint),
            );
        } catch (ClientExceptionInterface $exception) {
            return HealthResult::fail(
                name: $this->name(),
                message: 'Payment mock is unreachable',
                data: ['error' => $exception::class],
            );
        }

        if ($response->getStatusCode() !== 200) {
            return HealthResult::fail(
                name: $this->name(),
                message: 'Payment mock returned a non-200 status',
                data: ['status' => $response->getStatusCode()],
            );
        }

        return HealthResult::pass(name: $this->name());
    }
}
