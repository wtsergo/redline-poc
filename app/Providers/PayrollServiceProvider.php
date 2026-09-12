<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\Command\AdjustLineHandler;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\CalculateLineHandler;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\Command\RecalculateLineHandler;
use App\Payroll\Application\CommandBus;
use App\Payroll\Application\EventSourcedEarningLineRepository;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\Projector;
use App\Payroll\Domain\EarningLineRepository;
use App\Payroll\Infrastructure\ContainerCommandBus;
use App\Payroll\Infrastructure\Persistence\DatabaseEventStore;
use App\Payroll\Infrastructure\Projection\EarningLineSummaryProjector;
use App\Payroll\Infrastructure\SystemClock;
use App\Payroll\Infrastructure\TransactionalCommandBus;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

/** Wires the framework-free domain and application layers to their Laravel-backed adapters. */
final class PayrollServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const array HANDLERS = [
        CalculateLine::class => CalculateLineHandler::class,
        RecalculateLine::class => RecalculateLineHandler::class,
        AdjustLine::class => AdjustLineHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(ClockInterface::class, SystemClock::class);
        $this->app->bind(EventStore::class, DatabaseEventStore::class);
        $this->app->bind(Projector::class, EarningLineSummaryProjector::class);
        $this->app->bind(EarningLineRepository::class, EventSourcedEarningLineRepository::class);
        $this->app->bind(CommandBus::class, static fn (Application $app): CommandBus => new TransactionalCommandBus(
            new ContainerCommandBus($app, self::HANDLERS),
            $app->make(ConnectionInterface::class),
        ));
    }
}
