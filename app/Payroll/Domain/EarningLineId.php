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
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';                       // version nibble
        $hex[16] = '89ab'[random_int(0, 3)];  // RFC 4122 variant nibble

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4)));
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
