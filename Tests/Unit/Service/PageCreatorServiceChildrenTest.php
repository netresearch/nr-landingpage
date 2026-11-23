<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use Netresearch\NrLandingpage\Service\AnimationLoaderService;
use Netresearch\NrLandingpage\Service\AnimationScriptBuilder;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\SectionChildren;
use Netresearch\NrLandingpage\Service\SectionChildrenRefusedException;
use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementDataMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A section with child records — the items of an accordion — is written with
 * them in the page's one datamap, refused before anything is written when
 * they cannot be, and never turned into textpic by its image (NEXT-229).
 */
#[CoversClass(PageCreatorService::class)]
final class PageCreatorServiceChildrenTest extends UnitTestCase
{
    private const ITEMS = ['tx_items' => [['header' => 'Q1'], ['header' => 'Q2']]];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function theChildRowsGoIntoTheDatamapOfThePage(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('dataMap')
            ->with('accordion', 'NEW_content_0', self::ITEMS, 'NEW_page', 10)
            ->willReturn(new ContentElementDataMap([
                'tt_content' => ['NEW_content_0' => ['tx_items' => 'NEWa,NEWb', 'pi_flexform' => ['data' => []]]],
                'tx_item' => ['NEWa' => ['pid' => 'NEW_page', 'header' => 'Q1'], 'NEWb' => ['pid' => 'NEW_page', 'header' => 'Q2']],
            ], []));

        $written = null;
        $dataHandler = $this->dataHandler(['NEW_page' => 1, 'NEW_content_0' => 2]);
        $dataHandler->expects(self::once())->method('start')->willReturnCallback(
            static function (array $dataMap) use (&$written): void {
                $written = $dataMap;
            },
        );

        $this->service($dataHandler, $children)->createLandingPage(
            $this->template(),
            10,
            'Page',
            '/page',
            [],
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => self::ITEMS]],
        );

        self::assertIsArray($written);
        self::assertSame(['pages', 'tt_content', 'tx_item'], array_keys($written), 'tt_content before the child table');
        $element = $written['tt_content']['NEW_content_0'];
        self::assertSame('accordion', $element['CType']);
        self::assertSame('NEWa,NEWb', $element['tx_items']);
        self::assertArrayNotHasKey(PageCreatorService::CHILDREN_KEY, $element, 'the internal key is not written');
        self::assertSame(['NEWa', 'NEWb'], array_keys($written['tx_item']));
    }

    #[Test]
    public function aRefusedChildRefusesThePageBeforeAnythingIsWritten(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->method('dataMap')->willReturn(ContentElementDataMap::refused('Refused: no "tables_modify" grant.'));

        $dataHandler = $this->dataHandler([]);
        $dataHandler->expects(self::never())->method('start');

        $this->expectException(SectionChildrenRefusedException::class);
        $this->expectExceptionMessage('no "tables_modify" grant');

        $this->service($dataHandler, $children)->createLandingPage(
            $this->template(),
            10,
            'Page',
            '/page',
            [],
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => self::ITEMS]],
        );
    }

    #[Test]
    public function anElementWithChildrenKeepsItsTypeAndGetsNoImage(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->method('dataMap')->willReturn(new ContentElementDataMap([
            'tt_content' => ['NEW_content_0' => ['tx_items' => 'NEWa']],
            'tx_item' => ['NEWa' => ['pid' => 'NEW_page', 'header' => 'Q1']],
        ], []));

        $written = [];
        $dataHandler = $this->dataHandler(['NEW_page' => 1, 'NEW_content_0' => 2]);
        $dataHandler->expects(self::once())->method('start')->willReturnCallback(
            static function (array $dataMap) use (&$written): void {
                $written[] = $dataMap;
            },
        );

        $this->service($dataHandler, $children)->createLandingPage(
            $this->template(),
            10,
            'Page',
            '/page',
            [],
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'imageUid' => 7, 'children' => ['tx_items' => [['header' => 'Q1']]]]],
        );

        // One write only: no second pass that attaches the image.
        self::assertCount(1, $written);
        self::assertSame('accordion', $written[0]['tt_content']['NEW_content_0']['CType']);
    }

    #[Test]
    public function aListenerThatReordersTheElementsKeepsEachElementsChildren(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('dataMap')
            ->with('accordion', 'NEW_content_1')
            ->willReturn(new ContentElementDataMap(['tt_content' => ['NEW_content_1' => ['tx_items' => 'NEWa']], 'tx_item' => ['NEWa' => ['header' => 'Q1']]], []));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof BeforePageCreationEvent) {
                $event->contentElements = array_reverse($event->contentElements);
            }

            return $event;
        });

        $written = null;
        $dataHandler = $this->dataHandler(['NEW_page' => 1]);
        $dataHandler->method('start')->willReturnCallback(static function (array $dataMap) use (&$written): void {
            $written ??= $dataMap;
        });

        $this->service($dataHandler, $children, $dispatcher)->createLandingPage(
            $this->template(),
            10,
            'Page',
            '/page',
            [],
            [
                ['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => ['tx_items' => [['header' => 'Q1']]]],
                ['section' => 'intro', 'ctype' => 'text', 'header' => 'Intro', 'subheader' => '', 'bodytext' => '<p>x</p>'],
            ],
        );

        self::assertIsArray($written);
        self::assertSame('accordion', $written['tt_content']['NEW_content_1']['CType']);
        self::assertSame('NEWa', $written['tt_content']['NEW_content_1']['tx_items']);
        self::assertArrayNotHasKey('tx_items', $written['tt_content']['NEW_content_0']);
    }

    #[Test]
    public function aSectionWithoutChildrenAsksNothing(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::never())->method('dataMap');

        $this->service($this->dataHandler(['NEW_page' => 1]), $children)->createLandingPage(
            $this->template(),
            10,
            'Page',
            '/page',
            [],
            [['section' => 'intro', 'ctype' => 'text', 'header' => 'Intro', 'subheader' => '', 'bodytext' => '<p>x</p>']],
        );
    }

    #[Test]
    public function theApprovalCheckRefusesWithoutWriting(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->method('dataMap')->willReturn(ContentElementDataMap::refused('Refused: grant.'));
        $dataHandler = $this->dataHandler([]);
        $dataHandler->expects(self::never())->method('start');

        $this->expectException(SectionChildrenRefusedException::class);
        $this->service($dataHandler, $children)->assertChildrenWritable(
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => self::ITEMS]],
            10,
        );
    }

    private function template(): Template
    {
        return new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['accordion', 'text'], animationEnabled: false);
    }

    /**
     * @param array<string, int> $substMap
     */
    private function dataHandler(array $substMap): DataHandler&MockObject
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->substNEWwithIDs = $substMap;
        $dataHandler->errorLog = [];

        return $dataHandler;
    }

    private function service(DataHandler $dataHandler, SectionChildren $children, ?EventDispatcherInterface $dispatcher = null): PageCreatorService
    {
        if ($dispatcher === null) {
            $dispatcher = $this->createMock(EventDispatcherInterface::class);
            $dispatcher->method('dispatch')->willReturnArgument(0);
        }

        return new class ($dispatcher, $this->createMock(ResourceFactory::class), $children, $dataHandler) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $eventDispatcher,
                ResourceFactory $resourceFactory,
                SectionChildren $sectionChildren,
                private readonly DataHandler $mockDataHandler,
            ) {
                parent::__construct($eventDispatcher, $resourceFactory, new AnimationLoaderService(), new AnimationScriptBuilder(), $sectionChildren);
            }

            protected function createDataHandler(): DataHandler
            {
                return $this->mockDataHandler;
            }

            protected function getCurrentWorkspaceId(): int
            {
                return 0;
            }
        };
    }
}
