<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\EarningLineRepository;

/**
 * Rebuilds lines from their event stream and stores new events with optimistic concurrency:
 * the version the line was loaded at is the version the store must still be at.
 */
final readonly class EventSourcedEarningLineRepository implements EarningLineRepository
{
    public function __construct(
        private EventStore $eventStore,
        private Projector $projector,
    ) {}

    public function get(EarningLineId $id): EarningLine
    {
        return EarningLine::reconstitute($id, $this->eventStore->load($id));
    }

    public function save(EarningLine $line): void
    {
        $events = $line->releaseEvents();

        if ($events === []) {
            return;
        }

        $version = $line->version() - count($events);
        $this->eventStore->append($line->id(), $version, $events);

        foreach ($events as $event) {
            $this->projector->project($event, ++$version);
        }
    }
}
