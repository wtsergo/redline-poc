<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/**
 * Currencies this proof of concept knows about. An earning line is denominated in exactly
 * one currency (assumption A5); EUR exists so that cross-currency arithmetic can be shown
 * to be refused.
 */
enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';

    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::EUR => '€',
        };
    }
}
