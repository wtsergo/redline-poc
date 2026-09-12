<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersLineHistory;
use App\Console\Commands\Concerns\ReportsPayrollErrors;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\EarningLineId;
use Illuminate\Console\Command;

final class PayrollHistoryCommand extends Command
{
    use RendersLineHistory;
    use ReportsPayrollErrors;

    protected $signature = 'payroll:history {line : The earning line id (UUID)}';

    protected $description = 'Print the audit history of an earning line: frozen system value, every adjustment, current value';

    public function handle(GetLineHistoryHandler $history): int
    {
        return $this->attempt(function () use ($history): void {
            $lineHistory = $history(new GetLineHistory(EarningLineId::fromString($this->argument('line'))));

            $this->renderAuditTable($lineHistory);
            $this->renderAuditDetails($lineHistory);
        });
    }
}
