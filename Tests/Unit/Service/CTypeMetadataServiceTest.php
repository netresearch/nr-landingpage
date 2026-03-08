<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(CTypeMetadataService::class)]
final class CTypeMetadataServiceTest extends UnitTestCase
{
    private LanguageService $languageService;
    private CTypeMetadataService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->languageService = $this->createMock(LanguageService::class);
        // Default: pass through LLL strings as-is
        $this->languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => str_starts_with($key, 'LLL:') ? substr($key, strrpos($key, ':') + 1) : $key,
        );

        $GLOBALS['LANG'] = $this->languageService;

        $factory = $this->createMock(LanguageServiceFactory::class);
        $this->subject = new CTypeMetadataService($factory);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['TCA']);
        parent::tearDown();
    }

    private function setTca(array $tca): void
    {
        $GLOBALS['TCA']['tt_content'] = $tca;
    }

    private function buildMinimalTca(array $ctypeItems = [], array $types = [], array $palettes = [], array $columns = []): array
    {
        return [
            'columns' => array_merge([
                'CType' => [
                    'config' => [
                        'items' => $ctypeItems,
                    ],
                ],
            ], $columns),
            'types' => $types,
            'palettes' => $palettes,
        ];
    }

    #[Test]
    public function buildCTypeDescriptionReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->subject->buildCTypeDescription([]));
    }

    #[Test]
    public function buildCTypeDescriptionReturnsEmptyStringWhenTcaEmpty(): void
    {
        unset($GLOBALS['TCA']);
        self::assertSame('', $this->subject->buildCTypeDescription(['text']));
    }

    #[Test]
    public function buildCTypeDescriptionReturnsJsonWithLabelAndDescription(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Regular Text', 'value' => 'text', 'description' => 'A text element', 'group' => 'default'],
            ],
            types: [
                'text' => ['showitem' => 'header'],
            ],
            columns: [
                'header' => ['label' => 'Header', 'config' => ['type' => 'input']],
            ],
        ));

        $json = $this->subject->buildCTypeDescription(['text']);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('text', $decoded);
        self::assertSame('Regular Text', $decoded['text']['label']);
        self::assertSame('A text element', $decoded['text']['description']);
    }

    #[Test]
    public function buildCTypeDescriptionIncludesGroupWhenPresent(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Bullets', 'value' => 'bullets', 'description' => '', 'group' => 'lists'],
            ],
            types: ['bullets' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['bullets']), true);

        self::assertSame('lists', $decoded['bullets']['group']);
    }

    #[Test]
    public function buildCTypeDescriptionOmitsGroupWhenEmpty(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => '', 'group' => ''],
            ],
            types: ['text' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);

        self::assertArrayNotHasKey('group', $decoded['text']);
    }

    #[Test]
    public function buildCTypeDescriptionResolvesPaletteFields(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: [
                'text' => ['showitem' => '--palette--;;headers,bodytext'],
            ],
            palettes: [
                'headers' => ['showitem' => 'header,--linebreak--,subheader'],
            ],
            columns: [
                'header' => ['label' => 'Header', 'config' => ['type' => 'input']],
                'subheader' => ['label' => 'Subheader', 'config' => ['type' => 'input']],
                'bodytext' => ['label' => 'Body', 'config' => ['type' => 'text', 'enableRichtext' => true]],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);

        self::assertArrayHasKey('fields', $decoded['text']);
        self::assertArrayHasKey('header', $decoded['text']['fields']);
        self::assertArrayHasKey('subheader', $decoded['text']['fields']);
        self::assertArrayHasKey('bodytext', $decoded['text']['fields']);
    }

    #[Test]
    public function buildCTypeDescriptionIgnoresFilteredPalettes(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: [
                'text' => ['showitem' => '--palette--;;frames,--palette--;;access,bodytext'],
            ],
            palettes: [
                'frames' => ['showitem' => 'layout,frame_class'],
                'access' => ['showitem' => 'starttime,endtime'],
            ],
            columns: [
                'bodytext' => ['label' => 'Body', 'config' => ['type' => 'text', 'enableRichtext' => true]],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);
        $fields = $decoded['text']['fields'] ?? [];

        // frames and access palettes should be ignored
        self::assertArrayNotHasKey('layout', $fields);
        self::assertArrayNotHasKey('frame_class', $fields);
        self::assertArrayNotHasKey('starttime', $fields);
        self::assertArrayNotHasKey('endtime', $fields);
        // bodytext should still be present
        self::assertArrayHasKey('bodytext', $fields);
    }

    #[Test]
    public function buildCTypeDescriptionIgnoresFilteredFields(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: [
                'text' => ['showitem' => 'CType,colPos,hidden,header,bodytext,categories'],
            ],
            columns: [
                'header' => ['label' => 'Header', 'config' => ['type' => 'input']],
                'bodytext' => ['label' => 'Body', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);
        $fields = $decoded['text']['fields'] ?? [];

        self::assertArrayNotHasKey('CType', $fields);
        self::assertArrayNotHasKey('colPos', $fields);
        self::assertArrayNotHasKey('hidden', $fields);
        self::assertArrayNotHasKey('categories', $fields);
        self::assertArrayHasKey('header', $fields);
        self::assertArrayHasKey('bodytext', $fields);
    }

    #[Test]
    public function extractContentFieldsMergesColumnsOverrides(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: [
                'text' => [
                    'showitem' => 'bodytext',
                    'columnsOverrides' => [
                        'bodytext' => ['config' => ['enableRichtext' => true]],
                    ],
                ],
            ],
            columns: [
                'bodytext' => ['label' => 'Body', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);

        // The columnsOverride adds enableRichtext, so description should say "rich text HTML"
        self::assertStringContainsString('rich text HTML', $decoded['text']['fields']['bodytext']);
    }

    #[Test]
    public function describeFieldIdentifiesFileReferences(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Media', 'value' => 'textmedia', 'description' => ''],
            ],
            types: [
                'textmedia' => ['showitem' => 'assets'],
            ],
            columns: [
                'assets' => ['label' => 'Media', 'config' => ['type' => 'file', 'foreign_table' => 'sys_file_reference']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['textmedia']), true);

        self::assertStringContainsString('file references', $decoded['textmedia']['fields']['assets']);
    }

    #[Test]
    public function describeFieldIdentifiesSelectWithItems(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Bullets', 'value' => 'bullets', 'description' => ''],
            ],
            types: [
                'bullets' => ['showitem' => 'bullets_type'],
            ],
            columns: [
                'bullets_type' => [
                    'label' => 'Type',
                    'config' => [
                        'type' => 'select',
                        'items' => [
                            ['label' => 'Unordered', 'value' => '0'],
                            ['label' => 'Ordered', 'value' => '1'],
                        ],
                    ],
                ],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['bullets']), true);

        self::assertStringContainsString('select:', $decoded['bullets']['fields']['bullets_type']);
        self::assertStringContainsString('0', $decoded['bullets']['fields']['bullets_type']);
        self::assertStringContainsString('1', $decoded['bullets']['fields']['bullets_type']);
    }

    #[Test]
    public function describeFieldHandlesTextTable(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Table', 'value' => 'table', 'description' => ''],
            ],
            types: [
                'table' => [
                    'showitem' => 'bodytext',
                    'columnsOverrides' => [
                        'bodytext' => ['config' => ['renderType' => 'textTable', 'wrap' => 'off']],
                    ],
                ],
            ],
            columns: [
                'bodytext' => ['label' => 'Content', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['table']), true);

        self::assertStringContainsString('table data', $decoded['table']['fields']['bodytext']);
    }

    #[Test]
    public function describeFieldHandlesPlainTextWrapOff(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Bullets', 'value' => 'bullets', 'description' => ''],
            ],
            types: [
                'bullets' => [
                    'showitem' => 'bodytext',
                    'columnsOverrides' => [
                        'bodytext' => ['config' => ['wrap' => 'off']],
                    ],
                ],
            ],
            columns: [
                'bodytext' => ['label' => 'Items', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['bullets']), true);

        self::assertStringContainsString('one item per line', $decoded['bullets']['fields']['bodytext']);
    }

    #[Test]
    public function describeFieldHandlesCodeEditorRenderType(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'HTML', 'value' => 'html', 'description' => ''],
            ],
            types: [
                'html' => [
                    'showitem' => 'bodytext',
                    'columnsOverrides' => [
                        'bodytext' => ['config' => ['renderType' => 'codeEditor']],
                    ],
                ],
            ],
            columns: [
                'bodytext' => ['label' => 'Content', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['html']), true);

        self::assertStringContainsString('raw HTML code', $decoded['html']['fields']['bodytext']);
    }

    #[Test]
    public function describeFieldHandlesCheckType(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Test', 'value' => 'test', 'description' => ''],
            ],
            types: [
                'test' => ['showitem' => 'no_cache'],
            ],
            columns: [
                'no_cache' => ['label' => 'No Cache', 'config' => ['type' => 'check']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['test']), true);

        self::assertStringContainsString('boolean', $decoded['test']['fields']['no_cache']);
    }

    #[Test]
    public function describeFieldHandlesLinkType(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Test', 'value' => 'test', 'description' => ''],
            ],
            types: [
                'test' => ['showitem' => 'header_link'],
            ],
            columns: [
                'header_link' => ['label' => 'Link', 'config' => ['type' => 'link']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['test']), true);

        self::assertStringContainsString('URL/link', $decoded['test']['fields']['header_link']);
    }

    #[Test]
    public function describeFieldReturnsEmptyForUnknownTypeWithoutLabel(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Test', 'value' => 'test', 'description' => ''],
            ],
            types: [
                'test' => ['showitem' => 'custom_field'],
            ],
            columns: [
                'custom_field' => ['label' => '', 'config' => ['type' => 'passthrough']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['test']), true);

        // passthrough type with no label should not produce a field entry
        self::assertArrayNotHasKey('fields', $decoded['test']);
    }

    #[Test]
    public function translateReturnsOriginalWhenNotLllPrefix(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Plain Label', 'value' => 'text', 'description' => 'Plain desc'],
            ],
            types: ['text' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);

        self::assertSame('Plain Label', $decoded['text']['label']);
        self::assertSame('Plain desc', $decoded['text']['description']);
    }

    #[Test]
    public function unknownCTypeUsesIdentifierAsLabel(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [],
            types: ['mask_custom' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['mask_custom']), true);

        self::assertSame('mask_custom', $decoded['mask_custom']['label']);
    }

    #[Test]
    public function buildItemMetaMapSkipsDividerItems(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'divider', 'value' => '--div--'],
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: ['text' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text', '--div--']), true);

        self::assertArrayHasKey('text', $decoded);
        self::assertArrayNotHasKey('--div--', $decoded);
    }

    #[Test]
    public function buildCTypeDescriptionSkipsEmptyStringCTypes(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: ['text' => ['showitem' => '']],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text', '', 'text']), true);

        self::assertCount(1, $decoded);
        self::assertArrayHasKey('text', $decoded);
    }

    #[Test]
    public function divTabsInShowitemAreSkipped(): void
    {
        $this->setTca($this->buildMinimalTca(
            ctypeItems: [
                ['label' => 'Text', 'value' => 'text', 'description' => ''],
            ],
            types: [
                'text' => ['showitem' => '--div--;Tab Label,header,--div--;Another Tab,bodytext'],
            ],
            columns: [
                'header' => ['label' => 'Header', 'config' => ['type' => 'input']],
                'bodytext' => ['label' => 'Body', 'config' => ['type' => 'text']],
            ],
        ));

        $decoded = json_decode($this->subject->buildCTypeDescription(['text']), true);
        $fields = $decoded['text']['fields'] ?? [];

        self::assertArrayHasKey('header', $fields);
        self::assertArrayHasKey('bodytext', $fields);
        self::assertCount(2, $fields);
    }
}
