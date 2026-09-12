<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use App\Payroll\Domain\Exception\CurrencyMismatch;
use App\Payroll\Domain\Exception\InvalidMoneyAmount;

/**
 * An amount in a single currency, stored as integer minor units (cents).
 *
 * Integers make "-45.55 + 100.10 - 0.10 - 0.20 + 0.20" exact, which a float never is.
 * Amounts carry at most two decimals; anything finer is rejected rather than rounded
 * (assumption A4): the system must never round on the specialist's behalf.
 */
final readonly class Money
{
    private const int MINOR_UNITS_PER_MAJOR = 100;

    /** Typographic minus (U+2212), used by the assignment tables for negative amounts. */
    private const string MINUS = "\u{2212}";

    /** Optional sign, optional thousands separators, at most two decimals. */
    private const string DECIMAL_PATTERN = '/^(?<sign>[+\-\x{2212}]?)(?<whole>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?<fraction>\d{1,2}))?$/u';

    /** Whole-unit digits that always fit a 64-bit integer once multiplied by 100. */
    private const int MAX_WHOLE_DIGITS = 16;

    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    public static function fromMinorUnits(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses "1,050.00", "-45.55", "+100.10", "0.1" or "−0.10" (typographic minus).
     *
     * @throws InvalidMoneyAmount
     */
    public static function fromDecimal(string $amount, Currency $currency): self
    {
        $amount = trim($amount);

        if (preg_match(self::DECIMAL_PATTERN, $amount, $matches) !== 1) {
            throw InvalidMoneyAmount::notDecimal($amount);
        }

        $whole = str_replace(',', '', $matches['whole']);

        if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
            throw InvalidMoneyAmount::tooLarge($amount);
        }

        $fraction = str_pad($matches['fraction'] ?? '', 2, '0');
        $minorUnits = (int) $whole * self::MINOR_UNITS_PER_MAJOR + (int) $fraction;
        $negative = $matches['sign'] === '-' || $matches['sign'] === self::MINUS;

        return new self($negative ? -$minorUnits : $minorUnits, $currency);
    }

    /** @throws CurrencyMismatch */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    /** @throws CurrencyMismatch */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function isSameCurrencyAs(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /** "$1,004.45", "−$45.55", "$0.00" — the way values appear in the assignment. */
    public function format(): string
    {
        return ($this->minorUnits < 0 ? self::MINUS : '').$this->formatUnsigned();
    }

    /** Always signed, the way adjustments are listed: "+$100.10", "−$0.20". Zero stays "$0.00". */
    public function formatSigned(): string
    {
        return match (true) {
            $this->minorUnits > 0 => '+'.$this->formatUnsigned(),
            $this->minorUnits < 0 => self::MINUS.$this->formatUnsigned(),
            default => $this->formatUnsigned(),
        };
    }

    private function formatUnsigned(): string
    {
        $absolute = $this->minorUnits < 0 ? -$this->minorUnits : $this->minorUnits;
        $whole = (string) intdiv($absolute, self::MINOR_UNITS_PER_MAJOR);
        // Group thousands on the string so very large integers keep their exact digits.
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return sprintf('%s%s.%02d', $this->currency->symbol(), $grouped, $absolute % self::MINOR_UNITS_PER_MAJOR);
    }

    /** @throws CurrencyMismatch */
    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrencyAs($other)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
