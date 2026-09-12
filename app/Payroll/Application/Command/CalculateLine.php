<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;

/** The system calculated a new earning line. */
final readonly class CalculateLine
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $value,
    ) {}
}
