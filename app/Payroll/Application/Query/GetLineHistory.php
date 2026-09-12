<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Domain\EarningLineId;

final readonly class GetLineHistory
{
    public function __construct(public EarningLineId $lineId) {}
}
