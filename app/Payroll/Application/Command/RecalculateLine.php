<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;

/** Source data changed and the system recalculated the line. Ignored once the line is frozen. */
final readonly class RecalculateLine
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $value,
    ) {}
}
