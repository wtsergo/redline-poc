<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Adjustment;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Exception\CurrencyMismatch;
use App\Payroll\Domain\Exception\EarningLineNotFound;
use App\Payroll\Domain\Exception\ZeroAdjustment;
use App\Payroll\Domain\Money;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarningLineTest extends TestCase
{
    private const string LINE_ID = '0f5a2c3e-7b1d-4a9e-8c2f-1234567890ab';

    #[Test]
    public function it_reproduces_the_assignment_scenario_step_by_step(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('1,000.00'), $this->at(1));
        self::assertSame('$1,000.00', $line->currentValue()->format(), 'step 1: system calculates the line');

        $line->recalculate($this->usd('1,050.00'), $this->at(2));
        self::assertSame('$1,050.00', $line->currentValue()->format(), 'step 2: recalculation allowed, no adjustment yet');
        self::assertFalse($line->isFrozen());

        $line->adjust($this->usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction'), $this->at(3));
        self::assertSame('$1,004.45', $line->currentValue()->format(), 'step 3: first adjustment');
        self::assertTrue($line->isFrozen());
        self::assertSame(3, $line->frozenAtVersion());

        $line->recalculate($this->usd('1,200.00'), $this->at(4));
        self::assertSame('$1,004.45', $line->currentValue()->format(), 'step 4: recalculation ignored');
        self::assertSame('$1,050.00', $line->systemValue()->format(), 'the system value stays frozen');

        $line->adjust($this->usd('+100.10'), new Comment('Late correction: missed approved overtime bonus'), $this->at(5));
        self::assertSame('$1,104.55', $line->currentValue()->format(), 'step 5');

        $line->adjust($this->usd('-0.10'), new Comment('Minor rounding adjustment'), $this->at(6));
        self::assertSame('$1,104.45', $line->currentValue()->format(), 'step 6');

        $line->adjust($this->usd('-0.20'), new Comment('Second minor rounding adjustment'), $this->at(7));
        self::assertSame('$1,104.25', $line->currentValue()->format(), 'step 7');

        $line->adjust($this->usd('+0.20'), new Comment('Correcting mistake in adjustment #4'), $this->at(8));
        self::assertSame('$1,104.45', $line->currentValue()->format(), 'step 8: compensating correction');

        // Expected final audit history.
        self::assertSame('$1,050.00', $line->systemValue()->format());
        self::assertSame(3, $line->frozenAtVersion());
        self::assertSame([1, 2, 3, 4, 5], array_map(fn (Adjustment $a): int => $a->number, $line->adjustments()));
        self::assertSame(
            ['−$45.55', '+$100.10', '−$0.10', '−$0.20', '+$0.20'],
            array_map(fn (Adjustment $a): string => $a->amount->formatSigned(), $line->adjustments()),
        );
        self::assertSame('$1,104.45', $line->currentValue()->format());
        self::assertSame(8, $line->version());
    }

    #[Test]
    public function it_records_one_event_per_step_in_order_and_releases_them_once(): void
    {
        $line = $this->assignmentScenario();

        $events = $line->releaseEvents();

        self::assertSame([
            LineCalculated::class,
            LineRecalculated::class,
            LineAdjusted::class,
            RecalculationIgnored::class,
            LineAdjusted::class,
            LineAdjusted::class,
            LineAdjusted::class,
            LineAdjusted::class,
        ], array_map(fn (DomainEvent $e): string => $e::class, $events));

        $ignored = $events[3];
        self::assertInstanceOf(RecalculationIgnored::class, $ignored);
        self::assertSame('$1,200.00', $ignored->attemptedValue->format(), 'the ignored attempt keeps the value the system wanted');
        self::assertTrue($ignored->lineId->equals($this->id()));
        self::assertEquals($this->at(4), $ignored->recordedAt);

        self::assertSame([], $line->releaseEvents(), 'events are handed over exactly once');
    }

    #[Test]
    public function it_replaces_the_system_value_while_no_adjustment_exists(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('1,000.00'), $this->at(1));

        $line->recalculate($this->usd('1,050.00'), $this->at(2));
        $line->recalculate($this->usd('1,075.00'), $this->at(3));

        self::assertSame('$1,075.00', $line->systemValue()->format());
        self::assertSame('$1,075.00', $line->currentValue()->format());
        self::assertFalse($line->isFrozen());
        self::assertNull($line->frozenAtVersion());
        self::assertSame(3, $line->version());
    }

    #[Test]
    public function it_ignores_recalculation_once_adjusted_but_records_the_attempt(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('1,000.00'), $this->at(1));
        $line->adjust($this->usd('-1.00'), new Comment('first'), $this->at(2));
        $line->releaseEvents();

        $line->recalculate($this->usd('5,000.00'), $this->at(3));
        $line->recalculate($this->usd('6,000.00'), $this->at(4));

        self::assertSame('$1,000.00', $line->systemValue()->format());
        self::assertSame('$999.00', $line->currentValue()->format());
        self::assertSame(2, $line->frozenAtVersion());
        self::assertSame(4, $line->version());
        self::assertContainsOnlyInstancesOf(RecalculationIgnored::class, $line->releaseEvents());
    }

    #[Test]
    #[DataProvider('adjustmentCounts')]
    public function it_numbers_adjustments_sequentially_and_keeps_their_order(int $count): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('100.00'), $this->at(1));

        for ($n = 1; $n <= $count; $n++) {
            $line->adjust(Money::fromMinorUnits($n, Currency::USD), new Comment("adjustment $n"), $this->at($n + 1));
        }

        $adjustments = $line->adjustments();
        self::assertCount($count, $adjustments);
        self::assertSame(range(1, $count), array_map(fn (Adjustment $a): int => $a->number, $adjustments));
        self::assertSame(
            array_map(fn (int $n): string => "adjustment $n", range(1, $count)),
            array_map(fn (Adjustment $a): string => $a->comment->text, $adjustments),
        );
        self::assertSame(10_000 + $count * ($count + 1) / 2, $line->currentValue()->minorUnits);
        self::assertSame(2, $line->frozenAtVersion(), 'frozen at the first adjustment, whatever follows');
    }

    /** @return iterable<string, array{int}> */
    public static function adjustmentCounts(): iterable
    {
        yield 'one' => [1];
        yield 'two' => [2];
        yield 'five, as in the assignment' => [5];
        yield 'fifty' => [50];
    }

    #[Test]
    public function it_keeps_both_entries_of_a_compensating_correction(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('1,000.00'), $this->at(1));

        $line->adjust($this->usd('-0.20'), new Comment('Second minor rounding adjustment'), $this->at(2));
        $line->adjust($this->usd('+0.20'), new Comment('Correcting mistake in adjustment #1'), $this->at(3));

        self::assertCount(2, $line->adjustments(), 'the mistake stays visible; only a new entry fixes it');
        self::assertTrue($line->currentValue()->equals($line->systemValue()));
    }

    #[Test]
    public function it_accepts_positive_and_negative_amounts(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('10.00'), $this->at(1));

        $line->adjust($this->usd('+5.00'), new Comment('up'), $this->at(2));
        $line->adjust($this->usd('-20.00'), new Comment('down, below zero is fine'), $this->at(3));

        self::assertSame('−$5.00', $line->currentValue()->format());
    }

    #[Test]
    public function it_rejects_a_zero_adjustment_and_records_nothing(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('10.00'), $this->at(1));
        $line->releaseEvents();

        try {
            $line->adjust($this->usd('0.00'), new Comment('no-op'), $this->at(2));
            self::fail('A zero adjustment must be rejected.');
        } catch (ZeroAdjustment $e) {
            self::assertStringContainsString('zero amount', $e->getMessage());
        }

        self::assertSame([], $line->releaseEvents());
        self::assertFalse($line->isFrozen());
        self::assertSame(1, $line->version());
    }

    #[Test]
    public function it_rejects_an_adjustment_in_another_currency(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('10.00'), $this->at(1));

        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('Expected an amount in USD but got EUR');

        $line->adjust(Money::fromMinorUnits(100, Currency::EUR), new Comment('wrong currency'), $this->at(2));
    }

    #[Test]
    public function it_rejects_a_recalculation_in_another_currency(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('10.00'), $this->at(1));

        $this->expectException(CurrencyMismatch::class);

        $line->recalculate(Money::fromMinorUnits(100, Currency::EUR), $this->at(2));
    }

    #[Test]
    public function it_reconstitutes_the_same_state_from_its_own_events(): void
    {
        $original = $this->assignmentScenario();

        $replayed = EarningLine::reconstitute($this->id(), $original->releaseEvents());

        self::assertTrue($replayed->id()->equals($original->id()));
        self::assertSame($original->version(), $replayed->version());
        self::assertSame($original->frozenAtVersion(), $replayed->frozenAtVersion());
        self::assertTrue($replayed->systemValue()->equals($original->systemValue()));
        self::assertTrue($replayed->currentValue()->equals($original->currentValue()));
        self::assertEquals($original->adjustments(), $replayed->adjustments());
        self::assertSame([], $replayed->releaseEvents(), 'replayed history is not pending');
    }

    #[Test]
    public function it_continues_numbering_and_versioning_after_reconstitution(): void
    {
        $line = EarningLine::reconstitute($this->id(), $this->assignmentScenario()->releaseEvents());

        $line->adjust($this->usd('-4.45'), new Comment('round down'), $this->at(9));

        self::assertSame(9, $line->version());
        self::assertSame(6, $line->adjustments()[5]->number);
        self::assertSame('$1,100.00', $line->currentValue()->format());
        self::assertCount(1, $line->releaseEvents());
    }

    #[Test]
    public function it_cannot_be_reconstituted_from_an_empty_history(): void
    {
        $this->expectException(EarningLineNotFound::class);
        $this->expectExceptionMessage(self::LINE_ID);

        EarningLine::reconstitute($this->id(), []);
    }

    #[Test]
    public function it_refuses_events_it_does_not_understand(): void
    {
        $unknown = new readonly class($this->id(), $this->at(1)) implements DomainEvent
        {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $recordedAt) {}
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot apply');

        EarningLine::reconstitute($this->id(), [$unknown]);
    }

    #[Test]
    public function current_value_always_equals_frozen_system_value_plus_the_sum_of_adjustments(): void
    {
        mt_srand(20_260_912);

        for ($run = 0; $run < 25; $run++) {
            $systemValue = mt_rand(0, 500_000);
            $line = EarningLine::calculate($this->id(), Money::fromMinorUnits($systemValue, Currency::USD), $this->at(0));
            $adjustments = 0;

            for ($step = 1; $step <= 20; $step++) {
                if (mt_rand(0, 2) === 0) {
                    $attempt = mt_rand(0, 500_000);
                    $line->recalculate(Money::fromMinorUnits($attempt, Currency::USD), $this->at($step));
                    $systemValue = $line->isFrozen() ? $systemValue : $attempt;
                } else {
                    $amount = mt_rand(1, 10_000) * (mt_rand(0, 1) === 0 ? -1 : 1);
                    $line->adjust(Money::fromMinorUnits($amount, Currency::USD), new Comment("step $step"), $this->at($step));
                    $adjustments += $amount;
                }

                self::assertSame($systemValue, $line->systemValue()->minorUnits, "run $run step $step");
                self::assertSame($systemValue + $adjustments, $line->currentValue()->minorUnits, "run $run step $step");
            }
        }
    }

    #[Test]
    public function it_keeps_the_comment_and_timestamp_on_each_adjustment(): void
    {
        $line = EarningLine::calculate($this->id(), $this->usd('10.00'), $this->at(1));

        $line->adjust($this->usd('-1.00'), new Comment('  spaced comment  '), $this->at(2));

        $adjustment = $line->adjustments()[0];
        self::assertSame('spaced comment', $adjustment->comment->text);
        self::assertEquals($this->at(2), $adjustment->recordedAt);
        self::assertSame(-100, $adjustment->amount->minorUnits);
    }

    private function assignmentScenario(): EarningLine
    {
        $line = EarningLine::calculate($this->id(), $this->usd('1,000.00'), $this->at(1));
        $line->recalculate($this->usd('1,050.00'), $this->at(2));
        $line->adjust($this->usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction'), $this->at(3));
        $line->recalculate($this->usd('1,200.00'), $this->at(4));
        $line->adjust($this->usd('+100.10'), new Comment('Late correction: missed approved overtime bonus'), $this->at(5));
        $line->adjust($this->usd('-0.10'), new Comment('Minor rounding adjustment'), $this->at(6));
        $line->adjust($this->usd('-0.20'), new Comment('Second minor rounding adjustment'), $this->at(7));
        $line->adjust($this->usd('+0.20'), new Comment('Correcting mistake in adjustment #4'), $this->at(8));

        return $line;
    }

    private function id(): EarningLineId
    {
        return EarningLineId::fromString(self::LINE_ID);
    }

    private function usd(string $amount): Money
    {
        return Money::fromDecimal($amount, Currency::USD);
    }

    private function at(int $step): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-09-12 09:%02d:00', $step));
    }
}
