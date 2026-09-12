<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/** Wall-clock time, read through Laravel's Date facade so tests can travel in time. */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(Date::now());
    }
}
