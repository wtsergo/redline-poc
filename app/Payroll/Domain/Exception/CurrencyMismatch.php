<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Payroll\Domain\Currency;

final class CurrencyMismatch extends PayrollException
{
    public static function between(Currency $expected, Currency $actual): self
    {
        return new self(sprintf(
            'Expected an amount in %s but got %s: an earning line is denominated in a single currency.',
            $expected->value,
            $actual->value,
        ));
    }
}
