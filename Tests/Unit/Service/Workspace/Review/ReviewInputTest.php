<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review;

use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewInput::class)]
final class ReviewInputTest extends TestCase
{
    #[Test]
    #[DataProvider('names')]
    public function aNameIsCleanedAndBounded(string $raw, string $expected): void
    {
        self::assertSame($expected, ReviewInput::name($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['Jane Doe', 'Jane Doe'];
        yield 'trimmed and single spaced' => ["  Jane \t  Doe  ", 'Jane Doe'];
        yield 'control characters' => ["Ja\x00ne\x1B[31m", 'Ja ne [31m'];
        yield 'line breaks' => ["Jane\r\nDoe", 'Jane Doe'];
        yield 'empty' => ['', ReviewInput::DEFAULT_NAME];
        yield 'only spaces' => ["  \n ", ReviewInput::DEFAULT_NAME];
        yield 'markup stays text' => ['<b>Jane</b>', '<b>Jane</b>'];
        yield 'bidi override' => ["Jane\u{202E}eoD", 'Jane eoD'];
        yield 'too long' => [str_repeat('x', 200), str_repeat('x', ReviewInput::NAME_MAX)];
        yield 'multibyte kept whole' => [str_repeat('ä', 100), str_repeat('ä', ReviewInput::NAME_MAX)];
    }

    #[Test]
    #[DataProvider('texts')]
    public function aTextKeepsItsLinesAndLosesControlCharacters(string $raw, string $expected): void
    {
        self::assertSame($expected, ReviewInput::text($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'line endings normalised' => ["a\r\nb\rc\nd", "a\nb\nc\nd"];
        yield 'outer white space trimmed' => ["\n\n  a  \n", 'a'];
        yield 'nul and escape removed' => ["a\x00b\x1Bc", 'abc'];
        yield 'tab kept' => ["a\tb", "a\tb"];
        yield 'markup untouched' => ['<script>alert(1)</script>', '<script>alert(1)</script>'];
        yield 'line separator removed' => ["a\u{2028}b", 'ab'];
        yield 'bidi controls removed' => ["a\u{202E}b\u{2066}c", 'abc'];
        yield 'empty' => ['', ''];
    }
}
