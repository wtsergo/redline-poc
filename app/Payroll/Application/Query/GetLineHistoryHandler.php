<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Application\EventStore;
use App\Payroll\Domain\Exception\EarningLineNotFound;

/** Read side: folds the event stream into the audit view without touching the aggregate. */
final readonly class GetLineHistoryHandler
{
    public function __construct(private EventStore $eventStore) {}

    /** @throws EarningLineNotFound */
    public function __invoke(GetLineHistory $query): LineHistory
    {
        $events = $this->eventStore->load($query->lineId);

        if ($events === []) {
            throw EarningLineNotFound::withId($query->lineId);
        }

        return LineHistory::fromEvents($query->lineId, $events);
    }
}
