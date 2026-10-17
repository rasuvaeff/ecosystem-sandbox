-- One row per order result event (paid, payment_failed). Delivery from the
-- outbox is at-least-once, so the event id is the deduplication key and every
-- read uses FINAL. `source` tells the shop page's orders from the contract
-- page's generated ones.
CREATE TABLE IF NOT EXISTS {{order_results_table}}
(
    event_id     String,
    order_id     String,
    status       LowCardinality(String),
    amount_cents UInt64,
    occurred_at  DateTime64(3),
    source       LowCardinality(String) DEFAULT 'other'
)
ENGINE = ReplacingMergeTree
ORDER BY event_id
