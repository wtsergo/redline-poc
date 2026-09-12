<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

final class EmptyComment extends PayrollException
{
    public static function create(): self
    {
        return new self('A comment explaining the adjustment is mandatory.');
    }
}
