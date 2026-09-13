<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Money;
use DateTimeImmutable;

/**
 * A payroll specialist recorded a manual adjustment. The first one freezes the system value.
 *
 * Carries no ordinal: an adjustment's number is its position in the stream, derived by
 * whoever folds the events (the aggregate, the projector, a read model), never a value
 * decided ahead of that fold and stored here.
 */
final readonly class LineAdjusted implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $amount,
        public Comment $comment,
        public DateTimeImmutable $recordedAt,
    ) {}
}
