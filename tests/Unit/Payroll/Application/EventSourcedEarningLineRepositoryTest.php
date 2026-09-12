<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Application;

use App\Payroll\Application\EventSourcedEarningLineRepository;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use App\Payroll\Domain\Exception\EarningLineNotFound;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Persistence\InMemoryEventStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingProjector;

final class EventSourcedEarningLineRepositoryTest extends TestCase
{
    private InMemoryEventStore $eventStore;

    private RecordingProjector $projector;

    private EventSourcedEarningLineRepository $repository;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore;
        $this->projector = new RecordingProjector;
        $this->repository = new EventSourcedEarningLineRepository($this->eventStore, $this->projector);
    }

    #[Test]
    public function it_round_trips_a_line_through_the_event_stream(): void
    {
        $id = EarningLineId::generate();
        $line = EarningLine::calculate($id, $this->usd('1,000.00'), $this->at(1));
        $line->adjust($this->usd('-45.55'), new Comment('dental'), $this->at(2));
        $this->repository->save($line);

        $loaded = $this->repository->get($id);

        self::assertTrue($loaded->id()->equals($id));
        self::assertSame(2, $loaded->version());
        self::assertSame(2, $loaded->frozenAtVersion());
        self::assertSame('$954.45', $loaded->currentValue()->format());
        self::assertEquals($line->adjustments(), $loaded->adjustments());
        self::assertSame([], $loaded->releaseEvents());
    }

    #[Test]
    public function it_projects_only_the_new_events_with_their_stored_versions(): void
    {
        $id = EarningLineId::generate();
        $line = EarningLine::calculate($id, $this->usd('1,000.00'), $this->at(1));
        $line->recalculate($this->usd('1,050.00'), $this->at(2));
        $this->repository->save($line);
        self::assertSame([1, 2], $this->projector->versions);
        $this->projector->projected = [];
        $this->projector->versions = [];

        $reloaded = $this->repository->get($id);
        $reloaded->adjust($this->usd('-1.00'), new Comment('later'), $this->at(3));
        $this->repository->save($reloaded);

        self::assertCount(1, $this->projector->projected, 'replayed history is not projected again');
        self::assertSame([3], $this->projector->versions);
        self::assertInstanceOf(LineAdjusted::class, $this->projector->projected[0]);
        self::assertSame($this->eventStore->load($id)[2], $this->projector->projected[0], 'the projected event is the stored one');
    }

    #[Test]
    public function it_appends_and_projects_nothing_for_a_line_without_new_events(): void
    {
        $id = EarningLineId::generate();
        $this->repository->save(EarningLine::calculate($id, $this->usd('1.00'), $this->at(1)));
        $this->projector->projected = [];

        $this->repository->save($this->repository->get($id));

        self::assertCount(1, $this->eventStore->load($id));
        self::assertSame([], $this->projector->projected);
    }

    #[Test]
    public function it_detects_a_stale_copy_through_the_expected_version(): void
    {
        $id = EarningLineId::generate();
        $this->repository->save(EarningLine::calculate($id, $this->usd('1,000.00'), $this->at(1)));

        $first = $this->repository->get($id);
        $second = $this->repository->get($id);
        $first->adjust($this->usd('-1.00'), new Comment('first writer'), $this->at(2));
        $second->adjust($this->usd('-2.00'), new Comment('second writer, stale'), $this->at(2));

        $this->repository->save($first);

        $this->expectException(ConcurrencyConflict::class);
        $this->expectExceptionMessage('expected version 1');

        try {
            $this->repository->save($second);
        } finally {
            self::assertSame('$999.00', $this->repository->get($id)->currentValue()->format(), 'the stale write left no trace');
            self::assertCount(2, $this->projector->projected);
        }
    }

    #[Test]
    public function it_reports_an_unknown_line_as_not_found(): void
    {
        $this->expectException(EarningLineNotFound::class);

        $this->repository->get(EarningLineId::generate());
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
