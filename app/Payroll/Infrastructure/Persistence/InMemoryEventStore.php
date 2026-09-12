<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Persistence;

use App\Payroll\Application\EventStore;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Exception\ConcurrencyConflict;

/** Event store for tests and demos that need no database. Same contract, same conflict rule. */
final class InMemoryEventStore implements EventStore
{
    /** @var array<string, list<DomainEvent>> */
    private array $streams = [];

    public function append(EarningLineId $id, int $expectedVersion, array $events): void
    {
        $stream = $this->load($id);

        if (count($stream) !== $expectedVersion) {
            throw ConcurrencyConflict::forLine($id, $expectedVersion);
        }

        $this->streams[$id->toString()] = [...$stream, ...$events];
    }

    public function load(EarningLineId $id): array
    {
        return $this->streams[$id->toString()] ?? [];
    }
}
