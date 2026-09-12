<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use DateTimeImmutable;

/**
 * One manual correction on an earning line, numbered in the order it was recorded.
 *
 * Immutable by construction: there is no API anywhere to edit or remove one (rule R2).
 * A mistake is undone by recording a new, compensating adjustment (rule R3).
 */
final readonly class Adjustment
{
    public function __construct(
        public int $number,
        public Money $amount,
        public Comment $comment,
        public DateTimeImmutable $recordedAt,
    ) {}
}
