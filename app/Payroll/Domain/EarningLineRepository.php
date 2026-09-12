<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use App\Payroll\Domain\Exception\ConcurrencyConflict;
use App\Payroll\Domain\Exception\EarningLineNotFound;

/** Loads and persists earning lines. Deliberately has no way to delete one or to edit its history. */
interface EarningLineRepository
{
    /** @throws EarningLineNotFound */
    public function get(EarningLineId $id): EarningLine;

    /** @throws ConcurrencyConflict when the line changed since it was loaded (assumption A8) */
    public function save(EarningLine $line): void;
}
