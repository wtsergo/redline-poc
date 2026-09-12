<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use App\Payroll\Domain\Exception\CommentTooLong;
use App\Payroll\Domain\Exception\EmptyComment;

/**
 * The mandatory explanation attached to every manual adjustment (rule R6).
 *
 * Trimmed, non-blank, at most 500 characters (assumption A3 — the cap is arbitrary).
 */
final readonly class Comment
{
    public const int MAX_LENGTH = 500;

    public string $text;

    /**
     * @throws EmptyComment
     * @throws CommentTooLong
     */
    public function __construct(string $text)
    {
        $text = trim($text);

        if ($text === '') {
            throw EmptyComment::create();
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw CommentTooLong::limit(self::MAX_LENGTH);
        }

        $this->text = $text;
    }
}
