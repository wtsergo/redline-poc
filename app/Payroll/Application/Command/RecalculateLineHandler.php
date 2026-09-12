<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\EarningLineRepository;
use Psr\Clock\ClockInterface;

final readonly class RecalculateLineHandler
{
    public function __construct(
        private EarningLineRepository $lines,
        private ClockInterface $clock,
    ) {}

    public function __invoke(RecalculateLine $command): void
    {
        $line = $this->lines->get($command->lineId);
        $line->recalculate($command->value, $this->clock->now());
        $this->lines->save($line);
    }
}
