<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersLineHistory;
use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\CommandBus;
use App\Payroll\Application\Query\GetLineHistory;
use App\Payroll\Application\Query\GetLineHistoryHandler;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use Illuminate\Console\Command;

/**
 * Replays the eight steps of the assignment through the real command bus, event store and
 * projection, then prints the two tables the assignment expects. Every run creates a new line.
 */
final class PayrollDemoCommand extends Command
{
    use RendersLineHistory;

    protected $signature = 'payroll:demo';

    protected $description = 'Replay the assignment scenario against the real stack and print both expected tables';

    public function handle(CommandBus $bus, GetLineHistoryHandler $history): int
    {
        $id = EarningLineId::generate();
        $rows = [];

        foreach ($this->steps($id) as $index => [$event, $command, $note]) {
            $step = $index + 1;
            $bus->dispatch($command);

            $current = $history(new GetLineHistory($id))->currentValue;
            $rows[] = [
                $step,
                $event,
                $command instanceof AdjustLine ? $command->amount->formatSigned() : '—',
                $command instanceof AdjustLine ? "\"{$command->comment->text}\"" : $note,
                $current->format(),
            ];
        }

        $this->table(['Step', 'Event', 'Amount', 'Comment', 'Current value after this step'], $rows);
        $this->newLine();
        $this->line('Expected final audit history for this line:');
        $this->renderAuditTable($history(new GetLineHistory($id)));
        $this->newLine();
        $this->line("Stored as earning line {$id->toString()}. Inspect it again with: php artisan payroll:history {$id->toString()}");

        return self::SUCCESS;
    }

    /** @return list<array{string, object, string}> [event, command, comment-column note] in step order */
    private function steps(EarningLineId $id): array
    {
        $usd = static fn (string $amount): Money => Money::fromDecimal($amount, Currency::USD);
        $adjust = static fn (string $amount, string $comment): AdjustLine => new AdjustLine($id, $usd($amount), new Comment($comment));

        return [
            ['System calculates the line', new CalculateLine($id, $usd('1,000.00')), '—'],
            ['Source data changes, system recalculates (no manual correction yet, so this is allowed)', new RecalculateLine($id, $usd('1,050.00')), '—'],
            ['Specialist adds a manual correction', $adjust('-45.55', 'Employee declined dental benefit; reversing deduction'), ''],
            ['Source data changes again, system attempts to recalculate', new RecalculateLine($id, $usd('1,200.00')), '(must be ignored — line already has a manual correction)'],
            ['Specialist adds a second correction', $adjust('+100.10', 'Late correction: missed approved overtime bonus'), ''],
            ['Specialist adds a third correction', $adjust('-0.10', 'Minor rounding adjustment'), ''],
            ['Specialist adds a fourth correction', $adjust('-0.20', 'Second minor rounding adjustment'), ''],
            ['Specialist adds a compensating correction, realizing step 7 was a mistake', $adjust('+0.20', 'Correcting mistake in adjustment #4'), ''],
        ];
    }
}
