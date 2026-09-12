<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

final class ZeroAdjustment extends PayrollException
{
    public static function create(): self
    {
        return new self('An adjustment must change the value: a zero amount is a data-entry error.');
    }
}
