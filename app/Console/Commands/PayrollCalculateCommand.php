<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsPayrollErrors;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\CommandBus;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use Illuminate\Console\Command;

/** Plays the system: calculates a new line, or recalculates an existing one (which a frozen line ignores). */
final class PayrollCalculateCommand extends Command
{
    use ReportsPayrollErrors;

    protected $signature = 'payroll:calculate
        {amount : The system-calculated value in USD, e.g. 1050.00}
        {--line= : Recalculate this existing line instead of creating a new one}';

    protected $description = 'Calculate a new earning line, or recalculate an existing one';

    public function handle(CommandBus $bus, GetLineHistoryHandler $history): int
    {
        return $this->attempt(function () use ($bus, $history): void {
            $value = Money::fromDecimal($this->argument('amount'), Currency::USD);
            $existing = $this->option('line');

            if ($existing === null) {
                $id = EarningLineId::generate();
                $bus->dispatch(new CalculateLine($id, $value));
                $this->info("Calculated new earning line {$id->toString()}: {$value->format()}");

                return;
            }

            $id = EarningLineId::fromString($existing);
            $bus->dispatch(new RecalculateLine($id, $value));
            $lineHistory = $history(new GetLineHistory($id));

            if ($lineHistory->frozenAtVersion !== null) {
                $this->warn(sprintf(
                    'Recalculation to %s ignored: line %s has manual adjustments (frozen at step %d). Current value stays %s.',
                    $value->format(),
                    $id->toString(),
                    $lineHistory->frozenAtVersion,
                    $lineHistory->currentValue->format(),
                ));

                return;
            }

            $this->info("Recalculated earning line {$id->toString()}: {$lineHistory->currentValue->format()}");
        });
    }
}
