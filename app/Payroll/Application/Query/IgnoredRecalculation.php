<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Domain\Money;
use DateTimeImmutable;

/** A recalculation the system attempted after the line was frozen; kept for the audit trail (assumption A1). */
final readonly class IgnoredRecalculation
{
    public function __construct(
        public int $version,
        public Money $attemptedValue,
        public DateTimeImmutable $recordedAt,
    ) {}
}
