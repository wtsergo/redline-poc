<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlaysAssignmentScenario;
use Tests\TestCase;

final class PayrollHistoryCommandTest extends TestCase
{
    use PlaysAssignmentScenario;
    use RefreshDatabase;

    #[Test]
    public function it_prints_the_audit_table_the_adjustment_details_and_the_ignored_recalculations(): void
    {
        $this->travelTo('2026-09-12 09:00:00');
        $id = EarningLineId::generate();
        $this->playAssignmentScenario($id);

        $this->artisan('payroll:history', ['line' => $id->toString()])
            ->expectsTable(['Entry', 'Value'], [
                ['System value (frozen at step 3)', '$1,050.00'],
                ['Adjustment 1', '−$45.55'],
                ['Adjustment 2', '+$100.10'],
                ['Adjustment 3', '−$0.10'],
                ['Adjustment 4', '−$0.20'],
                ['Adjustment 5', '+$0.20'],
                ['Current (new) value', '$1,104.45'],
            ])
            ->expectsTable(['#', 'Amount', 'Comment', 'Recorded at'], [
                [1, '−$45.55', 'Employee declined dental benefit; reversing deduction', '2026-09-12 09:00:00'],
                [2, '+$100.10', 'Late correction: missed approved overtime bonus', '2026-09-12 09:00:00'],
                [3, '−$0.10', 'Minor rounding adjustment', '2026-09-12 09:00:00'],
                [4, '−$0.20', 'Second minor rounding adjustment', '2026-09-12 09:00:00'],
                [5, '+$0.20', 'Correcting mistake in adjustment #4', '2026-09-12 09:00:00'],
            ])
            ->expectsOutput('Step 4: system recalculation to $1,200.00 was ignored (line frozen at step 3).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_shows_an_unadjusted_line_without_a_freeze_and_without_details(): void
    {
        $this->artisan('payroll:calculate', ['amount' => '1000'])->assertSuccessful();
        $id = $this->onlyLineId();

        $this->artisan('payroll:history', ['line' => $id])
            ->expectsTable(['Entry', 'Value'], [
                ['System value', '$1,000.00'],
                ['Current (new) value', '$1,000.00'],
            ])
            ->doesntExpectOutputToContain('Recorded at')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_with_a_clear_message_for_an_unknown_line(): void
    {
        $id = EarningLineId::generate();

        $this->artisan('payroll:history', ['line' => $id->toString()])
            ->expectsOutput("Earning line {$id->toString()} does not exist.")
            ->assertFailed();
    }

    #[Test]
    public function it_fails_with_a_clear_message_for_an_invalid_id(): void
    {
        $this->artisan('payroll:history', ['line' => 'nope'])
            ->expectsOutput('"nope" is not a valid earning line id (expected a UUID).')
            ->assertFailed();
    }

    private function onlyLineId(): string
    {
        return EarningLineSummary::query()->sole()->id;
    }
}
