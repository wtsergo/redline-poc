<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\CommandBus;
use Illuminate\Database\ConnectionInterface;

/**
 * Runs every command inside one database transaction, so the appended events and the
 * projection they feed commit or roll back together.
 */
final readonly class TransactionalCommandBus implements CommandBus
{
    public function __construct(
        private CommandBus $inner,
        private ConnectionInterface $connection,
    ) {}

    public function dispatch(object $command): void
    {
        $this->connection->transaction(fn () => $this->inner->dispatch($command));
    }
}
