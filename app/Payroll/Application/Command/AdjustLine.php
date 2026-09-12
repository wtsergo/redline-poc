<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;

/** A payroll specialist corrects the line by a signed amount, with a mandatory comment. */
final readonly class AdjustLine
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $amount,
        public Comment $comment,
    ) {}
}
