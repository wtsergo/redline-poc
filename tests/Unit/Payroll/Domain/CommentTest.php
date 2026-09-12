<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Comment;
use App\Payroll\Domain\Exception\CommentTooLong;
use App\Payroll\Domain\Exception\EmptyComment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    #[Test]
    public function it_keeps_the_text_verbatim_apart_from_surrounding_whitespace(): void
    {
        $comment = new Comment("  Employee declined dental benefit; reversing deduction \n");

        self::assertSame('Employee declined dental benefit; reversing deduction', $comment->text);
    }

    #[Test]
    #[DataProvider('blankComments')]
    public function it_rejects_blank_comments_because_a_comment_is_mandatory(string $text): void
    {
        $this->expectException(EmptyComment::class);
        $this->expectExceptionMessage('mandatory');

        new Comment($text);
    }

    /** @return iterable<string, array{string}> */
    public static function blankComments(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tabs and newlines' => ["\t\n"];
    }

    #[Test]
    public function it_accepts_exactly_the_maximum_length_counted_in_characters(): void
    {
        $text = str_repeat('é', Comment::MAX_LENGTH);

        self::assertSame($text, (new Comment($text))->text);
    }

    #[Test]
    public function it_rejects_comments_longer_than_the_maximum(): void
    {
        $this->expectException(CommentTooLong::class);
        $this->expectExceptionMessage('500 characters');

        new Comment(str_repeat('x', Comment::MAX_LENGTH + 1));
    }
}
