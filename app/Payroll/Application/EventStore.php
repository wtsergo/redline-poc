<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Exception\ConcurrencyConflict;

/** Append-only stream of events per earning line. There is no update and no delete. */
interface EventStore
{
    /**
     * Appends events as versions expectedVersion+1 … expectedVersion+n.
     *
     * @param  list<DomainEvent>  $events
     *
     * @throws ConcurrencyConflict when the stream is no longer at $expectedVersion
     */
    public function append(EarningLineId $id, int $expectedVersion, array $events): void;

    /** @return list<DomainEvent> in version order; empty when the line does not exist */
    public function load(EarningLineId $id): array;
}
