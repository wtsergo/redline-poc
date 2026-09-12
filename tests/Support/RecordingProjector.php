<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Payroll\Application\Projector;
use App\Payroll\Domain\Event\DomainEvent;

/** Remembers every event it was asked to project, in order. */
final class RecordingProjector implements Projector
{
    /** @var list<DomainEvent> */
    public array $projected = [];

    /** @var list<int> */
    public array $versions = [];

    public function project(DomainEvent $event, int $version): void
    {
        $this->projected[] = $event;
        $this->versions[] = $version;
    }
}
