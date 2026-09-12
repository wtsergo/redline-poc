<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use App\Payroll\Infrastructure\Projection\EarningLineSummaryProjector;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EarningLineSummaryProjectorTest extends TestCase
{
    use RefreshDatabase;

    private EarningLineSummaryProjector $projector;

    private EarningLineId $id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projector = new EarningLineSummaryProjector;
        $this->id = EarningLineId::generate();
    }

    #[Test]
    public function it_creates_the_row_when_a_line_is_calculated(): void
    {
        $this->projector->project(new LineCalculated($this->id, $this->usd('1,000.00'), $this->at(1)), 1);

        $summary = $this->summary();
        self::assertSame('USD', $summary->currency);
        self::assertSame('$1,000.00', $summary->systemValue()->format());
        self::assertSame('$1,000.00', $summary->currentValue()->format());
        self::assertSame(0, $summary->adjustment_count);
        self::assertFalse($summary->isFrozen());
        self::assertSame(1, $summary->version);
        self::assertSame('2026-09-12 09:01:00', $summary->calculated_at->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-12 09:01:00', $summary->last_event_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_follows_the_assignment_scenario_step_by_step(): void
    {
        $steps = [
            // event, current value, system value, adjustment count, frozen at
            [new LineCalculated($this->id, $this->usd('1,000.00'), $this->at(1)), '$1,000.00', '$1,000.00', 0, null],
            [new LineRecalculated($this->id, $this->usd('1,050.00'), $this->at(2)), '$1,050.00', '$1,050.00', 0, null],
            [new LineAdjusted($this->id, 1, $this->usd('-45.55'), new Comment('dental'), $this->at(3)), '$1,004.45', '$1,050.00', 1, 3],
            [new RecalculationIgnored($this->id, $this->usd('1,200.00'), $this->at(4)), '$1,004.45', '$1,050.00', 1, 3],
            [new LineAdjusted($this->id, 2, $this->usd('+100.10'), new Comment('overtime'), $this->at(5)), '$1,104.55', '$1,050.00', 2, 3],
            [new LineAdjusted($this->id, 3, $this->usd('-0.10'), new Comment('rounding'), $this->at(6)), '$1,104.45', '$1,050.00', 3, 3],
            [new LineAdjusted($this->id, 4, $this->usd('-0.20'), new Comment('rounding'), $this->at(7)), '$1,104.25', '$1,050.00', 4, 3],
            [new LineAdjusted($this->id, 5, $this->usd('+0.20'), new Comment('fix #4'), $this->at(8)), '$1,104.45', '$1,050.00', 5, 3],
        ];

        foreach ($steps as $index => [$event, $current, $system, $count, $frozenAt]) {
            $version = $index + 1;
            $this->projector->project($event, $version);

            $summary = $this->summary();
            self::assertSame($current, $summary->currentValue()->format(), "step $version");
            self::assertSame($system, $summary->systemValue()->format(), "step $version");
            self::assertSame($count, $summary->adjustment_count, "step $version");
            self::assertSame($frozenAt, $summary->frozen_at_version, "step $version");
            self::assertSame($version, $summary->version, "step $version");
            self::assertEquals($event->recordedAt, $summary->last_event_at, "step $version");
        }

        self::assertSame(1, EarningLineSummary::query()->count());
    }

    #[Test]
    public function it_is_idempotent_per_event(): void
    {
        $calculated = new LineCalculated($this->id, $this->usd('1,000.00'), $this->at(1));
        $adjusted = new LineAdjusted($this->id, 1, $this->usd('-45.55'), new Comment('dental'), $this->at(2));

        $this->projector->project($calculated, 1);
        $this->projector->project($calculated, 1);
        $this->projector->project($adjusted, 2);
        $this->projector->project($adjusted, 2);
        $this->projector->project($calculated, 1); // an old event arriving again

        self::assertSame(1, EarningLineSummary::query()->count());
        $summary = $this->summary();
        self::assertSame('$954.45', $summary->currentValue()->format());
        self::assertSame(1, $summary->adjustment_count);
        self::assertSame(2, $summary->version);
    }

    #[Test]
    public function it_refuses_to_project_onto_a_line_it_has_never_seen(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->projector->project(new LineAdjusted($this->id, 1, $this->usd('-1.00'), new Comment('orphan'), $this->at(1)), 1);
    }

    #[Test]
    public function it_refuses_events_it_does_not_understand(): void
    {
        $this->projector->project(new LineCalculated($this->id, $this->usd('1,000.00'), $this->at(1)), 1);
        $unknown = new readonly class($this->id, $this->at(2)) implements DomainEvent
        {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $recordedAt) {}
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot project');

        $this->projector->project($unknown, 2);
    }

    private function summary(): EarningLineSummary
    {
        return EarningLineSummary::query()->findOrFail($this->id->toString());
    }

    private function usd(string $amount): Money
    {
        return Money::fromDecimal($amount, Currency::USD);
    }

    private function at(int $step): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-09-12 09:%02d:00', $step), new DateTimeZone('UTC'));
    }
}
