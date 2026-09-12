<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

final class CommentTooLong extends PayrollException
{
    public static function limit(int $maxLength): self
    {
        return new self(sprintf('A comment may not exceed %d characters.', $maxLength));
    }
}
