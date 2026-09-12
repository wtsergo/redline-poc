<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Persistence;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Maps domain events to and from their stored row: a short type name plus a JSON payload.
 * Money is stored as integer minor units and a currency code, never as a decimal.
 */
final readonly class EventSerializer
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    /** @return array{aggregate_id: string, event_type: string, payload: string, recorded_at: string} */
    public function toRow(DomainEvent $event): array
    {
        [$type, $payload] = match (true) {
            $event instanceof LineCalculated => ['line_calculated', self::money($event->value)],
            $event instanceof LineRecalculated => ['line_recalculated', self::money($event->value)],
            $event instanceof RecalculationIgnored => ['recalculation_ignored', self::money($event->attemptedValue)],
            $event instanceof LineAdjusted => ['line_adjusted', [
                ...self::money($event->amount),
                'number' => $event->number,
                'comment' => $event->comment->text,
            ]],
            default => throw new InvalidArgumentException(sprintf('Cannot store %s.', $event::class)),
        };

        return [
            'aggregate_id' => $event->lineId->toString(),
            'event_type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'recorded_at' => $event->recordedAt->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @param  array<mixed>  $row  one earning_line_events row as an associative array
     *
     * @throws UnexpectedValueException when the row does not hold what its type promises
     */
    public function fromRow(array $row): DomainEvent
    {
        $lineId = EarningLineId::fromString(self::string($row, 'aggregate_id'));
        $recordedAt = new DateTimeImmutable(self::string($row, 'recorded_at'), new DateTimeZone('UTC'));
        $payload = json_decode(self::string($row, 'payload'), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new UnexpectedValueException('The event payload must decode to an object.');
        }

        $type = self::string($row, 'event_type');

        return match ($type) {
            'line_calculated' => new LineCalculated($lineId, self::moneyFrom($payload), $recordedAt),
            'line_recalculated' => new LineRecalculated($lineId, self::moneyFrom($payload), $recordedAt),
            'recalculation_ignored' => new RecalculationIgnored($lineId, self::moneyFrom($payload), $recordedAt),
            'line_adjusted' => new LineAdjusted(
                $lineId,
                self::int($payload, 'number'),
                self::moneyFrom($payload),
                new Comment(self::string($payload, 'comment')),
                $recordedAt,
            ),
            default => throw new UnexpectedValueException(sprintf('Unknown stored event type "%s".', $type)),
        };
    }

    /** @return array{amount_minor: int, currency: string} */
    private static function money(Money $money): array
    {
        return ['amount_minor' => $money->minorUnits, 'currency' => $money->currency->value];
    }

    /** @param  array<mixed>  $payload */
    private static function moneyFrom(array $payload): Money
    {
        return Money::fromMinorUnits(self::int($payload, 'amount_minor'), Currency::from(self::string($payload, 'currency')));
    }

    /** @param  array<mixed>  $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new UnexpectedValueException(sprintf('"%s" must be an integer in the stored event.', $key));
        }

        return $value;
    }

    /** @param  array<mixed>  $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new UnexpectedValueException(sprintf('"%s" must be a string in the stored event.', $key));
        }

        return $value;
    }
}
