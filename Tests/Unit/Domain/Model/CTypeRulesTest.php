<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Domain\Model;

use Netresearch\NrLandingpage\Domain\Model\CTypeRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(CTypeRules::class)]
final class CTypeRulesTest extends UnitTestCase
{
    #[Test]
    public function readsOneRulePerLineAndGroupsLinesOfOneType(): void
    {
        $rules = CTypeRules::parse("header: at most 8 words\ntext: 80 to 120 words\r\n  Text :  no questions  \nheader: no full stop");

        self::assertSame(['header' => ['at most 8 words', 'no full stop'], 'text' => ['80 to 120 words', 'no questions']], $rules->rules);
        self::assertSame([], $rules->ignored);
        self::assertSame("- header: at most 8 words; no full stop\n- text: 80 to 120 words; no questions", $rules->toPromptLines());
    }

    #[Test]
    public function skipsBlankLinesAndCommentsWithoutReportingThem(): void
    {
        $rules = CTypeRules::parse("\n# the hero\n   \nheader: short\n#header: ignored comment");

        self::assertSame(['header' => ['short']], $rules->rules);
        self::assertSame([], $rules->ignored);
    }

    #[Test]
    public function reportsLinesWithoutATypeOrARule(): void
    {
        $rules = CTypeRules::parse("just some words\nheader:\n: no type\n9lives: digit first\ntext: fine");

        self::assertSame(['text' => ['fine']], $rules->rules);
        self::assertSame(['just some words', 'header:', ': no type', '9lives: digit first'], $rules->ignored);
    }

    #[Test]
    public function reportsATypeTheTemplateDoesNotAllowButAcceptsAnyWhenNoneIsSet(): void
    {
        $text = "header: short\ntextmedia: caption";

        $restricted = CTypeRules::parse($text, ['header', 'text']);
        $open = CTypeRules::parse($text);

        self::assertSame(['header' => ['short']], $restricted->rules);
        self::assertSame(['textmedia: caption'], $restricted->ignored);
        self::assertSame(['header' => ['short'], 'textmedia' => ['caption']], $open->rules);
    }

    #[Test]
    public function keepsAColonInsideTheRule(): void
    {
        self::assertSame(['text' => ['Tone: friendly, never: sarcastic']], CTypeRules::parse('text: Tone: friendly, never: sarcastic')->rules);
    }

    #[Test]
    public function cutsALongRuleAndStopsAfterTheMaximumNumberOfRules(): void
    {
        $lines = ['header: ' . str_repeat('x', CTypeRules::MAX_RULE_LENGTH + 50)];
        for ($i = 1; $i < CTypeRules::MAX_RULES + 3; ++$i) {
            $lines[] = 'text: rule ' . $i;
        }

        $rules = CTypeRules::parse(implode("\n", $lines));

        self::assertSame(CTypeRules::MAX_RULE_LENGTH, mb_strlen($rules->rules['header'][0]));
        self::assertCount(CTypeRules::MAX_RULES - 1, $rules->rules['text']);
        self::assertSame(['text: rule ' . CTypeRules::MAX_RULES, 'text: rule ' . (CTypeRules::MAX_RULES + 1), 'text: rule ' . (CTypeRules::MAX_RULES + 2)], $rules->ignored);
    }

    #[Test]
    public function isEmptyWithoutUsableRules(): void
    {
        self::assertTrue(CTypeRules::parse('')->isEmpty());
        self::assertTrue(CTypeRules::parse("# only a comment\nnonsense")->isEmpty());
        self::assertFalse(CTypeRules::parse('text: x')->isEmpty());
    }
}
