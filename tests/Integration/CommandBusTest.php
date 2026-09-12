<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\CommandBus;
use App\Payroll\Application\Projector;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\Adjustment;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Exception\EarningLineNotFound;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\ContainerCommandBus;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/** The whole stack as the console sees it: container-resolved bus, database event store, projection. */
final class CommandBusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_the_assignment_scenario_end_to_end_through_the_database(): void
    {
        $bus = $this->app->make(CommandBus::class);
        $id = EarningLineId::generate();

        $bus->dispatch(new CalculateLine($id, $this->usd('1,000.00')));
        $bus->dispatch(new RecalculateLine($id, $this->usd('1,050.00')));
        $bus->dispatch(new AdjustLine($id, $this->usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction')));
        $bus->dispatch(new RecalculateLine($id, $this->usd('1,200.00')));
        $bus->dispatch(new AdjustLine($id, $this->usd('+100.10'), new Comment('Late correction: missed approved overtime bonus')));
        $bus->dispatch(new AdjustLine($id, $this->usd('-0.10'), new Comment('Minor rounding adjustment')));
        $bus->dispatch(new AdjustLine($id, $this->usd('-0.20'), new Comment('Second minor rounding adjustment')));
        $bus->dispatch(new AdjustLine($id, $this->usd('+0.20'), new Comment('Correcting mistake in adjustment #4')));

        $history = ($this->app->make(GetLineHistoryHandler::class))(new GetLineHistory($id));
        self::assertSame('$1,050.00', $history->systemValue->format());
        self::assertSame(3, $history->frozenAtVersion);
        self::assertSame(
            ['−$45.55', '+$100.10', '−$0.10', '−$0.20', '+$0.20'],
            array_map(fn (Adjustment $a): string => $a->amount->formatSigned(), $history->adjustments),
        );
        self::assertSame('$1,104.45', $history->currentValue->format());
        self::assertCount(1, $history->ignoredRecalculations);

        $summary = EarningLineSummary::query()->findOrFail($id->toString());
        self::assertSame('$1,104.45', $summary->currentValue()->format());
        self::assertSame('$1,050.00', $summary->systemValue()->format());
        self::assertSame(5, $summary->adjustment_count);
        self::assertSame(3, $summary->frozen_at_version);
        self::assertSame(8, $summary->version);
        self::assertSame(8, DB::table('earning_line_events')->where('aggregate_id', $id->toString())->count());
    }

    #[Test]
    public function it_rolls_back_the_appended_events_when_the_projection_fails(): void
    {
        $this->app->bind(Projector::class, fn (): Projector => new class implements Projector
        {
            public function project(DomainEvent $event, int $version): void
            {
                throw new RuntimeException('projection exploded');
            }
        });
        $id = EarningLineId::generate();

        try {
            $this->app->make(CommandBus::class)->dispatch(new CalculateLine($id, $this->usd('1,000.00')));
            self::fail('The projector failure must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('projection exploded', $e->getMessage());
        }

        self::assertSame(0, DB::table('earning_line_events')->where('aggregate_id', $id->toString())->count(), 'append and projection are one transaction');
        self::assertSame(0, EarningLineSummary::query()->count());
    }

    #[Test]
    public function it_surfaces_domain_errors_unchanged(): void
    {
        $this->expectException(EarningLineNotFound::class);

        $this->app->make(CommandBus::class)->dispatch(new AdjustLine(EarningLineId::generate(), $this->usd('-1.00'), new Comment('nobody home')));
    }

    #[Test]
    public function it_rejects_a_command_without_a_handler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No handler is registered for stdClass');

        $this->app->make(CommandBus::class)->dispatch(new stdClass);
    }

    #[Test]
    public function it_rejects_a_handler_that_is_not_invokable(): void
    {
        $bus = new ContainerCommandBus($this->app, [stdClass::class => stdClass::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be invokable');

        $bus->dispatch(new stdClass);
    }

    private function usd(string $amount): Money
    {
        return Money::fromDecimal($amount, Currency::USD);
    }
}
