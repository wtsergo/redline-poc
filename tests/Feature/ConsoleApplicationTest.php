<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke test for the console-only bootstrap (no HTTP kernel, routes or middleware).
 */
final class ConsoleApplicationTest extends TestCase
{
    #[Test]
    public function it_boots_and_lists_artisan_commands(): void
    {
        $this->artisan('list')->assertExitCode(0);
    }
}
