<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Application;

use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\Command\AdjustLineHandler;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\CalculateLineHandler;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\Command\RecalculateLineHandler;
use App\Payroll\Application\EventSourcedEarningLineRepository;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\Adjustment;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use App\Payroll\Domain\Exception\EarningLineNotFound;
use App\Payroll\Domain\Exception\ZeroAdjustment;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Persistence\InMemoryEventStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\RecordingProjector;

/** The use cases end to end over the in-memory store: no framework, no database. */
final class CommandHandlersTest extends TestCase
{
    private InMemoryEventStore $eventStore;

    private RecordingProjector $projector;

    private FrozenClock $clock;

    private CalculateLineHandler $calculate;

    private RecalculateLineHandler $recalculate;

    private AdjustLineHandler $adjust;

    private GetLineHistoryHandler $history;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore;
        $this->projector = new RecordingProjector;
        $this->clock = new FrozenClock;

        $lines = new EventSourcedEarningLineRepository($this->eventStore, $this->projector);

        $this->calculate = new CalculateLineHandler($lines, $this->clock);
        $this->recalculate = new RecalculateLineHandler($lines, $this->clock);
        $this->adjust = new AdjustLineHandler($lines, $this->clock);
        $this->history = new GetLineHistoryHandler($this->eventStore);
    }

    #[Test]
    public function it_runs_the_assignment_scenario_through_the_handlers(): void
    {
        $id = EarningLineId::generate();

        ($this->calculate)(new CalculateLine($id, $this->usd('1,000.00')));
        ($this->recalculate)(new RecalculateLine($id, $this->usd('1,050.00')));
        ($this->adjust)(new AdjustLine($id, $this->usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction')));
        ($this->recalculate)(new RecalculateLine($id, $this->usd('1,200.00')));
        ($this->adjust)(new AdjustLine($id, $this->usd('+100.10'), new Comment('Late correction: missed approved overtime bonus')));
        ($this->adjust)(new AdjustLine($id, $this->usd('-0.10'), new Comment('Minor rounding adjustment')));
        ($this->adjust)(new AdjustLine($id, $this->usd('-0.20'), new Comment('Second minor rounding adjustment')));
        ($this->adjust)(new AdjustLine($id, $this->usd('+0.20'), new Comment('Correcting mistake in adjustment #4')));

        $history = ($this->history)(new GetLineHistory($id));

        self::assertTrue($history->lineId->equals($id));
        self::assertSame('$1,050.00', $history->systemValue->format());
        self::assertSame(3, $history->frozenAtVersion);
        self::assertSame(
            ['−$45.55', '+$100.10', '−$0.10', '−$0.20', '+$0.20'],
            array_map(fn (Adjustment $a): string => $a->amount->formatSigned(), $history->adjustments),
        );
        self::assertSame('$1,104.45', $history->currentValue->format());
        self::assertSame(8, $history->version);

        self::assertCount(1, $history->ignoredRecalculations);
        self::assertSame(4, $history->ignoredRecalculations[0]->version);
        self::assertSame('$1,200.00', $history->ignoredRecalculations[0]->attemptedValue->format());

        self::assertSame([
            LineCalculated::class,
            LineRecalculated::class,
            LineAdjusted::class,
            RecalculationIgnored::class,
            LineAdjusted::class,
            LineAdjusted::class,
            LineAdjusted::class,
            LineAdjusted::class,
        ], array_map(fn (DomainEvent $e): string => $e::class, $this->projector->projected), 'every stored event reaches the projector, in order');
        self::assertSame(range(1, 8), $this->projector->versions, 'with the version it was stored at');
    }

    #[Test]
    public function it_stamps_every_event_with_the_clock(): void
    {
        $id = EarningLineId::generate();

        ($this->calculate)(new CalculateLine($id, $this->usd('1,000.00')));
        $this->clock->advance('+1 day');
        ($this->adjust)(new AdjustLine($id, $this->usd('-1.00'), new Comment('a day later')));

        [$calculated, $adjusted] = $this->eventStore->load($id);

        self::assertEquals(new DateTimeImmutable('2026-09-12 09:00:00'), $calculated->recordedAt);
        self::assertEquals(new DateTimeImmutable('2026-09-13 09:00:00'), $adjusted->recordedAt);
    }

    #[Test]
    public function it_rejects_adjusting_a_line_that_does_not_exist(): void
    {
        $id = EarningLineId::generate();

        $this->expectException(EarningLineNotFound::class);
        $this->expectExceptionMessage($id->toString());

        ($this->adjust)(new AdjustLine($id, $this->usd('-1.00'), new Comment('nobody home')));
    }

    #[Test]
    public function it_rejects_recalculating_a_line_that_does_not_exist(): void
    {
        $this->expectException(EarningLineNotFound::class);

        ($this->recalculate)(new RecalculateLine(EarningLineId::generate(), $this->usd('1.00')));
    }

    #[Test]
    public function it_reports_the_history_of_an_unknown_line_as_not_found(): void
    {
        $this->expectException(EarningLineNotFound::class);

        ($this->history)(new GetLineHistory(EarningLineId::generate()));
    }

    #[Test]
    public function it_rejects_calculating_the_same_line_twice(): void
    {
        $id = EarningLineId::generate();
        ($this->calculate)(new CalculateLine($id, $this->usd('1,000.00')));

        try {
            ($this->calculate)(new CalculateLine($id, $this->usd('2,000.00')));
            self::fail('A second calculation must conflict with the existing stream.');
        } catch (ConcurrencyConflict $e) {
            self::assertStringContainsString('expected version 0', $e->getMessage());
        }

        self::assertCount(1, $this->eventStore->load($id));
        self::assertCount(1, $this->projector->projected);
    }

    #[Test]
    public function it_stores_and_projects_nothing_when_the_domain_rejects_a_command(): void
    {
        $id = EarningLineId::generate();
        ($this->calculate)(new CalculateLine($id, $this->usd('1,000.00')));

        try {
            ($this->adjust)(new AdjustLine($id, $this->usd('0.00'), new Comment('changes nothing')));
            self::fail('A zero adjustment must be rejected.');
        } catch (ZeroAdjustment) {
        }

        self::assertCount(1, $this->eventStore->load($id));
        self::assertCount(1, $this->projector->projected);
        self::assertNull(($this->history)(new GetLineHistory($id))->frozenAtVersion);
    }

    private function usd(string $amount): Money
    {
        return Money::fromDecimal($amount, Currency::USD);
    }
}
