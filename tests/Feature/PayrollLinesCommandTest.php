<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlaysAssignmentScenario;
use Tests\TestCase;

final class PayrollLinesCommandTest extends TestCase
{
    use PlaysAssignmentScenario;
    use RefreshDatabase;

    #[Test]
    public function it_explains_what_to_do_when_there_are_no_lines(): void
    {
        $this->artisan('payroll:lines')
            ->expectsOutput('No earning lines yet. Run payroll:demo or payroll:calculate to create one.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_lists_every_line_from_the_read_model(): void
    {
        $this->travelTo('2026-09-12 09:00:00');
        $frozen = EarningLineId::fromString('00000000-0000-4000-8000-000000000001');
        $this->playAssignmentScenario($frozen);
        $this->travelTo('2026-09-12 09:05:00');
        $this->artisan('payroll:calculate', ['amount' => '250'])->assertSuccessful();

        $this->artisan('payroll:lines')
            ->expectsTable(['Line', 'System value', 'Adjustments', 'Frozen at step', 'Current value', 'Last event'], [
                [$frozen->toString(), '$1,050.00', 5, 3, '$1,104.45', '2026-09-12 09:00:00'],
                [$this->latestLineId(), '$250.00', 0, '—', '$250.00', '2026-09-12 09:05:00'],
            ])
            ->assertSuccessful();
    }

    private function latestLineId(): string
    {
        return EarningLineSummary::query()->orderByDesc('calculated_at')->firstOrFail()->id;
    }
}
