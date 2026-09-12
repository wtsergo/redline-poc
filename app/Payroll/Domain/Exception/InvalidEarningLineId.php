<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

final class InvalidEarningLineId extends PayrollException
{
    public static function notUuid(string $value): self
    {
        return new self(sprintf('"%s" is not a valid earning line id (expected a UUID).', $value));
    }
}
