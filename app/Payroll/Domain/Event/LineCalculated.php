<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use DateTimeImmutable;

/** The system calculated the line for the first time; this is how a line comes into existence. */
final readonly class LineCalculated implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $value,
        public DateTimeImmutable $recordedAt,
    ) {}
}
