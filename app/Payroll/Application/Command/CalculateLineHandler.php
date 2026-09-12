<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineRepository;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use Psr\Clock\ClockInterface;

final readonly class CalculateLineHandler
{
    public function __construct(
        private EarningLineRepository $lines,
        private ClockInterface $clock,
    ) {}

    /** @throws ConcurrencyConflict when a line with this id already exists (its stream is past version 0) */
    public function __invoke(CalculateLine $command): void
    {
        $this->lines->save(EarningLine::calculate($command->lineId, $command->value, $this->clock->now()));
    }
}
