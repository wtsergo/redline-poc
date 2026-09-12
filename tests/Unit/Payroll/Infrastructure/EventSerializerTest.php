<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Infrastructure;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Domain\Event\LineAdjusted;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Event\RecalculationIgnored;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Persistence\EventSerializer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class EventSerializerTest extends TestCase
{
    private const string LINE_ID = '0f5a2c3e-7b1d-4a9e-8c2f-1234567890ab';

    #[Test]
    #[DataProvider('events')]
    public function it_round_trips_every_event_type(DomainEvent $event, string $expectedType): void
    {
        $serializer = new EventSerializer;

        $row = $serializer->toRow($event);

        self::assertSame(self::LINE_ID, $row['aggregate_id']);
        self::assertSame($expectedType, $row['event_type']);
        self::assertSame('2026-09-12 09:00:00.123456', $row['recorded_at']);
        self::assertEquals($event, $serializer->fromRow($row));
    }

    /** @return iterable<string, array{DomainEvent, string}> */
    public static function events(): iterable
    {
        $id = EarningLineId::fromString(self::LINE_ID);
        $at = new DateTimeImmutable('2026-09-12 09:00:00.123456', new DateTimeZone('UTC'));
        $usd = static fn (string $amount): Money => Money::fromDecimal($amount, Currency::USD);

        yield 'calculated' => [new LineCalculated($id, $usd('1,000.00'), $at), 'line_calculated'];
        yield 'recalculated' => [new LineRecalculated($id, $usd('1,050.00'), $at), 'line_recalculated'];
        yield 'recalculation ignored' => [new RecalculationIgnored($id, $usd('1,200.00'), $at), 'recalculation_ignored'];
        yield 'adjusted' => [
            new LineAdjusted($id, 1, $usd('-45.55'), new Comment('Employee declined dental benefit; reversing deduction'), $at),
            'line_adjusted',
        ];
    }

    #[Test]
    public function it_stores_money_as_minor_units_and_a_currency_code_never_as_a_decimal(): void
    {
        $event = new LineAdjusted($this->id(), 2, Money::fromDecimal('-45.55', Currency::EUR), new Comment('dental'), $this->at());

        $row = (new EventSerializer)->toRow($event);

        self::assertSame('{"amount_minor":-4555,"currency":"EUR","number":2,"comment":"dental"}', $row['payload']);
    }

    #[Test]
    public function it_normalises_timestamps_to_utc(): void
    {
        $serializer = new EventSerializer;
        $local = new DateTimeImmutable('2026-09-12 11:00:00', new DateTimeZone('Europe/Kyiv'));
        $event = new LineCalculated($this->id(), Money::zero(Currency::USD), $local);

        $row = $serializer->toRow($event);

        self::assertSame('2026-09-12 08:00:00.000000', $row['recorded_at']);
        self::assertEquals($local, $serializer->fromRow($row)->recordedAt, 'same instant, whatever the zone');
    }

    #[Test]
    public function it_refuses_to_store_events_it_does_not_know(): void
    {
        $unknown = new readonly class($this->id(), $this->at()) implements DomainEvent
        {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $recordedAt) {}
        };

        $this->expectException(InvalidArgumentException::class);

        (new EventSerializer)->toRow($unknown);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string<\Throwable>  $exception
     */
    #[Test]
    #[DataProvider('corruptRows')]
    public function it_fails_loudly_on_a_corrupt_row(array $row, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        (new EventSerializer)->fromRow($row);
    }

    /** @return iterable<string, array{array<string, mixed>, class-string<\Throwable>, string}> */
    public static function corruptRows(): iterable
    {
        /**
         * @param  array<string, mixed>  $overrides
         * @return array<string, mixed>
         */
        $row = static fn (array $overrides): array => array_merge([
            'aggregate_id' => self::LINE_ID,
            'event_type' => 'line_adjusted',
            'payload' => '{"amount_minor":-4555,"currency":"USD","number":1,"comment":"dental"}',
            'recorded_at' => '2026-09-12 09:00:00.000000',
        ], $overrides);

        yield 'unknown type' => [$row(['event_type' => 'line_deleted']), UnexpectedValueException::class, 'Unknown stored event type "line_deleted"'];
        yield 'type is not a string' => [$row(['event_type' => 7]), UnexpectedValueException::class, '"event_type" must be a string'];
        yield 'missing timestamp' => [$row(['recorded_at' => null]), UnexpectedValueException::class, '"recorded_at" must be a string'];
        yield 'payload is not json' => [$row(['payload' => '{oops']), JsonException::class, 'Syntax error'];
        yield 'payload is not an object' => [$row(['payload' => '42']), UnexpectedValueException::class, 'must decode to an object'];
        yield 'amount is a decimal string' => [$row(['payload' => '{"amount_minor":"-45.55","currency":"USD","number":1,"comment":"x"}']), UnexpectedValueException::class, '"amount_minor" must be an integer'];
        yield 'comment missing' => [$row(['payload' => '{"amount_minor":-4555,"currency":"USD","number":1}']), UnexpectedValueException::class, '"comment" must be a string'];
    }

    private function id(): EarningLineId
    {
        return EarningLineId::fromString(self::LINE_ID);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 09:00:00', new DateTimeZone('UTC'));
    }
}
