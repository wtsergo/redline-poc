<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Event\DomainEvent;

/**
 * Updates a read model from one stored event. Called for every event, in order, right after
 * it is appended; the version lets a projection detect an event it has already applied.
 */
interface Projector
{
    public function project(DomainEvent $event, int $version): void;
}
