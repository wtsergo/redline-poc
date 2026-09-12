<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Persistence;

use App\Payroll\Application\EventStore;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Event store over the earning_line_events table.
 *
 * Concurrency: rows are inserted at versions expectedVersion+1…n in one statement. A stale
 * writer hits UNIQUE (aggregate_id, version), the statement is rolled back as a whole, and
 * the collision surfaces as a ConcurrencyConflict. The table's triggers refuse any UPDATE or
 * DELETE, so this class does not even have code paths for them.
 */
final readonly class DatabaseEventStore implements EventStore
{
    private const string TABLE = 'earning_line_events';

    public function __construct(
        private ConnectionInterface $connection,
        private EventSerializer $serializer,
    ) {}

    public function append(EarningLineId $id, int $expectedVersion, array $events): void
    {
        $version = $expectedVersion;
        $rows = [];

        foreach ($events as $event) {
            $rows[] = [...$this->serializer->toRow($event), 'version' => ++$version];
        }

        try {
            $this->connection->table(self::TABLE)->insert($rows);
        } catch (UniqueConstraintViolationException) {
            throw ConcurrencyConflict::forLine($id, $expectedVersion);
        }
    }

    public function load(EarningLineId $id): array
    {
        $rows = $this->connection->table(self::TABLE)
            ->where('aggregate_id', $id->toString())
            ->orderBy('version')
            ->get(['aggregate_id', 'event_type', 'payload', 'recorded_at']);

        return array_values(array_map(
            fn (object $row): DomainEvent => $this->serializer->fromRow(get_object_vars($row)),
            $rows->all(),
        ));
    }
}
