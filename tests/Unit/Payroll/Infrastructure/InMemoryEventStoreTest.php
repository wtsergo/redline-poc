<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Infrastructure;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Event\LineCalculated;
use App\Payroll\Domain\Event\LineRecalculated;
use App\Payroll\Domain\Exception\ConcurrencyConflict;
use App\Payroll\Domain\Money;
use App\Payroll\Infrastructure\Persistence\InMemoryEventStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryEventStoreTest extends TestCase
{
    #[Test]
    public function it_returns_an_empty_stream_for_an_unknown_line(): void
    {
        self::assertSame([], (new InMemoryEventStore)->load(EarningLineId::generate()));
    }

    #[Test]
    public function it_appends_in_order_and_keeps_lines_apart(): void
    {
        $store = new InMemoryEventStore;
        $a = EarningLineId::generate();
        $b = EarningLineId::generate();
        $calculatedA = new LineCalculated($a, $this->usd(1), $this->now());
        $recalculatedA = new LineRecalculated($a, $this->usd(2), $this->now());
        $calculatedB = new LineCalculated($b, $this->usd(3), $this->now());

        $store->append($a, 0, [$calculatedA]);
        $store->append($b, 0, [$calculatedB]);
        $store->append($a, 1, [$recalculatedA]);

        self::assertSame([$calculatedA, $recalculatedA], $store->load($a));
        self::assertSame([$calculatedB], $store->load($b));
    }

    #[Test]
    public function it_rejects_an_append_from_a_stale_version_and_stores_nothing(): void
    {
        $store = new InMemoryEventStore;
        $id = EarningLineId::generate();
        $store->append($id, 0, [new LineCalculated($id, $this->usd(1), $this->now())]);

        try {
            $store->append($id, 0, [new LineRecalculated($id, $this->usd(2), $this->now())]);
            self::fail('Appending from a stale version must conflict.');
        } catch (ConcurrencyConflict $e) {
            self::assertStringContainsString($id->toString(), $e->getMessage());
        }

        self::assertCount(1, $store->load($id));
    }

    private function usd(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, Currency::USD);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 09:00:00');
    }
}
