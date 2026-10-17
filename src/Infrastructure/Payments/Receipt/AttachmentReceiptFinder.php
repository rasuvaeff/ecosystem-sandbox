<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Receipt;

use App\Application\Payments\GetReceipt\ReceiptFinder;
use App\Application\Payments\GetReceipt\ReceiptView;
use App\Domain\Ordering\OrderId;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageAttachments\Attachments;

final readonly class AttachmentReceiptFinder implements ReceiptFinder
{
    public function __construct(
        private Attachments $attachments,
        private ProxyUrlGeneratorInterface $fileUrls,
        private ClockInterface $clock,
    ) {}

    public function forOrder(OrderId $orderId): ?ReceiptView
    {
        $attachment = $this->attachments->forOwner(ownerType: StoreReceiptOnOrderPaid::OWNER_TYPE, ownerId: $orderId->value, role: StoreReceiptOnOrderPaid::ROLE)[0] ?? null;
        if ($attachment === null) {
            return null;
        }

        return new ReceiptView(
            fileName: $attachment->file->originalName,
            size: $attachment->file->size,
            mimeType: $attachment->file->mimeType,
            url: $this->fileUrls->url($attachment->file, $this->clock->now()->modify(modifier: '+1 hour')),
        );
    }
}
