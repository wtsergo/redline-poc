<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Application;

use App\Payroll\Application\Query\LineHistory;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Money;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineHistoryTest extends TestCase
{
    #[Test]
    public function it_shows_a_never_adjusted_line_as_still_recalculable(): void
    {
        $line = EarningLine::calculate(EarningLineId::generate(), $this->usd('1,000.00'), $this->at(1));
        $line->recalculate($this->usd('1,050.00'), $this->at(2));

        $history = LineHistory::fromEvents($line->id(), $line->releaseEvents());

        self::assertNull($history->frozenAtVersion);
        self::assertSame([], $history->adjustments);
        self::assertSame([], $history->ignoredRecalculations);
        self::assertSame('$1,050.00', $history->systemValue->format(), 'the latest system value, not the first (assumption A7)');
        self::assertSame('$1,050.00', $history->currentValue->format());
        self::assertSame(2, $history->version);
    }

    #[Test]
    public function it_keeps_ignored_recalculations_out_of_the_adjustment_numbering(): void
    {
        $line = EarningLine::calculate(EarningLineId::generate(), $this->usd('1,000.00'), $this->at(1));
        $line->adjust($this->usd('-1.00'), new Comment('one'), $this->at(2));
        $line->recalculate($this->usd('9,999.00'), $this->at(3));
        $line->recalculate($this->usd('8,888.00'), $this->at(4));
        $line->adjust($this->usd('-2.00'), new Comment('two'), $this->at(5));

        $history = LineHistory::fromEvents($line->id(), $line->releaseEvents());

        self::assertSame([1, 2], array_map(fn ($a): int => $a->number, $history->adjustments));
        self::assertSame([3, 4], array_map(fn ($i): int => $i->version, $history->ignoredRecalculations));
        self::assertSame(['$9,999.00', '$8,888.00'], array_map(fn ($i): string => $i->attemptedValue->format(), $history->ignoredRecalculations));
        self::assertEquals($this->at(4), $history->ignoredRecalculations[1]->recordedAt);
        self::assertSame('$997.00', $history->currentValue->format());
    }

    #[Test]
    public function it_agrees_with_the_aggregate_for_any_sequence_of_events(): void
    {
        mt_srand(368_864);

        for ($run = 0; $run < 25; $run++) {
            $line = EarningLine::calculate(EarningLineId::generate(), Money::fromMinorUnits(mt_rand(0, 500_000), Currency::USD), $this->at(0));

            for ($step = 1; $step <= 15; $step++) {
                if (mt_rand(0, 2) === 0) {
                    $line->recalculate(Money::fromMinorUnits(mt_rand(0, 500_000), Currency::USD), $this->at($step));
                } else {
                    $line->adjust(Money::fromMinorUnits(mt_rand(1, 10_000) * (mt_rand(0, 1) === 0 ? -1 : 1), Currency::USD), new Comment("step $step"), $this->at($step));
                }
            }

            $events = $line->releaseEvents();
            $history = LineHistory::fromEvents($line->id(), $events);

            self::assertTrue($history->currentValue->equals($line->currentValue()), "run $run");
            self::assertTrue($history->systemValue->equals($line->systemValue()), "run $run");
            self::assertSame($line->frozenAtVersion(), $history->frozenAtVersion, "run $run");
            self::assertEquals($line->adjustments(), $history->adjustments, "run $run");
            self::assertSame($line->version(), $history->version, "run $run");
            self::assertCount(count(array_filter($events, fn (DomainEvent $e): bool => $e instanceof RecalculationIgnored)), $history->ignoredRecalculations, "run $run");
        }
    }

    #[Test]
    public function it_refuses_a_stream_that_does_not_start_with_a_calculation(): void
    {
        $id = EarningLineId::generate();
        $orphan = new LineAdjusted($id, 1, $this->usd('-1.00'), new Comment('orphan'), $this->at(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must start with a calculation');

        LineHistory::fromEvents($id, [$orphan]);
    }

    #[Test]
    public function it_refuses_events_it_does_not_understand(): void
    {
        $id = EarningLineId::generate();
        $unknown = new readonly class($id, $this->at(1)) implements DomainEvent
        {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $recordedAt) {}
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot fold');

        LineHistory::fromEvents($id, [$unknown]);
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
