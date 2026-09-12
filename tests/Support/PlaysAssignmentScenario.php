<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Payroll\Application\Command\AdjustLine;
use App\Payroll\Application\Command\CalculateLine;
use App\Payroll\Application\Command\RecalculateLine;
use App\Payroll\Application\CommandBus;
use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;

/** Runs the eight assignment steps through the container-resolved command bus, for feature tests. */
trait PlaysAssignmentScenario
{
    private function playAssignmentScenario(EarningLineId $id): void
    {
        $bus = $this->app->make(CommandBus::class);
        $usd = static fn (string $amount): Money => Money::fromDecimal($amount, Currency::USD);

        $bus->dispatch(new CalculateLine($id, $usd('1,000.00')));
        $bus->dispatch(new RecalculateLine($id, $usd('1,050.00')));
        $bus->dispatch(new AdjustLine($id, $usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction')));
        $bus->dispatch(new RecalculateLine($id, $usd('1,200.00')));
        $bus->dispatch(new AdjustLine($id, $usd('+100.10'), new Comment('Late correction: missed approved overtime bonus')));
        $bus->dispatch(new AdjustLine($id, $usd('-0.10'), new Comment('Minor rounding adjustment')));
        $bus->dispatch(new AdjustLine($id, $usd('-0.20'), new Comment('Second minor rounding adjustment')));
        $bus->dispatch(new AdjustLine($id, $usd('+0.20'), new Comment('Correcting mistake in adjustment #4')));
    }
}
