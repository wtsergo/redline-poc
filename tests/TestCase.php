<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends BaseTestCase
{
    /**
     * Narrows the framework's `PendingCommand|int` return type: with console output
     * mocking enabled (the default for tests) `artisan()` always returns a PendingCommand.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function artisan($command, $parameters = []): PendingCommand
    {
        $pending = parent::artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new \LogicException('Console output mocking must stay enabled for artisan() assertions.');
        }

        return $pending;
    }
}
