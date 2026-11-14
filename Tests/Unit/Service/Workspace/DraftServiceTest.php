<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\ImageNotReadableException;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\SectionNormalizer;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftService;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftService::class)]
#[CoversClass(SectionNormalizer::class)]
final class DraftServiceTest extends TestCase
{
    private DraftRepository&MockObject $drafts;

    private ImageSearchService&MockObject $images;

    private DraftService $subject;

    /** @var list<array<string, mixed>> */
    private array $inserted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->inserted = [];
        $this->drafts = $this->createMock(DraftRepository::class);
        $this->drafts->method('insert')->willReturnCallback(
            function (int $owner, string $title, int $templateUid, int $parentPageUid, array $briefing, array $pageFields, array $sections, array $beGroups, int $now): int {
                $this->inserted[] = compact('owner', 'title', 'templateUid', 'parentPageUid', 'briefing', 'pageFields', 'sections', 'beGroups', 'now');

                return 11;
            },
        );
        $this->images = $this->createMock(ImageSearchService::class);
        $this->images->method('isReadable')->willReturnCallback(static fn(int $uid): bool => $uid !== 666);
        $this->subject = new DraftService($this->drafts, new SectionNormalizer($this->images, new CreativeHtmlSanitizer()));
    }

    #[Test]
    public function aDraftKeepsTheNormalisedSectionsWithStableUniqueIds(): void
    {
        $uid = $this->subject->create(
            $this->template(),
            ' Launch ',
            9,
            ['topic' => 'x'],
            ['description' => 'd'],
            [
                ['section' => 'hero', 'ctype' => 'text', 'header' => 'A', 'bodytext' => '<p>a</p>', 'animation' => ['type' => 'fade']],
                ['section' => 'faq', 'ctype' => 'banana', 'header' => 'B', 'bodytext' => '<p>b</p>'],
            ],
            5,
            1000,
        );

        self::assertSame(11, $uid);
        $row = $this->inserted[0];
        self::assertSame('Launch', $row['title']);
        self::assertSame(5, $row['owner']);
        self::assertSame(9, $row['parentPageUid']);
        self::assertSame([], $row['beGroups'], 'a new draft is shared with nobody');
        $sections = $row['sections'];
        self::assertIsArray($sections);
        self::assertCount(2, $sections);
        self::assertSame('text', $sections[1]['ctype'], 'a content type the template does not allow becomes text');
        self::assertSame(['type' => 'fade'], $sections[0]['animation']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sections[0]['id']);
        self::assertNotSame($sections[0]['id'], $sections[1]['id']);
    }

    #[Test]
    public function aPastedSourceTextIsNotKept(): void
    {
        $this->subject->create(
            $this->template(),
            'T',
            0,
            [ContentGeneratorService::SOURCE_TEXT_KEY => 'secret notes', 'topic' => 'x'],
            [],
            [['header' => 'A']],
            5,
            1000,
        );

        self::assertSame(['topic' => 'x'], $this->inserted[0]['briefing']);
    }

    #[Test]
    public function aCreativeTemplateIsRefused(): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->create($this->template('creative'), 'T', 0, [], [], [['header' => 'A']], 5, 1000);
    }

    #[Test]
    public function aDraftNeedsATitleAndASection(): void
    {
        foreach ([['  ', [['header' => 'A']]], ['T', []], ['T', ['not a section']]] as [$title, $sections]) {
            try {
                $this->subject->create($this->template(), $title, 0, [], [], $sections, 5, 1000);
                self::fail('A draft without a title or sections was stored.');
            } catch (WorkspaceRefusedException) {
                // expected
            }
        }
        self::assertSame([], $this->inserted);
    }

    #[Test]
    public function aDraftWithTooManySectionsOrTooMuchTextIsRefused(): void
    {
        $many = array_fill(0, DraftService::MAX_SECTIONS + 1, ['header' => 'A']);
        $large = [['header' => 'A', 'bodytext' => str_repeat('x', DraftService::MAX_SECTIONS_BYTES)]];

        foreach ([$many, $large] as $sections) {
            try {
                $this->subject->create($this->template(), 'T', 0, [], [], $sections, 5, 1000);
                self::fail('An oversized draft was stored.');
            } catch (WorkspaceRefusedException) {
                // expected
            }
        }
        self::assertSame([], $this->inserted);
    }

    #[Test]
    public function aDraftAtTheSectionLimitIsStored(): void
    {
        $this->subject->create($this->template(), 'T', 0, [], [], array_fill(0, DraftService::MAX_SECTIONS, ['header' => 'A']), 5, 1000);

        self::assertCount(1, $this->inserted);
    }

    #[Test]
    public function anImageTheUserMayNotReadIsRefused(): void
    {
        $this->expectException(ImageNotReadableException::class);
        $this->subject->create($this->template(), 'T', 0, [], [], [['header' => 'A', 'imageUid' => 666]], 5, 1000);
    }

    private function template(string $mode = 'structured'): Template
    {
        return new Template(
            uid: 1,
            title: 'T',
            identifier: 't',
            description: '',
            llmConfiguration: 0,
            systemPrompt: '',
            allowedCTypes: ['text', 'textmedia'],
            generationMode: $mode,
        );
    }
}
