<?php

declare(strict_types=1);

namespace App\Bootloader;

use App\Application\Payments\GetGatewayProtection\GatewayProtection;
use App\Application\Payments\GetReceipt\ReceiptFinder;
use App\Domain\Payments\GatewayHealth;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcomes;
use App\Domain\Payments\Process\PaymentProcessRepository;
use App\Infrastructure\Payments\Gateway\MockGatewayHealth;
use App\Infrastructure\Payments\Gateway\RedisGatewayProtection;
use App\Infrastructure\Payments\Gateway\ResilientPaymentGateway;
use App\Infrastructure\Payments\Metrics\MetricPaymentOutcomes;
use App\Infrastructure\Payments\Persistence\DbPaymentProcessRepository;
use App\Infrastructure\Payments\Receipt\AttachmentReceiptFinder;
use Yiisoft\Di\ServiceProviderInterface;

final readonly class PaymentsBootloader implements ServiceProviderInterface
{
    public function getDefinitions(): array
    {
        return [
            PaymentGateway::class => ResilientPaymentGateway::class,
            PaymentProcessRepository::class => DbPaymentProcessRepository::class,
            PaymentOutcomes::class => MetricPaymentOutcomes::class,
            ReceiptFinder::class => AttachmentReceiptFinder::class,
            GatewayHealth::class => MockGatewayHealth::class,
            GatewayProtection::class => RedisGatewayProtection::class,
        ];
    }

    public function getExtensions(): array
    {
        return [];
    }
}
