<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use DateTimeImmutable;

/** Source data changed and the system replaced the value, which is allowed while no manual adjustment exists. */
final readonly class LineRecalculated implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $value,
        public DateTimeImmutable $recordedAt,
    ) {}
}
