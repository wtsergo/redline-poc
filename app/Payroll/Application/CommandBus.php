<?php

declare(strict_types=1);

namespace App\Payroll\Application;

/** Routes a command object to its single handler. */
interface CommandBus
{
    public function dispatch(object $command): void;
}
