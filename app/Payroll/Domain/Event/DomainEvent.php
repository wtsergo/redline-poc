<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Payroll\Domain\EarningLineId;
use DateTimeImmutable;

/**
 * A fact about an earning line, in the order it happened. The event stream is the
 * line's only source of truth: state is derived by replaying it.
 */
interface DomainEvent
{
    public EarningLineId $lineId { get; }

    public DateTimeImmutable $recordedAt { get; }
}
