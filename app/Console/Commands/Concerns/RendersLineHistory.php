<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Payroll\Application\Query\LineHistory;

/** Console rendering of the audit view, shared by payroll:demo and payroll:history. */
trait RendersLineHistory
{
    /** The two-column audit table exactly as the assignment expects it. */
    private function renderAuditTable(LineHistory $history): void
    {
        $rows = [[
            $history->frozenAtVersion === null
                ? 'System value'
                : sprintf('System value (frozen at step %d)', $history->frozenAtVersion),
            $history->systemValue->format(),
        ]];

        foreach ($history->adjustments as $adjustment) {
            $rows[] = ["Adjustment {$adjustment->number}", $adjustment->amount->formatSigned()];
        }

        $rows[] = ['Current (new) value', $history->currentValue->format()];

        $this->table(['Entry', 'Value'], $rows);
    }

    /** Everything the audit table leaves out: comments, timestamps and the ignored recalculations. */
    private function renderAuditDetails(LineHistory $history): void
    {
        if ($history->adjustments !== []) {
            $this->table(
                ['#', 'Amount', 'Comment', 'Recorded at'],
                array_map(static fn ($adjustment): array => [
                    $adjustment->number,
                    $adjustment->amount->formatSigned(),
                    $adjustment->comment->text,
                    $adjustment->recordedAt->format('Y-m-d H:i:s'),
                ], $history->adjustments),
            );
        }

        foreach ($history->ignoredRecalculations as $ignored) {
            $this->line(sprintf(
                'Step %d: system recalculation to %s was ignored (line frozen at step %d).',
                $ignored->version,
                $ignored->attemptedValue->format(),
                $history->frozenAtVersion,
            ));
        }
    }
}
