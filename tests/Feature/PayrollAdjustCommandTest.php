<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Infrastructure\Projection\EarningLineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PayrollAdjustCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('payroll:calculate', ['amount' => '1000'])->assertSuccessful();
        $this->id = EarningLineSummary::query()->sole()->id;
    }

    #[Test]
    public function it_records_an_adjustment_and_reports_the_new_value(): void
    {
        $this->artisan('payroll:adjust', ['line' => $this->id, 'amount' => '-45.55', 'comment' => 'Employee declined dental benefit; reversing deduction'])
            ->expectsOutput("Adjustment 1 recorded on line {$this->id}: −$45.55. Current value: $954.45.")
            ->assertSuccessful();

        $this->artisan('payroll:adjust', ['line' => $this->id, 'amount' => '+100.10', 'comment' => 'Late correction: missed approved overtime bonus'])
            ->expectsOutput("Adjustment 2 recorded on line {$this->id}: +$100.10. Current value: $1,054.55.")
            ->assertSuccessful();

        self::assertSame(2, EarningLineSummary::query()->sole()->frozen_at_version);
    }

    /** @param  array<string, string>  $arguments */
    #[Test]
    #[DataProvider('rejectedAdjustments')]
    public function it_refuses_with_the_domain_message_and_stores_nothing(array $arguments, string $message): void
    {
        $this->artisan('payroll:adjust', ['line' => $this->id, ...$arguments])
            ->expectsOutput($message)
            ->assertFailed();

        self::assertSame(1, DB::table('earning_line_events')->count(), 'only the calculation is stored');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function rejectedAdjustments(): iterable
    {
        yield 'comment omitted' => [['amount' => '-45.55'], 'A comment explaining the adjustment is mandatory.'];
        yield 'comment blank' => [['amount' => '-45.55', 'comment' => '   '], 'A comment explaining the adjustment is mandatory.'];
        yield 'zero amount' => [['amount' => '0.00', 'comment' => 'nothing'], 'An adjustment must change the value: a zero amount is a data-entry error.'];
        yield 'three decimals' => [['amount' => '-0.005', 'comment' => 'too precise'], '"-0.005" is not a valid amount: expected an optionally signed decimal with at most two decimals, e.g. "-45.55".'];
    }

    #[Test]
    public function it_fails_with_the_domain_message_for_an_unknown_line(): void
    {
        $id = EarningLineId::generate()->toString();

        $this->artisan('payroll:adjust', ['line' => $id, 'amount' => '-1', 'comment' => 'nobody home'])
            ->expectsOutput("Earning line $id does not exist.")
            ->assertFailed();
    }
}
