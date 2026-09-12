<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Payroll\Domain\EarningLineId;

/**
 * Raised when two writers try to append to the same line from the same version
 * (assumption A8). The caller reloads the line and retries.
 */
final class ConcurrencyConflict extends PayrollException
{
    public static function forLine(EarningLineId $id, int $expectedVersion): self
    {
        return new self(sprintf(
            'Earning line %s was modified concurrently (expected version %d).',
            $id->toString(),
            $expectedVersion,
        ));
    }
}
