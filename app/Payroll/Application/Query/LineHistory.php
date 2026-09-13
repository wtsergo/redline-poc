<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Domain\Adjustment;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Money;
use LogicException;

/**
 * Read model for the audit view (rule R5): the system value as frozen, every adjustment in
 * order, the current value and, listed separately, every recalculation the system attempted
 * after the freeze. Earlier system values stay in the stream but are not part of the audit
 * table (assumption A7).
 */
final readonly class LineHistory
{
    /**
     * @param  list<Adjustment>  $adjustments
     * @param  list<IgnoredRecalculation>  $ignoredRecalculations
     */
    public function __construct(
        public EarningLineId $lineId,
        public Money $systemValue,
        public ?int $frozenAtVersion,
        public array $adjustments,
        public array $ignoredRecalculations,
        public Money $currentValue,
        public int $version,
    ) {}

    /** @param  list<DomainEvent>  $events  the line's stream, which always starts with LineCalculated */
    public static function fromEvents(EarningLineId $lineId, array $events): self
    {
        $systemValue = null;
        $frozenAtVersion = null;
        $adjustments = [];
        $ignored = [];
        $version = 0;

        foreach ($events as $event) {
            $version++;

            match (true) {
                $event instanceof LineCalculated, $event instanceof LineRecalculated => $systemValue = $event->value,
                $event instanceof LineAdjusted => [
                    $frozenAtVersion ??= $version,
                    $adjustments[] = new Adjustment(count($adjustments) + 1, $event->amount, $event->comment, $event->recordedAt),
                ],
                $event instanceof RecalculationIgnored => $ignored[] = new IgnoredRecalculation($version, $event->attemptedValue, $event->recordedAt),
                default => throw new LogicException(sprintf('%s cannot fold %s.', self::class, $event::class)),
            };
        }

        if ($systemValue === null) {
            throw new LogicException('An earning line history must start with a calculation.');
        }

        $currentValue = array_reduce(
            $adjustments,
            static fn (Money $total, Adjustment $adjustment): Money => $total->plus($adjustment->amount),
            $systemValue,
        );

        return new self($lineId, $systemValue, $frozenAtVersion, $adjustments, $ignored, $currentValue, $version);
    }
}
