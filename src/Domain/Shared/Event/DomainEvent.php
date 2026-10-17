<?php

declare(strict_types=1);

namespace App\Domain\Shared\Event;

/**
 * Something that happened in the domain. Dispatched in-process (yii-event) in
 * the transaction that produced it; a context that publishes it to the outbox
 * maps it to an integration event there.
 */
interface DomainEvent {}
