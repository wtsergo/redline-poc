<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payroll\Application\CommandBus;
use App\Payroll\Application\EventSourcedEarningLineRepository;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\Projector;
use App\Payroll\Domain\EarningLineRepository;
use App\Payroll\Infrastructure\Persistence\DatabaseEventStore;
use App\Payroll\Infrastructure\Projection\EarningLineSummaryProjector;
use App\Payroll\Infrastructure\SystemClock;
use App\Payroll\Infrastructure\TransactionalCommandBus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Tests\TestCase;

final class PayrollServiceProviderTest extends TestCase
{
    /**
     * @param  class-string  $port
     * @param  class-string  $adapter
     */
    #[Test]
    #[DataProvider('bindings')]
    public function it_binds_every_port_to_its_laravel_backed_adapter(string $port, string $adapter): void
    {
        self::assertInstanceOf($adapter, $this->app->make($port));
    }

    /** @return iterable<string, array{class-string, class-string}> */
    public static function bindings(): iterable
    {
        yield 'clock' => [ClockInterface::class, SystemClock::class];
        yield 'event store' => [EventStore::class, DatabaseEventStore::class];
        yield 'projector' => [Projector::class, EarningLineSummaryProjector::class];
        yield 'repository' => [EarningLineRepository::class, EventSourcedEarningLineRepository::class];
        yield 'command bus' => [CommandBus::class, TransactionalCommandBus::class];
    }

    #[Test]
    public function the_clock_follows_laravel_time_travel(): void
    {
        $this->travelTo('2026-01-01 12:00:00');

        self::assertEquals(new DateTimeImmutable('2026-01-01 12:00:00'), $this->app->make(ClockInterface::class)->now());
    }
}
