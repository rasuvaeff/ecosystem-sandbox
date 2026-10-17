<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowEvidence\Data;

use App\Application\Ordering\GetOrderEvidence\AuditEntry;
use App\Application\Ordering\GetOrderEvidence\OrderEvidence;
use App\Application\Ordering\GetOrderEvidence\OutboxEntry;
use App\Application\Payments\GetPaymentProcess\PaymentProcessView;
use App\Application\Payments\GetReceipt\ReceiptView;
use App\Endpoint\Rest\Ordering\Shared\Data\OrderResult;
use App\Endpoint\Rest\Ordering\Shared\Data\TransitionResult;
use OpenApi\Attributes as OA;

/**
 * Everything the database holds about one order, composed from three
 * contexts: Ordering's evidence, Marketing's attribution, Payments' receipt
 * and payment process.
 * The web UI renders every step from it.
 */
#[OA\Schema(schema: 'OrderEvidence', required: ['order', 'transitions', 'audit', 'outbox', 'maxAttempts', 'nextTransitions', 'attribution', 'receipt', 'payment'], properties: [
    new OA\Property(
        property: 'payment',
        description: 'The payment saga (PaymentProcess): what the bank was asked and what came back; abandoned means no answer on the last attempt, and the reconciliation then ends it as not_charged, or as recovered when the bank had taken the money and the failed order was paid after all. Null until the worker takes the payment',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                required: ['status'],
                properties: [
                    new OA\Property(
                        property: 'status',
                        type: 'string',
                        enum: ['requested', 'captured', 'declined', 'abandoned', 'not_charged', 'recovered'],
                    ),
                ],
                type: 'object',
                additionalProperties: false,
            ),
        ],
    ),
    new OA\Property(
        property: 'receipt',
        description: 'The receipt file attached to a paid order (yii3-filestorage), null until stored',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                required: ['fileName', 'size', 'mimeType', 'url'],
                properties: [
                    new OA\Property(
                        property: 'fileName',
                        type: 'string',
                    ),
                    new OA\Property(
                        property: 'size',
                        type: 'integer',
                        minimum: 0,
                    ),
                    new OA\Property(
                        property: 'mimeType',
                        type: ['string', 'null'],
                    ),
                    new OA\Property(
                        property: 'url',
                        description: 'Signed, expiring download URL (GET /files/{token})',
                        type: ['string', 'null'],
                    ),
                ],
                type: 'object',
                additionalProperties: false,
            ),
        ],
    ),
    new OA\Property(
        property: 'attribution',
        description: 'Where the buyer came from, journalled by yii3-utm-db at checkout',
        type: 'array',
        items: new OA\Items(
            required: ['channel', 'source', 'medium', 'campaign', 'clickIds', 'referrerHost', 'landingPage'],
            properties: [
                new OA\Property(
                    property: 'channel',
                    ref: '#/components/schemas/UtmChannel',
                ),
                new OA\Property(
                    property: 'source',
                    type: ['string', 'null'],
                ),
                new OA\Property(
                    property: 'medium',
                    type: ['string', 'null'],
                ),
                new OA\Property(
                    property: 'campaign',
                    type: ['string', 'null'],
                ),
                new OA\Property(
                    property: 'clickIds',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(
                        type: 'string',
                    ),
                ),
                new OA\Property(
                    property: 'referrerHost',
                    type: ['string', 'null'],
                ),
                new OA\Property(
                    property: 'landingPage',
                    type: ['string', 'null'],
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'order',
        ref: '#/components/schemas/Order',
    ),
    new OA\Property(
        property: 'transitions',
        ref: '#/components/schemas/OrderTimeline/properties/events',
    ),
    new OA\Property(
        property: 'audit',
        type: 'array',
        items: new OA\Items(
            required: ['action', 'requestId', 'at', 'changes'],
            properties: [
                new OA\Property(
                    property: 'changes',
                    type: 'array',
                    items: new OA\Items(
                        ref: '#/components/schemas/AuditChange',
                    ),
                ),
                new OA\Property(
                    property: 'action',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'requestId',
                    type: ['string', 'null'],
                ),
                new OA\Property(
                    property: 'at',
                    type: 'string',
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'outbox',
        type: 'array',
        items: new OA\Items(
            required: ['type', 'status', 'attempts', 'createdAt', 'lastAttemptAt'],
            properties: [
                new OA\Property(
                    property: 'type',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'status',
                    type: 'string',
                    enum: ['pending', 'processing', 'published', 'failed'],
                ),
                new OA\Property(
                    property: 'attempts',
                    type: 'integer',
                    minimum: 0,
                ),
                new OA\Property(
                    property: 'createdAt',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'lastAttemptAt',
                    type: ['string', 'null'],
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'maxAttempts',
        type: 'integer',
        minimum: 1,
    ),
    new OA\Property(
        property: 'nextTransitions',
        type: 'array',
        items: new OA\Items(
            type: 'string',
        ),
    ),
], type: 'object', additionalProperties: false)]
final readonly class OrderEvidenceResult implements \JsonSerializable
{
    /**
     * @param list<TransitionResult> $transitions
     * @param list<AuditEntry> $audit
     * @param list<OutboxEntry> $outbox
     * @param list<string> $nextTransitions
     * @param list<array{channel: string, source: ?string, medium: ?string, campaign: ?string, clickIds: object, referrerHost: ?string, landingPage: ?string}> $attribution
     */
    public function __construct(
        public OrderResult $order,
        public array $transitions,
        public array $audit,
        public array $outbox,
        public int $maxAttempts,
        public array $nextTransitions,
        public array $attribution,
        public ?ReceiptView $receipt,
        public ?PaymentProcessView $payment,
    ) {}

    /**
     * @param list<array{channel: string, source: ?string, medium: ?string, campaign: ?string, clickIds: object, referrerHost: ?string, landingPage: ?string}> $attribution
     */
    public static function compose(OrderEvidence $evidence, array $attribution, ?ReceiptView $receipt, ?PaymentProcessView $payment): self
    {
        $next = [];
        foreach ($evidence->nextTransitions as $transition) {
            $next[] = $transition->value;
        }

        return new self(
            order: OrderResult::fromView(order: $evidence->order),
            transitions: TransitionResult::list(records: $evidence->transitions),
            audit: $evidence->audit,
            outbox: $evidence->outbox,
            maxAttempts: $evidence->maxAttempts,
            nextTransitions: $next,
            attribution: $attribution,
            receipt: $receipt,
            payment: $payment,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'order' => $this->order,
            'transitions' => $this->transitions,
            'audit' => $this->audit,
            'outbox' => $this->outbox,
            'maxAttempts' => $this->maxAttempts,
            'nextTransitions' => $this->nextTransitions,
            'attribution' => $this->attribution,
            'receipt' => $this->receipt,
            'payment' => $this->payment,
        ];
    }
}
