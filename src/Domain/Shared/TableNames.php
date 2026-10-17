<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Every table name the application uses, its own and the packages'. The
 * packages get theirs from here through params, so a read model that joins
 * a package's table and the package itself can never disagree.
 */
final readonly class TableNames
{
    public const string ORDERS = 'orders';
    public const string INBOX = 'inbox';
    public const string PAYMENT_PROCESSES = 'payment_processes';
    public const string ORDER_SUMMARY = 'order_summary';
    public const string OUTBOX = 'outbox';
    public const string AUDIT_LOG = 'audit_log';
    public const string WORKFLOW_TRANSITIONS = 'workflow_transitions';
    public const string IDEMPOTENCY_KEYS = 'idempotency_keys';
    public const string WEBHOOK_DELIVERIES = 'webhook_deliveries';
    public const string WEBHOOK_NONCES = 'webhook_nonces';
    public const string UTM_ATTRIBUTION = 'utm_attribution';
    public const string FEATURE_FLAGS = 'feature_flags';
    public const string SETTINGS = 'settings';
    public const string FILESTORAGE_FILE = 'filestorage_file';
    public const string FILESTORAGE_BLOB = 'filestorage_blob';
    public const string FILESTORAGE_BLOB_RESERVATION = 'filestorage_blob_reservation';
    public const string FILESTORAGE_ATTACHMENT = 'filestorage_attachment';
    public const string CLICKHOUSE_ORDER_RESULTS = 'sandbox_order_events';
}
