<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsPayrollErrors;
use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\CommandBus;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use Illuminate\Console\Command;

/** Plays the payroll specialist. The comment is optional here on purpose: the domain refuses a blank one. */
final class PayrollAdjustCommand extends Command
{
    use ReportsPayrollErrors;

    protected $signature = 'payroll:adjust
        {line : The earning line id (UUID)}
        {amount : Signed amount in USD, e.g. -45.55 or +100.10}
        {comment? : Why the adjustment is made (mandatory; try omitting it)}';

    protected $description = 'Record a manual adjustment on an earning line';

    public function handle(CommandBus $bus, GetLineHistoryHandler $history): int
    {
        return $this->attempt(function () use ($bus, $history): void {
            $id = EarningLineId::fromString($this->argument('line'));
            $amount = Money::fromDecimal($this->argument('amount'), Currency::USD);
            $comment = new Comment($this->argument('comment') ?? '');

            $bus->dispatch(new AdjustLine($id, $amount, $comment));

            $lineHistory = $history(new GetLineHistory($id));
            $this->info(sprintf(
                'Adjustment %d recorded on line %s: %s. Current value: %s.',
                count($lineHistory->adjustments),
                $id->toString(),
                $amount->formatSigned(),
                $lineHistory->currentValue->format(),
            ));
        });
    }
}
