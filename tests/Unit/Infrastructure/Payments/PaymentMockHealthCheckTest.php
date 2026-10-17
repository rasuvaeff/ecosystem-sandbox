<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payments;

use App\Infrastructure\Payments\Health\PaymentMockHealthCheck;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use HttpSoft\Message\RequestFactory;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3HealthCheck\HealthStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * An unreachable mock is the case this check exists to report, so a transport
 * failure must become a failed result, not an exception: the MCP
 * `payment.gateway_status` tool calls the check directly, without the
 * try/catch HealthChecker puts around it.
 */
#[Test]
#[Covers(PaymentMockHealthCheck::class)]
final class PaymentMockHealthCheckTest
{
    public function anUnreachableMockIsAFailedCheck(): void
    {
        $client = Understudy::for(ClientInterface::class);
        when(static fn () => $client->sendRequest(Arg::any()))->throws(
            new ConnectException(message: 'Connection refused', request: new Request(method: 'GET', uri: 'http://payment-mock:8081/health')),
        );

        $result = new PaymentMockHealthCheck($client, new RequestFactory(), endpoint: 'http://payment-mock:8081/health')->check();

        Assert::same($result->status, HealthStatus::Fail);
    }
}
