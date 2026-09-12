<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command;

use App\Payroll\Domain\EarningLineRepository;
use Psr\Clock\ClockInterface;

final readonly class AdjustLineHandler
{
    public function __construct(
        private EarningLineRepository $lines,
        private ClockInterface $clock,
    ) {}

    public function __invoke(AdjustLine $command): void
    {
        $line = $this->lines->get($command->lineId);
        $line->adjust($command->amount, $command->comment, $this->clock->now());
        $this->lines->save($line);
    }
}
