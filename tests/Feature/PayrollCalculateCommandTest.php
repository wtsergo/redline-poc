<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PayrollCalculateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_calculates_a_new_line_and_prints_its_id(): void
    {
        $this->artisan('payroll:calculate', ['amount' => '1,000.00'])
            ->expectsOutputToContain('Calculated new earning line ')
            ->assertSuccessful();

        $line = EarningLineSummary::query()->sole();
        self::assertSame('$1,000.00', $line->currentValue()->format());
        self::assertFalse($line->isFrozen());
    }

    #[Test]
    public function it_recalculates_an_existing_line_that_has_no_adjustment(): void
    {
        $this->artisan('payroll:calculate', ['amount' => '1000'])->assertSuccessful();
        $id = EarningLineSummary::query()->sole()->id;

        $this->artisan('payroll:calculate', ['amount' => '1050', '--line' => $id])
            ->expectsOutput("Recalculated earning line $id: $1,050.00")
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_that_a_frozen_line_ignored_the_recalculation(): void
    {
        $this->artisan('payroll:calculate', ['amount' => '1000'])->assertSuccessful();
        $id = EarningLineSummary::query()->sole()->id;
        $this->artisan('payroll:adjust', ['line' => $id, 'amount' => '-45.55', 'comment' => 'dental'])->assertSuccessful();

        $this->artisan('payroll:calculate', ['amount' => '1,200.00', '--line' => $id])
            ->expectsOutput("Recalculation to $1,200.00 ignored: line $id has manual adjustments (frozen at step 2). Current value stays $954.45.")
            ->doesntExpectOutputToContain('Recalculated earning line')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_with_the_domain_message_for_an_invalid_amount(): void
    {
        $this->artisan('payroll:calculate', ['amount' => '12.345'])
            ->expectsOutput('"12.345" is not a valid amount: expected an optionally signed decimal with at most two decimals, e.g. "-45.55".')
            ->assertFailed();

        self::assertSame(0, EarningLineSummary::query()->count());
    }

    #[Test]
    public function it_fails_with_the_domain_message_for_an_unknown_line(): void
    {
        $id = EarningLineId::generate()->toString();

        $this->artisan('payroll:calculate', ['amount' => '1000', '--line' => $id])
            ->expectsOutput("Earning line $id does not exist.")
            ->assertFailed();
    }
}
