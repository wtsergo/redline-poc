<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payroll\Application\EventStore;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Persistence\DatabaseEventStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnexpectedValueException;

/** Runs on SQLite in memory locally and on MySQL in CI: same code, same triggers, same expectations. */
final class DatabaseEventStoreTest extends TestCase
{
    use RefreshDatabase;

    private EventStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->app->make(DatabaseEventStore::class);
    }

    #[Test]
    public function it_round_trips_every_event_type_in_version_order(): void
    {
        $id = EarningLineId::generate();
        $events = [
            new LineCalculated($id, $this->usd('1,000.00'), $this->at(1)),
            new LineRecalculated($id, $this->usd('1,050.00'), $this->at(2)),
            new LineAdjusted($id, 1, $this->usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction'), $this->at(3)),
            new RecalculationIgnored($id, $this->usd('1,200.00'), $this->at(4)),
        ];

        $this->store->append($id, 0, array_slice($events, 0, 2));
        $this->store->append($id, 2, array_slice($events, 2));

        self::assertEquals($events, $this->store->load($id));
        self::assertSame([1, 2, 3, 4], DB::table('earning_line_events')->where('aggregate_id', $id->toString())->orderBy('id')->pluck('version')->all());
    }

    #[Test]
    public function it_keeps_the_streams_of_different_lines_apart(): void
    {
        $a = EarningLineId::generate();
        $b = EarningLineId::generate();

        $this->store->append($a, 0, [new LineCalculated($a, $this->usd('1.00'), $this->at(1))]);
        $this->store->append($b, 0, [new LineCalculated($b, $this->usd('2.00'), $this->at(1))]);
        $this->store->append($a, 1, [new LineRecalculated($a, $this->usd('3.00'), $this->at(2))]);

        self::assertCount(2, $this->store->load($a));
        self::assertCount(1, $this->store->load($b));
        self::assertSame([], $this->store->load(EarningLineId::generate()));
    }

    #[Test]
    public function it_rejects_a_stale_writer_and_writes_nothing_of_its_batch(): void
    {
        $id = EarningLineId::generate();
        $this->store->append($id, 0, [new LineCalculated($id, $this->usd('1,000.00'), $this->at(1))]);
        $stale = [
            new LineRecalculated($id, $this->usd('1,050.00'), $this->at(2)),
            new LineRecalculated($id, $this->usd('1,075.00'), $this->at(3)),
        ];

        try {
            $this->store->append($id, 0, $stale);
            self::fail('Appending from a stale version must conflict on the unique index.');
        } catch (ConcurrencyConflict $e) {
            self::assertStringContainsString('expected version 0', $e->getMessage());
        }

        self::assertCount(1, $this->store->load($id), 'the multi-row insert is atomic: no partial batch');
    }

    #[Test]
    public function it_refuses_updates_at_the_storage_layer(): void
    {
        $id = EarningLineId::generate();
        $this->store->append($id, 0, [new LineCalculated($id, $this->usd('1,000.00'), $this->at(1))]);

        try {
            DB::table('earning_line_events')->where('aggregate_id', $id->toString())->update(['payload' => '{"amount_minor":1,"currency":"USD"}']);
            self::fail('The append-only trigger must refuse the UPDATE.');
        } catch (QueryException $e) {
            self::assertStringContainsString('earning_line_events is append-only', $e->getMessage());
        }

        self::assertSame('$1,000.00', $this->loadCalculatedValue($id));
    }

    #[Test]
    public function it_refuses_deletes_at_the_storage_layer(): void
    {
        $id = EarningLineId::generate();
        $this->store->append($id, 0, [new LineCalculated($id, $this->usd('1,000.00'), $this->at(1))]);

        try {
            DB::table('earning_line_events')->where('aggregate_id', $id->toString())->delete();
            self::fail('The append-only trigger must refuse the DELETE.');
        } catch (QueryException $e) {
            self::assertStringContainsString('earning_line_events is append-only', $e->getMessage());
        }

        self::assertCount(1, $this->store->load($id));
    }

    #[Test]
    public function it_fails_loudly_instead_of_guessing_when_a_row_is_corrupt(): void
    {
        $id = EarningLineId::generate();
        DB::table('earning_line_events')->insert([
            'aggregate_id' => $id->toString(),
            'version' => 1,
            'event_type' => 'line_deleted',
            'payload' => '{}',
            'recorded_at' => '2026-09-12 09:00:00.000000',
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('line_deleted');

        $this->store->load($id);
    }

    private function loadCalculatedValue(EarningLineId $id): string
    {
        $event = $this->store->load($id)[0];
        self::assertInstanceOf(LineCalculated::class, $event);

        return $event->value->format();
    }

    private function usd(string $amount): Money
    {
        return Money::fromDecimal($amount, Currency::USD);
    }

    private function at(int $step): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-09-12 09:%02d:00.%06d', $step, $step * 111_111), new DateTimeZone('UTC'));
    }
}
