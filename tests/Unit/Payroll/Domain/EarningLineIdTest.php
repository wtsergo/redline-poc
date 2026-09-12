<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\EarningLineId;
use App\Payroll\Domain\Exception\InvalidEarningLineId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarningLineIdTest extends TestCase
{
    #[Test]
    public function it_generates_random_version_4_uuids(): void
    {
        $id = EarningLineId::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );
        self::assertFalse($id->equals(EarningLineId::generate()));
    }

    #[Test]
    public function it_accepts_uuid_strings_case_insensitively(): void
    {
        $id = EarningLineId::fromString(' 0F5A2C3E-7B1D-4A9E-8C2F-1234567890AB ');

        self::assertSame('0f5a2c3e-7b1d-4a9e-8c2f-1234567890ab', $id->toString());
        self::assertTrue($id->equals(EarningLineId::fromString('0f5a2c3e-7b1d-4a9e-8c2f-1234567890ab')));
        self::assertFalse($id->equals(EarningLineId::fromString('0f5a2c3e-7b1d-4a9e-8c2f-1234567890ac')));
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function it_rejects_anything_that_is_not_a_uuid(string $value): void
    {
        $this->expectException(InvalidEarningLineId::class);

        EarningLineId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['not-a-uuid'];
        yield 'missing dashes' => ['0f5a2c3e7b1d4a9e8c2f1234567890ab'];
        yield 'one character short' => ['0f5a2c3e-7b1d-4a9e-8c2f-1234567890a'];
        yield 'non-hex character' => ['0f5a2c3e-7b1d-4a9e-8c2f-1234567890ag'];
    }
}
