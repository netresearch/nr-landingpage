<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\SectionChildren;
use Netresearch\NrLandingpage\Service\SectionChildrenRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An accordion section of the Page Composer is created with its items, through
 * nr-llm's content-type rule and the real DataHandler (NEXT-229).
 */
#[CoversClass(PageCreatorService::class)]
#[CoversClass(SectionChildren::class)]
final class PageCreatorServiceChildrenTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
        'typo3conf/ext/nr_landingpage/Tests/Functional/Fixtures/Extensions/nrlp_children_fixture',
    ];

    /** "Open to everybody" in Fixtures/pages.csv. */
    private const PARENT = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function anAccordionSectionIsCreatedWithItsItems(): void
    {
        $this->setUpBackendUser(1);
        $children = $this->get(SectionChildren::class)->clean('nrlp_accordion', [
            'tx_nrlpfixture_items' => [
                ['header' => 'First question', 'bodytext' => '<p>Answer <script>x</script></p>', 'unknown' => 'dropped'],
                ['bodytext' => 'no header, dropped'],
                ['header' => 'Second question'],
            ],
        ]);
        self::assertSame(['First question', 'Second question'], array_column($children['tx_nrlpfixture_items'] ?? [], 'header'));
        self::assertStringNotContainsString('<script>', $children['tx_nrlpfixture_items'][0]['bodytext'] ?? '');

        $result = $this->get(PageCreatorService::class)->createLandingPage(
            new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['nrlp_accordion']),
            self::PARENT,
            'FAQ page',
            '/faq',
            [],
            [['section' => 'faq', 'ctype' => 'nrlp_accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => $children]],
        );

        $element = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->select(['uid', 'pid', 'CType', 'tx_nrlpfixture_items'], 'tt_content', ['uid' => $result['contentUids'][0]])->fetchAssociative();
        self::assertIsArray($element);
        self::assertSame('nrlp_accordion', $element['CType']);
        self::assertSame(2, (int) $element['tx_nrlpfixture_items']);

        $items = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrlpfixture_item')
            ->select(['header', 'pid', 'parent_content'], 'tx_nrlpfixture_item', [], [], ['sorting' => 'ASC'])->fetchAllAssociative();
        self::assertSame(['First question', 'Second question'], array_column($items, 'header'));
        foreach ($items as $item) {
            self::assertSame($result['pageUid'], (int) $item['pid']);
            self::assertSame((int) $element['uid'], (int) $item['parent_content']);
        }
    }

    #[Test]
    public function anEditorWithoutTheItemTableIsRefusedAndNothingIsCreated(): void
    {
        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'pages,tt_content';
        $editor->groupData['tables_select'] = 'pages,tt_content';
        $editor->groupData['pagetypes_select'] = '1';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:nrlp_accordion';
        $editor->groupData['non_exclude_fields'] = 'tx_nrlpfixture_item:header';

        try {
            $this->get(PageCreatorService::class)->createLandingPage(
                new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['nrlp_accordion']),
                self::PARENT,
                'FAQ page',
                '/faq',
                [],
                [['section' => 'faq', 'ctype' => 'nrlp_accordion', 'header' => 'FAQ', 'subheader' => '', 'bodytext' => '', 'children' => ['tx_nrlpfixture_items' => [['header' => 'Q']]]]],
            );
            self::fail('The section must be refused');
        } catch (SectionChildrenRefusedException $e) {
            self::assertStringContainsString('tables_modify', $e->getMessage());
        }

        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages')->count('uid', 'pages', ['title' => 'FAQ page']);
        self::assertSame(0, $pages, 'nothing was written');
    }
}
