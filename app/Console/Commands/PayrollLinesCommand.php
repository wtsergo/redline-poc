<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Console\Command;

/** Reads the earning_lines projection: the CQRS query side answers list questions, not the event stream. */
final class PayrollLinesCommand extends Command
{
    protected $signature = 'payroll:lines';

    protected $description = 'List every earning line with its current value (from the read model)';

    public function handle(): int
    {
        $lines = EarningLineSummary::query()->orderBy('calculated_at')->orderBy('id')->get();

        if ($lines->isEmpty()) {
            $this->line('No earning lines yet. Run payroll:demo or payroll:calculate to create one.');

            return self::SUCCESS;
        }

        $this->table(
            ['Line', 'System value', 'Adjustments', 'Frozen at step', 'Current value', 'Last event'],
            $lines->map(static fn (EarningLineSummary $line): array => [
                $line->id,
                $line->systemValue()->format(),
                $line->adjustment_count,
                $line->frozen_at_version ?? '—',
                $line->currentValue()->format(),
                $line->last_event_at->format('Y-m-d H:i:s'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
