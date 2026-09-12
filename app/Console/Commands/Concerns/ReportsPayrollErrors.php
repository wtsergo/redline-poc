<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Payroll\Domain\Exception\PayrollException;

/** Turns a domain rule violation into a one-line error and a non-zero exit code. */
trait ReportsPayrollErrors
{
    /** @param  callable(): void  $action */
    private function attempt(callable $action): int
    {
        try {
            $action();
        } catch (PayrollException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
