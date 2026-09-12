<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Projection;

use App\Payroll\Application\Projector;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use LogicException;

/**
 * Keeps earning_lines in step with the event stream. Idempotent per event: a version the
 * row has already seen is skipped, so a replay after a crash cannot double-apply anything.
 */
final readonly class EarningLineSummaryProjector implements Projector
{
    public function project(DomainEvent $event, int $version): void
    {
        if ($event instanceof LineCalculated) {
            EarningLineSummary::query()->firstOrCreate(['id' => $event->lineId->toString()], [
                'currency' => $event->value->currency->value,
                'system_value_minor' => $event->value->minorUnits,
                'current_value_minor' => $event->value->minorUnits,
                'adjustment_count' => 0,
                'frozen_at_version' => null,
                'version' => $version,
                'calculated_at' => $event->recordedAt,
                'last_event_at' => $event->recordedAt,
            ]);

            return;
        }

        $summary = EarningLineSummary::query()->findOrFail($event->lineId->toString());

        if ($summary->version >= $version) {
            return; // already applied
        }

        match (true) {
            $event instanceof LineRecalculated => $this->recalculated($summary, $event),
            $event instanceof LineAdjusted => $this->adjusted($summary, $event, $version),
            $event instanceof RecalculationIgnored => null, // the row does not change, only its version
            default => throw new LogicException(sprintf('%s cannot project %s.', self::class, $event::class)),
        };

        $summary->version = $version;
        $summary->last_event_at = $event->recordedAt;
        $summary->save();
    }

    private function recalculated(EarningLineSummary $summary, LineRecalculated $event): void
    {
        // Only ever happens before the first adjustment, so current value == system value.
        $summary->system_value_minor = $event->value->minorUnits;
        $summary->current_value_minor = $event->value->minorUnits;
    }

    private function adjusted(EarningLineSummary $summary, LineAdjusted $event, int $version): void
    {
        $summary->current_value_minor += $event->amount->minorUnits;
        $summary->adjustment_count = $event->number;

        if ($summary->frozen_at_version === null) {
            $summary->frozen_at_version = $version;
        }
    }
}
