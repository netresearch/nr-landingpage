<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\SeoFinding;
use Netresearch\NrLandingpage\Service\SeoCheckService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(SeoCheckService::class)]
#[CoversClass(SeoFinding::class)]
final class SeoCheckServiceTest extends UnitTestCase
{
    private const TITLE = 'Rocket 2: the faster way to ship';

    private SeoCheckService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SeoCheckService();
    }

    private static function description(int $length): string
    {
        return str_repeat('d', $length);
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<int, string> $alternatives
     * @return list<array{rule: string, severity: string, params: list<int|string>, section: int|null}>
     */
    private function findingsOf(string $title, string $seoTitle, string $description, array $sections = [], array $alternatives = []): array
    {
        return array_map(
            static fn(SeoFinding $finding): array => $finding->toArray(),
            $this->subject->check($title, $seoTitle, $description, $sections, $alternatives),
        );
    }

    /** @param list<array<string, mixed>> $sections */
    private function rules(array $sections, string $title = self::TITLE, array $alternatives = []): array
    {
        return array_map(
            static fn(array $f): string => $f['rule'] . ($f['section'] !== null ? '@' . $f['section'] : ''),
            $this->findingsOf($title, '', self::description(140), $sections, $alternatives),
        );
    }

    #[Test]
    public function aPageWithGoodTitleDescriptionAndHeadingsHasNoFindings(): void
    {
        $sections = [
            ['header' => 'Why Rocket 2', 'bodytext' => '<p>Text</p><h3>Speed</h3><p>More</p>'],
            ['header' => 'Pricing', 'bodytext' => '<h3>Plans</h3><h4>Team</h4>'],
        ];

        self::assertSame([], $this->findingsOf(self::TITLE, '', self::description(140), $sections));
    }

    #[Test]
    public function titleMissingIsAnError(): void
    {
        $findings = $this->findingsOf('', '  ', self::description(140));

        self::assertSame([['rule' => 'title-missing', 'severity' => 'error', 'params' => [], 'section' => null]], $findings);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function titles(): iterable
    {
        yield 'seo title wins, too short' => ['A long enough page title for the editor to see', 'Short', ['title-short']];
        yield 'page title used without seo title, too long' => [str_repeat('t', 61), '', ['title-long']];
        yield 'seo title at the minimum' => ['x', str_repeat('s', 30), []];
        yield 'seo title at the maximum' => ['x', str_repeat('s', 60), []];
        yield 'one below the minimum' => ['x', str_repeat('s', 29), ['title-short']];
        yield 'one above the maximum' => ['x', str_repeat('s', 61), ['title-long']];
        yield 'multibyte counts characters' => ['x', str_repeat('ü', 30), []];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('titles')]
    public function checksTheLengthOfTheTitleShown(string $title, string $seoTitle, array $expected): void
    {
        $rules = array_column($this->findingsOf($title, $seoTitle, self::description(140)), 'rule');

        self::assertSame($expected, $rules);
    }

    #[Test]
    public function titleFindingNamesLengthAndLimit(): void
    {
        $findings = $this->findingsOf('x', str_repeat('s', 61), self::description(140));

        self::assertSame([61, 60], $findings[0]['params']);
    }

    /** @return iterable<string, array{int, list<string>}> */
    public static function descriptions(): iterable
    {
        yield 'missing' => [0, ['description-missing']];
        yield 'one below the minimum' => [119, ['description-short']];
        yield 'minimum' => [120, []];
        yield 'maximum' => [160, []];
        yield 'one above the maximum' => [161, ['description-long']];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('descriptions')]
    public function checksTheLengthOfTheMetaDescription(int $length, array $expected): void
    {
        self::assertSame($expected, array_column($this->findingsOf(self::TITLE, '', self::description($length)), 'rule'));
    }

    #[Test]
    public function aHeadingMayBeOneLevelDeeperOnlyAndTheTitleCountsAsH1(): void
    {
        $sections = [
            ['header' => 'Intro', 'bodytext' => '<h4>Skips h3</h4>'],
            ['header' => 'Next', 'bodytext' => '<h3>Fine</h3>'],
        ];

        $findings = $this->findingsOf(self::TITLE, '', self::description(140), $sections);

        self::assertCount(1, $findings);
        self::assertSame('heading-skip', $findings[0]['rule']);
        self::assertSame([2, 4, 'Skips h3'], $findings[0]['params']);
        self::assertSame(0, $findings[0]['section']);
    }

    #[Test]
    public function aSectionWithoutHeaderStartingWithH3AfterTheTitleSkipsH2(): void
    {
        self::assertSame(['heading-skip@0'], $this->rules([['header' => '', 'bodytext' => '<h3>Early</h3>']]));
    }

    #[Test]
    public function levelCarriesOverFromOneSectionToTheNextAndGoingUpIsFine(): void
    {
        $sections = [
            ['header' => 'A', 'bodytext' => '<h3>x</h3><h4>y</h4>'],
            ['header' => '', 'bodytext' => '<h4>no jump: still under the h4 of the section before</h4>'],
            ['header' => '', 'bodytext' => '<h2>up again</h2>'],
            ['header' => '', 'bodytext' => '<h5>jump from h2 to h5</h5>'],
        ];

        self::assertSame(['heading-skip@3'], $this->rules($sections));
    }

    #[Test]
    public function aSecondH1InTheContentIsReportedAndDoesNotChangeTheLevel(): void
    {
        $findings = $this->findingsOf(self::TITLE, '', self::description(140), [['header' => 'A', 'bodytext' => '<h1>Another title</h1><h3>ok after h2</h3>']]);

        self::assertSame(['heading-h1'], array_column($findings, 'rule'));
        self::assertSame(['Another title'], $findings[0]['params']);
    }

    #[Test]
    public function withoutAPageTitleTheFirstHeadingIsNotComparedWithAnything(): void
    {
        self::assertSame(['title-missing'], array_column($this->findingsOf('', '', self::description(140), [['header' => '', 'bodytext' => '<h4>first</h4>']]), 'rule'));
    }

    #[Test]
    public function headingsAreFoundInHtmlThatIsNotWellFormed(): void
    {
        self::assertSame(['heading-skip@0'], $this->rules([['header' => 'A', 'bodytext' => '<p>open <b>bold<h4>deep</h4>']]));
    }

    #[Test]
    public function aLongHeadingIsQuotedShortened(): void
    {
        $findings = $this->findingsOf(self::TITLE, '', self::description(140), [['header' => 'A', 'bodytext' => '<h4>' . str_repeat('w', 80) . '</h4>']]);

        self::assertSame(40, mb_strlen($findings[0]['params'][2]));
        self::assertStringEndsWith('…', $findings[0]['params'][2]);
    }

    #[Test]
    public function reportsImagesInTheContentWithoutAltText(): void
    {
        $sections = [
            ['header' => 'A', 'bodytext' => '<img src="a.png" alt="A rocket"><img src="b.png"><img src="c.png" alt="  ">'],
            ['header' => 'B', 'bodytext' => '<p><img src="d.png" alt=""></p>'],
        ];

        self::assertSame(['alt-missing@0', 'alt-missing@0', 'alt-missing@1'], $this->rules($sections));
    }

    #[Test]
    public function reportsAChosenImageFileWithoutAlternativeTextOnlyWhenItIsKnownToHaveNone(): void
    {
        $sections = [
            ['header' => 'A', 'bodytext' => '', 'imageUid' => 7],
            ['header' => 'B', 'bodytext' => '', 'imageUid' => 8],
            ['header' => 'C', 'bodytext' => '', 'imageUid' => 9],
            ['header' => 'D', 'bodytext' => '', 'imageUid' => 0],
        ];

        // 7 has no text, 8 has one, 9 is not known to the caller, section 3 has no image.
        self::assertSame(['image-alt-missing@0'], $this->rules($sections, alternatives: [7 => ' ', 8 => 'A rocket']));
    }

    #[Test]
    public function toleratesSectionsOfTheWrongShape(): void
    {
        $sections = [['header' => ['x'], 'bodytext' => 5, 'imageUid' => 'abc'], []];

        self::assertSame([], $this->rules($sections));
    }

    #[Test]
    public function findingsKeepTheOrderOfThePage(): void
    {
        $findings = $this->subject->check('', '', '', [['header' => 'A', 'bodytext' => '<h4>x</h4><img src="a">']]);

        self::assertSame(['title-missing', 'description-missing', 'heading-skip', 'alt-missing'], array_map(static fn(SeoFinding $f): string => $f->rule, $findings));
    }
}
