<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use DateTimeImmutable;

/**
 * The system tried to recalculate a line that already carries a manual adjustment (rule R4).
 * Nothing changes, but the attempt is kept so an auditor can see that the source data moved
 * after the line was frozen (assumption A1).
 */
final readonly class RecalculationIgnored implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $attemptedValue,
        public DateTimeImmutable $recordedAt,
    ) {}
}
