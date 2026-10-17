<?php

declare(strict_types=1);

namespace App\Bootloader;

use App\Application\Ordering\DescribeLifecycle\LifecycleDescriber;
use App\Application\Ordering\GetOrderEvidence\OrderActivityReader;
use App\Application\Ordering\GetWebhookDeliveries\WebhookJournal;
use App\Application\Ordering\ListOrders\OrderListReader;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\TransitionHistory;
use App\Infrastructure\Ordering\Persistence\DbOrderRepository;
use App\Infrastructure\Ordering\ReadModel\DbOrderActivityReader;
use App\Infrastructure\Ordering\ReadModel\DbOrderListReader;
use App\Infrastructure\Ordering\Webhook\DbWebhookJournal;
use App\Infrastructure\Ordering\Workflow\WorkflowLifecycleDescriber;
use App\Infrastructure\Ordering\Workflow\WorkflowOrderLifecycle;
use App\Infrastructure\Ordering\Workflow\WorkflowTransitionHistory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;
use Yiisoft\Di\ServiceProviderInterface;

final readonly class OrderingBootloader implements ServiceProviderInterface
{
    public function getDefinitions(): array
    {
        return [
            OrderRepository::class => DbOrderRepository::class,
            TransitionHistory::class => WorkflowTransitionHistory::class,
            OrderActivityReader::class => DbOrderActivityReader::class,
            OrderListReader::class => DbOrderListReader::class,
            LifecycleDescriber::class => WorkflowLifecycleDescriber::class,
            WebhookJournal::class => DbWebhookJournal::class,
            OrderLifecycle::class => static fn (WorkflowRegistry $registry, WorkflowTransaction $transaction): WorkflowOrderLifecycle => new WorkflowOrderLifecycle(
                workflow: $registry->get(WorkflowOrderLifecycle::WORKFLOW),
                transaction: $transaction,
            ),
        ];
    }

    public function getExtensions(): array
    {
        return [];
    }
}
