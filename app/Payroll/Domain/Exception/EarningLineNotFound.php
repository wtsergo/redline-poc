<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Payroll\Domain\EarningLineId;

final class EarningLineNotFound extends PayrollException
{
    public static function withId(EarningLineId $id): self
    {
        return new self(sprintf('Earning line %s does not exist.', $id->toString()));
    }
}
