<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Service\Workspace\PlainText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainText::class)]
final class PlainTextTest extends TestCase
{
    #[Test]
    public function generatedHtmlBecomesLines(): void
    {
        $html = '<p>First &amp; second</p><ul><li>One</li><li>Two</li></ul><p>Last<br>line</p>';

        self::assertSame("First & second\nOne\nTwo\nLast\nline", PlainText::fromHtml($html));
    }

    #[Test]
    public function markupIsStrippedNotExecuted(): void
    {
        self::assertSame('Hi alert(1)', PlainText::fromHtml('<p>Hi <script>alert(1)</script></p>'));
    }

    #[Test]
    public function paragraphsAreSplitAtEmptyLines(): void
    {
        self::assertSame(
            "<p>One<br>\nstill one</p>\n<p>Two</p>",
            str_replace('<br />', '<br>', PlainText::toBodytextHtml("One\nstill one\n\n\nTwo")),
        );
    }

    #[Test]
    #[DataProvider('payloads')]
    public function everythingAReviewerWritesIsEscaped(string $payload): void
    {
        $html = PlainText::toBodytextHtml($payload);

        // The only tags in the output are the paragraphs and line breaks the
        // function adds itself: after removing those, no `<` is left.
        self::assertStringNotContainsString('<', preg_replace('#</?p>|<br\s*/?>#', '', $html) ?? '');
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'image' => ['<img src=x onerror=alert(1)>'];
        yield 'link' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'entity' => ['&lt;script&gt;'];
        yield 'quotes' => ['" onmouseover="alert(1)'];
        yield 'nul and control bytes' => ["a\x00<b>\x1B[31m"];
    }

    #[Test]
    public function emptyTextGivesNoParagraph(): void
    {
        self::assertSame('', PlainText::toBodytextHtml(''));
        self::assertSame('', PlainText::toBodytextHtml("  \n\n  "));
    }

    #[Test]
    public function aSingleLineBreakSurvivesTheRoundTrip(): void
    {
        foreach (["a\nb", "a\nb\nc", "a\nb\n\nc\nd", "one\n\ntwo"] as $text) {
            self::assertSame($text, PlainText::fromHtml(PlainText::toBodytextHtml($text)), json_encode($text, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function theRoundTripKeepsThePlainText(): void
    {
        $text = "Alpha <b> & beta\n\nGamma \"quoted\"";

        self::assertSame($text, PlainText::fromHtml(PlainText::toBodytextHtml($text)));
    }
}
