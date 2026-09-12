<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PayrollDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prints_both_tables_exactly_as_the_assignment_expects(): void
    {
        $this->artisan('payroll:demo')
            ->expectsTable(['Step', 'Event', 'Amount', 'Comment', 'Current value after this step'], [
                [1, 'System calculates the line', '—', '—', '$1,000.00'],
                [2, 'Source data changes, system recalculates (no manual correction yet, so this is allowed)', '—', '—', '$1,050.00'],
                [3, 'Specialist adds a manual correction', '−$45.55', '"Employee declined dental benefit; reversing deduction"', '$1,004.45'],
                [4, 'Source data changes again, system attempts to recalculate', '—', '(must be ignored — line already has a manual correction)', '$1,004.45'],
                [5, 'Specialist adds a second correction', '+$100.10', '"Late correction: missed approved overtime bonus"', '$1,104.55'],
                [6, 'Specialist adds a third correction', '−$0.10', '"Minor rounding adjustment"', '$1,104.45'],
                [7, 'Specialist adds a fourth correction', '−$0.20', '"Second minor rounding adjustment"', '$1,104.25'],
                [8, 'Specialist adds a compensating correction, realizing step 7 was a mistake', '+$0.20', '"Correcting mistake in adjustment #4"', '$1,104.45'],
            ])
            ->expectsOutput('Expected final audit history for this line:')
            ->expectsTable(['Entry', 'Value'], [
                ['System value (frozen at step 3)', '$1,050.00'],
                ['Adjustment 1', '−$45.55'],
                ['Adjustment 2', '+$100.10'],
                ['Adjustment 3', '−$0.10'],
                ['Adjustment 4', '−$0.20'],
                ['Adjustment 5', '+$0.20'],
                ['Current (new) value', '$1,104.45'],
            ])
            ->expectsOutputToContain('Inspect it again with: php artisan payroll:history ')
            ->assertSuccessful();

        self::assertSame(1, EarningLineSummary::query()->count());
        self::assertSame(8, DB::table('earning_line_events')->count(), 'one event per step, the ignored recalculation included');
    }

    #[Test]
    public function every_run_stores_a_new_line(): void
    {
        $this->artisan('payroll:demo')->assertSuccessful();
        $this->artisan('payroll:demo')->assertSuccessful();

        self::assertSame(2, EarningLineSummary::query()->count());
    }
}
