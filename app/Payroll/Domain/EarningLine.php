<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Exception\CurrencyMismatch;
use App\Payroll\Domain\Exception\EarningLineNotFound;
use App\Payroll\Domain\Exception\ZeroAdjustment;
use DateTimeImmutable;
use LogicException;

/**
 * An earning line: a value the system calculates, which a payroll specialist may correct
 * with manual adjustments. Event-sourced aggregate root.
 *
 * Invariants (the numbered rules refer to the assignment):
 *  - R1/R2  adjustments are appended, numbered 1..n, and can never be edited or removed;
 *  - R3     a mistake is undone by a new compensating adjustment, never by touching an old one;
 *  - R4     the first adjustment freezes the system value: later recalculations are recorded
 *           as ignored and do not change anything;
 *  - R5     current value = frozen system value + sum of adjustments, always derivable.
 */
final class EarningLine
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    private int $version = 0;

    private Money $systemValue;

    private ?int $frozenAtVersion = null;

    /** @var list<Adjustment> */
    private array $adjustments = [];

    private function __construct(private readonly EarningLineId $id) {}

    /** A line comes into existence with its first system calculation. */
    public static function calculate(EarningLineId $id, Money $value, DateTimeImmutable $at): self
    {
        $line = new self($id);
        $line->record(new LineCalculated($id, $value, $at));

        return $line;
    }

    /**
     * Rebuilds the line by replaying its history. Replayed events are not pending.
     *
     * @param  iterable<DomainEvent>  $history
     *
     * @throws EarningLineNotFound when the history is empty
     */
    public static function reconstitute(EarningLineId $id, iterable $history): self
    {
        $line = new self($id);

        foreach ($history as $event) {
            $line->apply($event);
        }

        if ($line->version === 0) {
            throw EarningLineNotFound::withId($id);
        }

        return $line;
    }

    /**
     * Source data changed. Replaces the system value unless the line is frozen, in which
     * case the attempt is recorded and ignored (rule R4): this is not an error.
     *
     * @throws CurrencyMismatch
     */
    public function recalculate(Money $value, DateTimeImmutable $at): void
    {
        $this->assertSameCurrency($value);

        $this->record($this->isFrozen()
            ? new RecalculationIgnored($this->id, $value, $at)
            : new LineRecalculated($this->id, $value, $at));
    }

    /**
     * Records a manual correction. Positive or negative, never zero, always commented.
     *
     * @throws ZeroAdjustment
     * @throws CurrencyMismatch
     */
    public function adjust(Money $amount, Comment $comment, DateTimeImmutable $at): void
    {
        $this->assertSameCurrency($amount);

        if ($amount->isZero()) {
            throw ZeroAdjustment::create();
        }

        $this->record(new LineAdjusted($this->id, count($this->adjustments) + 1, $amount, $comment, $at));
    }

    public function id(): EarningLineId
    {
        return $this->id;
    }

    /** Number of events applied so far; the expected version for optimistic concurrency. */
    public function version(): int
    {
        return $this->version;
    }

    /** The system-calculated value. Frozen from the first adjustment onwards. */
    public function systemValue(): Money
    {
        return $this->systemValue;
    }

    public function currentValue(): Money
    {
        return array_reduce(
            $this->adjustments,
            static fn (Money $total, Adjustment $adjustment): Money => $total->plus($adjustment->amount),
            $this->systemValue,
        );
    }

    public function isFrozen(): bool
    {
        return $this->frozenAtVersion !== null;
    }

    /** Version (= step) of the first adjustment, or null while the line is still recalculable. */
    public function frozenAtVersion(): ?int
    {
        return $this->frozenAtVersion;
    }

    /** @return list<Adjustment> in the order they were recorded */
    public function adjustments(): array
    {
        return $this->adjustments;
    }

    /**
     * Hands over the events recorded since the line was loaded, exactly once.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    private function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->pendingEvents[] = $event;
    }

    private function apply(DomainEvent $event): void
    {
        $this->version++;

        match (true) {
            $event instanceof LineCalculated => $this->systemValue = $event->value,
            $event instanceof LineRecalculated => $this->systemValue = $event->value,
            $event instanceof LineAdjusted => $this->applyAdjusted($event),
            $event instanceof RecalculationIgnored => null, // nothing changes; the record itself is the point
            default => throw new LogicException(sprintf('%s cannot apply %s.', self::class, $event::class)),
        };
    }

    private function applyAdjusted(LineAdjusted $event): void
    {
        $this->frozenAtVersion ??= $this->version;
        $this->adjustments[] = new Adjustment($event->number, $event->amount, $event->comment, $event->recordedAt);
    }

    /** @throws CurrencyMismatch */
    private function assertSameCurrency(Money $amount): void
    {
        if (! $amount->isSameCurrencyAs($this->systemValue)) {
            throw CurrencyMismatch::between($this->systemValue->currency, $amount->currency);
        }
    }
}
