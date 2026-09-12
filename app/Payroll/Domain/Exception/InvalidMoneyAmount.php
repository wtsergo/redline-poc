<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

final class InvalidMoneyAmount extends PayrollException
{
    public static function notDecimal(string $amount): self
    {
        return new self(sprintf(
            '"%s" is not a valid amount: expected an optionally signed decimal with at most two decimals, e.g. "-45.55".',
            $amount,
        ));
    }

    public static function tooLarge(string $amount): self
    {
        return new self(sprintf('"%s" is too large to be represented exactly.', $amount));
    }
}
