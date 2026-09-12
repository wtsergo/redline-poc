<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use App\Payroll\Domain\Exception\InvalidEarningLineId;

/** Identity of an earning line: a lower-case UUID. */
final readonly class EarningLineId
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(public string $value) {}

    /** A random (version 4) UUID, generated without any framework helper. */
    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    /** @throws InvalidEarningLineId */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw InvalidEarningLineId::notUuid($value);
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
