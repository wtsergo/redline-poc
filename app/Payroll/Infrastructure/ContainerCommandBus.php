<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\CommandBus;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/** Resolves the handler for a command from the service container using an explicit command → handler map. */
final readonly class ContainerCommandBus implements CommandBus
{
    /** @param  array<class-string, class-string>  $handlers  command class => invokable handler class */
    public function __construct(
        private Container $container,
        private array $handlers,
    ) {}

    public function dispatch(object $command): void
    {
        $handlerClass = $this->handlers[$command::class]
            ?? throw new InvalidArgumentException(sprintf('No handler is registered for %s.', $command::class));

        $handler = $this->container->make($handlerClass);

        if (! is_callable($handler)) {
            throw new LogicException(sprintf('%s must be invokable to handle %s.', $handlerClass, $command::class));
        }

        $handler($command);
    }
}
