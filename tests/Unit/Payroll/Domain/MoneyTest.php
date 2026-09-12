<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\Exception\CurrencyMismatch;
use App\Payroll\Domain\Exception\InvalidMoneyAmount;
use App\Payroll\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    #[DataProvider('decimalStrings')]
    public function it_parses_decimal_strings_into_minor_units(string $input, int $expectedMinorUnits): void
    {
        $money = Money::fromDecimal($input, Currency::USD);

        self::assertSame($expectedMinorUnits, $money->minorUnits);
        self::assertSame(Currency::USD, $money->currency);
    }

    /** @return iterable<string, array{string, int}> */
    public static function decimalStrings(): iterable
    {
        yield 'whole number' => ['1000', 100_000];
        yield 'thousands separator and decimals' => ['1,000.00', 100_000];
        yield 'negative' => ['-45.55', -4_555];
        yield 'explicit plus' => ['+100.10', 10_010];
        yield 'single decimal digit' => ['0.1', 10];
        yield 'typographic minus as used in the assignment' => ['−0.10', -10];
        yield 'surrounding whitespace' => [' 12.34 ', 1_234];
        yield 'zero' => ['0', 0];
        yield 'negative zero' => ['-0.00', 0];
        yield 'largest exactly representable whole part' => ['9,999,999,999,999,999.99', 999_999_999_999_999_999];
    }

    #[Test]
    #[DataProvider('invalidDecimalStrings')]
    public function it_rejects_amounts_it_cannot_represent_exactly(string $input): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::fromDecimal($input, Currency::USD);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecimalStrings(): iterable
    {
        yield 'three decimals are rejected, never rounded' => ['0.005'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'two points' => ['1.2.3'];
        yield 'misplaced thousands separator' => ['1,00'];
        yield 'double sign' => ['--1'];
        yield 'scientific notation' => ['1e3'];
        yield 'currency symbol' => ['$1.00'];
        yield 'trailing point' => ['1.'];
        yield 'too many whole digits' => ['12,345,678,901,234,567.00'];
    }

    #[Test]
    #[DataProvider('formattedAmounts')]
    public function it_formats_like_the_assignment_tables(int $minorUnits, string $expected, string $expectedSigned): void
    {
        $money = Money::fromMinorUnits($minorUnits, Currency::USD);

        self::assertSame($expected, $money->format());
        self::assertSame($expectedSigned, $money->formatSigned());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function formattedAmounts(): iterable
    {
        yield 'thousands' => [100_445, '$1,004.45', '+$1,004.45'];
        yield 'negative' => [-4_555, '−$45.55', '−$45.55'];
        yield 'zero' => [0, '$0.00', '$0.00'];
        yield 'cents only' => [5, '$0.05', '+$0.05'];
        yield 'negative cents' => [-20, '−$0.20', '−$0.20'];
        yield 'millions' => [123_456_789, '$1,234,567.89', '+$1,234,567.89'];
        yield 'exactly one thousand' => [100_000, '$1,000.00', '+$1,000.00'];
        yield 'beyond float precision' => [999_999_999_999_999_999, '$9,999,999,999,999,999.99', '+$9,999,999,999,999,999.99'];
    }

    #[Test]
    public function it_formats_euro_with_its_own_symbol(): void
    {
        self::assertSame('€1.00', Money::fromMinorUnits(100, Currency::EUR)->format());
    }

    #[Test]
    public function it_adds_and_subtracts_without_mutating_the_operands(): void
    {
        $a = Money::fromDecimal('100.10', Currency::USD);
        $b = Money::fromDecimal('0.10', Currency::USD);

        $sum = $a->plus($b);
        $difference = $a->minus($b);

        self::assertSame(10_020, $sum->minorUnits);
        self::assertSame(10_000, $difference->minorUnits);
        self::assertSame(10_010, $a->minorUnits);
        self::assertSame(10, $b->minorUnits);
    }

    #[Test]
    public function it_negates(): void
    {
        $money = Money::fromDecimal('0.20', Currency::USD);

        self::assertSame(-20, $money->negate()->minorUnits);
        self::assertTrue($money->negate()->negate()->equals($money));
    }

    #[Test]
    public function it_knows_when_it_is_zero(): void
    {
        self::assertTrue(Money::zero(Currency::USD)->isZero());
        self::assertTrue(Money::fromDecimal('-0.20', Currency::USD)->plus(Money::fromDecimal('0.20', Currency::USD))->isZero());
        self::assertFalse(Money::fromDecimal('0.01', Currency::USD)->isZero());
    }

    #[Test]
    public function it_compares_by_amount_and_currency(): void
    {
        $usd = Money::fromMinorUnits(100, Currency::USD);

        self::assertTrue($usd->equals(Money::fromDecimal('1.00', Currency::USD)));
        self::assertFalse($usd->equals(Money::fromMinorUnits(101, Currency::USD)));
        self::assertFalse($usd->equals(Money::fromMinorUnits(100, Currency::EUR)));
        self::assertTrue($usd->isSameCurrencyAs(Money::zero(Currency::USD)));
        self::assertFalse($usd->isSameCurrencyAs(Money::zero(Currency::EUR)));
    }

    #[Test]
    public function it_refuses_addition_across_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('Expected an amount in USD but got EUR');

        Money::fromMinorUnits(100, Currency::USD)->plus(Money::fromMinorUnits(100, Currency::EUR));
    }

    #[Test]
    public function it_refuses_subtraction_across_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::fromMinorUnits(100, Currency::USD)->minus(Money::fromMinorUnits(1, Currency::EUR));
    }

    #[Test]
    public function it_survives_the_rounding_trap_from_the_assignment(): void
    {
        $value = Money::fromDecimal('1,050.00', Currency::USD);

        foreach (['-45.55', '+100.10', '-0.10', '-0.20', '+0.20'] as $adjustment) {
            $value = $value->plus(Money::fromDecimal($adjustment, Currency::USD));
        }

        self::assertSame(110_445, $value->minorUnits);
        self::assertSame('$1,104.45', $value->format());
    }
}
