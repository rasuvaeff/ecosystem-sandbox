<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Receipt;

use App\Application\Ordering\GetOrder\OrderView;
use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderResultEvent;
use App\Application\Ordering\OrderingApi;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Transaction\TransactionManager;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageAttachments\Attachments;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * The receipt of a paid order: a file in yii3-filestorage, linked to the
 * order by yii3-filestorage-attachments, downloadable through a signed
 * yii3-filestorage-web URL. A subscriber of `order.paid`.
 *
 * A subscriber sees a message again whenever another one failed it (the
 * webhook receiver refuses the first attempt on purpose), and
 * `Storage::add()` has no natural key: the existing attachment is what makes
 * a redelivery write no second receipt. The file row and the link are written
 * in one transaction.
 */
final readonly class StoreReceiptOnOrderPaid implements PublisherInterface
{
    public const string OWNER_TYPE = 'order';
    public const string ROLE = 'receipt';
    public const string GROUP = 'receipts';

    public function __construct(
        private IntegrationMessages $messages,
        private OrderingApi $ordering,
        private StorageInterface $storage,
        private Attachments $attachments,
        private StreamFactoryInterface $streams,
        private TransactionManager $transactions,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $result = $this->messages->parse(message: $message, expected: OrderResultEvent::class);
        if (!$result instanceof OrderPaid) {
            // A failed payment has nothing to give a receipt for.
            return;
        }

        try {
            $order = $this->ordering->order(orderId: new OrderId(value: $result->orderId));
        } catch (OrderNotFoundException) {
            $order = null;
        }
        if (!$order instanceof OrderView) {
            throw PublishException::terminal(message: 'Order of the paid event does not exist', outboxMessage: $message);
        }
        $orderId = $order->orderId->value;
        if ($this->attachments->forOwner(ownerType: self::OWNER_TYPE, ownerId: $orderId, role: self::ROLE) !== []) {
            return;
        }

        $text = implode(separator: "\n", array: [
            'Sandbox shop - payment receipt',
            '',
            'Order:    ' . $orderId,
            'Customer: ' . $order->customerId->value,
            'Amount:   ' . number_format(num: $order->amount->cents / 100, decimals: 2, decimal_separator: '.', thousands_separator: ' ') . ' ' . Amount::CURRENCY,
            'Paid at:  ' . $message->getCreatedAt()->format(format: 'Y-m-d H:i:s T'),
            'Event:    ' . $message->getId(),
            '',
        ]);

        try {
            $this->transactions->execute(operation: function () use ($orderId, $text): void {
                $file = $this->storage->add(
                    Upload::fromStream($this->streams->createStream($text), 'receipt-' . substr(string: hash(algo: 'sha256', data: $orderId), offset: 0, length: 12) . '.txt', $this->streams),
                    groupName: self::GROUP,
                    description: 'Payment receipt',
                    metadata: ['orderId' => $orderId],
                );
                $this->attachments->attach(file: $file, ownerType: self::OWNER_TYPE, ownerId: $orderId, role: self::ROLE);
            });
        } catch (\Throwable $exception) {
            throw new PublishException(message: 'The receipt could not be stored', outboxMessage: $message, previous: $exception);
        }
    }
}
