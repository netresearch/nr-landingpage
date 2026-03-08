<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(TemplateService::class)]
final class TemplateServiceTest extends UnitTestCase
{
    #[Test]
    public function getAvailableCTypesGroupsByItemGroup(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Header', 'value' => 'header', 'group' => 'default'],
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Bullet List', 'value' => 'bullets', 'group' => 'lists'],
                ['label' => 'Table', 'value' => 'table', 'group' => 'lists'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
                'lists' => 'Lists',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        // Expect: --div-- "Typical page content", header, text, --div-- "Lists", bullets, table
        self::assertCount(6, $params['items']);
        self::assertSame('--div--', $params['items'][0]['value']);
        self::assertSame('Typical page content', $params['items'][0]['label']);
        self::assertSame('header', $params['items'][1]['value']);
        self::assertSame('text', $params['items'][2]['value']);
        self::assertSame('--div--', $params['items'][3]['value']);
        self::assertSame('Lists', $params['items'][3]['label']);
        self::assertSame('bullets', $params['items'][4]['value']);
        self::assertSame('table', $params['items'][5]['value']);
    }

    #[Test]
    public function getAvailableCTypesExcludesMenuFormsSpecialPluginsGroups(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Menu Pages', 'value' => 'menu_pages', 'group' => 'menu'],
                ['label' => 'Form', 'value' => 'form_formframework', 'group' => 'forms'],
                ['label' => 'HTML', 'value' => 'html', 'group' => 'special'],
                ['label' => 'Plugin', 'value' => 'list', 'group' => 'plugins'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
                'menu' => 'Menu',
                'forms' => 'Forms',
                'special' => 'Special elements',
                'plugins' => 'Plugins',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        $values = array_column($params['items'], 'value');
        self::assertContains('text', $values);
        self::assertNotContains('menu_pages', $values);
        self::assertNotContains('form_formframework', $values);
        self::assertNotContains('html', $values);
        self::assertNotContains('list', $values);
    }

    #[Test]
    public function getAvailableCTypesExcludesSpecificCTypes(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Divider', 'value' => 'div', 'group' => 'default'],
                ['label' => 'Shortcut', 'value' => 'shortcut', 'group' => 'default'],
                ['label' => 'File Links', 'value' => 'uploads', 'group' => 'default'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        $values = array_filter(
            array_column($params['items'], 'value'),
            static fn(string $v): bool => $v !== '--div--',
        );
        self::assertSame(['text'], array_values($values));
    }

    #[Test]
    public function getAvailableCTypesSkipsEmptyValues(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Header', 'value' => 'header', 'group' => 'default'],
                ['label' => 'Empty', 'value' => '', 'group' => 'default'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        $values = array_filter(
            array_column($params['items'], 'value'),
            static fn(string $v): bool => $v !== '--div--',
        );
        self::assertCount(1, $values);
    }

    #[Test]
    public function getAvailableCTypesIncludesThirdPartyExtensionCTypes(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Accordion', 'value' => 'ce_accordion', 'group' => 'container'],
                ['label' => 'Tabs', 'value' => 'ce_tabs', 'group' => 'container'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
                'container' => 'Container Elements',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        $values = array_column($params['items'], 'value');
        self::assertContains('ce_accordion', $values);
        self::assertContains('ce_tabs', $values);
    }

    #[Test]
    public function getAvailableCTypesSkipsEmptyGroups(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Plugin', 'value' => 'list', 'group' => 'plugins'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
                'lists' => 'Lists',
                'plugins' => 'Plugins',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        // 'lists' group has no items, 'plugins' is excluded → only 'default' group header + text
        $divLabels = array_values(array_map(
            static fn(array $item): string => $item['label'],
            array_filter($params['items'], static fn(array $item): bool => $item['value'] === '--div--'),
        ));
        self::assertSame(['Typical page content'], $divLabels);
    }

    #[Test]
    public function getAvailableCTypesFallsBackToOtherGroupForItemsWithoutGroup(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
                ['label' => 'Custom', 'value' => 'custom_element'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
            ],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        $values = array_filter(
            array_column($params['items'], 'value'),
            static fn(string $v): bool => $v !== '--div--',
        );
        self::assertContains('text', $values);
        self::assertContains('custom_element', $values);
    }

    /**
     * Set up a realistic pages TCA with types, palettes, and columns.
     *
     * @param array<string, array{label?: string, config?: array<string, mixed>}> $columns
     * @param string $showitem types[1].showitem string
     * @param array<string, array{showitem?: string}> $palettes
     */
    private function setPagesTca(array $columns, string $showitem = '', array $palettes = []): void
    {
        $GLOBALS['TCA']['pages'] = [
            'columns' => $columns,
            'types' => [
                '1' => ['showitem' => $showitem],
            ],
            'palettes' => $palettes,
        ];
    }

    #[Test]
    public function getAvailablePageFieldsExcludesSystemFields(): void
    {
        $this->setPagesTca(
            columns: [
                'abstract' => ['label' => 'Abstract', 'config' => ['type' => 'text']],
                'uid' => ['label' => 'UID', 'config' => ['type' => 'input']],
                'pid' => ['label' => 'PID', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;General,abstract',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldValues = array_column($params['items'], 'value');
        self::assertContains('abstract', $fieldValues);
        self::assertNotContains('uid', $fieldValues);
        self::assertNotContains('pid', $fieldValues);
    }

    #[Test]
    public function getAvailablePageFieldsExcludesPassthroughFields(): void
    {
        $this->setPagesTca(
            columns: [
                'abstract' => ['label' => 'Abstract', 'config' => ['type' => 'text']],
                'internal' => ['label' => 'Internal', 'config' => ['type' => 'passthrough']],
            ],
            showitem: '--div--;General,abstract,internal',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldValues = array_filter(
            array_column($params['items'], 'value'),
            static fn(string $v): bool => $v !== '--div--',
        );
        self::assertCount(1, $fieldValues);
    }

    #[Test]
    public function getAvailablePageFieldsUsesResolvedLabelWithoutFieldKey(): void
    {
        $this->setPagesTca(
            columns: [
                'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;SEO,seo_title',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldItems = array_filter(
            $params['items'],
            static fn(array $item): bool => ($item['value'] ?? '') !== '--div--',
        );
        $firstField = array_values($fieldItems)[0];
        self::assertSame('SEO Title', $firstField['label']);
        self::assertSame('seo_title', $firstField['value']);
    }

    #[Test]
    public function getAvailablePageFieldsGroupsByTcaTabs(): void
    {
        $this->setPagesTca(
            columns: [
                'title' => ['label' => 'Title', 'config' => ['type' => 'input']],
                'abstract' => ['label' => 'Abstract', 'config' => ['type' => 'text']],
                'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
                'og_title' => ['label' => 'OG Title', 'config' => ['type' => 'input']],
            ],
            showitem: implode(',', [
                '--div--;General',
                '--palette--;;titlePalette',
                '--palette--;;abstractPalette',
                '--div--;SEO',
                'seo_title,og_title',
            ]),
            palettes: [
                'titlePalette' => ['showitem' => 'title'],
                'abstractPalette' => ['showitem' => 'abstract'],
            ],
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $items = $params['items'];

        // Expect: --div-- General, title, abstract, --div-- SEO, seo_title, og_title
        self::assertSame('General', $items[0]['label']);
        self::assertSame('--div--', $items[0]['value']);
        self::assertSame('title', $items[1]['value']);
        self::assertSame('abstract', $items[2]['value']);
        self::assertSame('SEO', $items[3]['label']);
        self::assertSame('--div--', $items[3]['value']);
        self::assertContains('seo_title', array_column(array_slice($items, 4), 'value'));
        self::assertContains('og_title', array_column(array_slice($items, 4), 'value'));
    }

    #[Test]
    public function getAvailablePageFieldsPreservesTabOrder(): void
    {
        $this->setPagesTca(
            columns: [
                'og_description' => ['label' => 'OG Desc', 'config' => ['type' => 'text']],
                'abstract' => ['label' => 'Abstract', 'config' => ['type' => 'text']],
            ],
            showitem: '--div--;General,abstract,--div--;SEO,og_description',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $divLabels = array_values(array_map(
            static fn(array $item): string => $item['label'],
            array_filter($params['items'], static fn(array $item): bool => $item['value'] === '--div--'),
        ));

        self::assertSame('General', $divLabels[0]);
        self::assertSame('SEO', $divLabels[1]);
    }

    #[Test]
    public function getAvailablePageFieldsInsertsDivSeparators(): void
    {
        $this->setPagesTca(
            columns: [
                'title' => ['label' => 'Title', 'config' => ['type' => 'input']],
                'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;General,title,--div--;SEO,seo_title',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $divItems = array_filter($params['items'], static fn(array $item): bool => $item['value'] === '--div--');
        self::assertCount(2, $divItems);
    }

    #[Test]
    public function getAvailablePageFieldsGroupsUnmappedFieldsUnderOther(): void
    {
        $this->setPagesTca(
            columns: [
                'title' => ['label' => 'Title', 'config' => ['type' => 'input']],
                'custom_ext_field' => ['label' => 'Custom', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;General,title',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $divLabels = array_values(array_map(
            static fn(array $item): string => $item['label'],
            array_filter($params['items'], static fn(array $item): bool => $item['value'] === '--div--'),
        ));

        self::assertContains('General', $divLabels);
        self::assertContains('Other', $divLabels);
    }

    #[Test]
    public function getAvailablePageFieldsFallsBackToHumanizedNameForUnresolvedLabel(): void
    {
        $this->setPagesTca(
            columns: [
                'og_description' => ['label' => '', 'config' => ['type' => 'text']],
            ],
            showitem: '--div--;SEO,og_description',
        );

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldItems = array_filter(
            $params['items'],
            static fn(array $item): bool => ($item['value'] ?? '') !== '--div--',
        );
        $firstField = array_values($fieldItems)[0];
        self::assertStringContainsString('Og Description', $firstField['label']);
    }

    #[Test]
    public function getAvailablePageFieldsFallsBackForTechnicalKeyLabels(): void
    {
        $this->setPagesTca(
            columns: [
                'description' => ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_tca.xlf:pages.description', 'config' => ['type' => 'text']],
            ],
            showitem: '--div--;General,description',
        );

        // Simulate sL() returning a technical key instead of a human label
        $langService = $this->createMock(LanguageService::class);
        $langService->method('sL')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'LLL:EXT:core/Resources/Private/Language/locallang_tca.xlf:pages.description' => 'core.db.pages.description',
                default => '',
            });
        $GLOBALS['LANG'] = $langService;

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldItems = array_filter(
            $params['items'],
            static fn(array $item): bool => ($item['value'] ?? '') !== '--div--',
        );
        $firstField = array_values($fieldItems)[0];
        // Should fall back to humanized field name, not show "core.db.pages.description"
        self::assertStringContainsString('Description', $firstField['label']);
        self::assertStringNotContainsString('core.db.pages', $firstField['label']);
    }

    #[Test]
    public function loadByUidReturnsTemplateWhenFound(): void
    {
        $row = $this->createTemplateRow();

        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(1, $template->uid);
        self::assertSame('Test Template', $template->title);
        self::assertSame('test', $template->identifier);
        self::assertSame('Test description', $template->description);
        self::assertSame(0, $template->llmConfiguration);
        self::assertSame('Test prompt', $template->systemPrompt);
        self::assertSame('optional', $template->briefingMode);
        self::assertSame('hidden', $template->publishMode);
    }

    #[Test]
    public function loadByUidReturnsNullWhenNotFound(): void
    {
        $service = new TemplateService($this->createMockConnectionPool(false));

        $template = $service->loadByUid(999);

        self::assertNull($template);
    }

    #[Test]
    public function loadByUidReturnsNullWhenGroupRestricted(): void
    {
        $row = $this->createTemplateRow(['be_groups' => '1,2']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->userGroupsUID = [99];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNull($template);
    }

    #[Test]
    public function loadByUidReturnsTemplateForAdmin(): void
    {
        $row = $this->createTemplateRow(['be_groups' => '1,2']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->userGroupsUID = [];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(1, $template->uid);
    }

    #[Test]
    public function loadByUidReturnsTemplateWhenNoGroupRestriction(): void
    {
        $row = $this->createTemplateRow(['be_groups' => '']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->userGroupsUID = [99];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(1, $template->uid);
    }

    #[Test]
    public function hasTemplatesForUserReturnsFalseWhenNoTemplates(): void
    {
        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([]));

        self::assertFalse($service->hasTemplatesForUser());
    }

    #[Test]
    public function hasTemplatesForUserReturnsTrueWhenUnrestrictedTemplateExists(): void
    {
        $row = $this->createTemplateRow(['uid' => 1, 'be_groups' => '']);
        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([$row]));

        self::assertTrue($service->hasTemplatesForUser());
    }

    #[Test]
    public function hasTemplatesForUserReturnsFalseWhenAllTemplatesRestricted(): void
    {
        $row = $this->createTemplateRow(['uid' => 1, 'be_groups' => '5,6']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->userGroupsUID = [99];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([$row]));

        self::assertFalse($service->hasTemplatesForUser());
    }

    #[Test]
    public function hasTemplatesForUserReturnsTrueForAdmin(): void
    {
        $row = $this->createTemplateRow(['uid' => 1, 'be_groups' => '5,6']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->userGroupsUID = [];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([$row]));

        self::assertTrue($service->hasTemplatesForUser());
    }

    #[Test]
    public function loadForUserReturnsAccessibleTemplates(): void
    {
        $row1 = $this->createTemplateRow(['uid' => 1, 'title' => 'Template 1', 'be_groups' => '']);
        $row2 = $this->createTemplateRow(['uid' => 2, 'title' => 'Template 2', 'be_groups' => '']);

        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([$row1, $row2]));

        $templates = $service->loadForUser();

        self::assertCount(2, $templates);
        self::assertSame(1, $templates[0]->uid);
        self::assertSame('Template 1', $templates[0]->title);
        self::assertSame(2, $templates[1]->uid);
        self::assertSame('Template 2', $templates[1]->title);
    }

    #[Test]
    public function loadForUserFiltersInaccessibleTemplates(): void
    {
        $row1 = $this->createTemplateRow(['uid' => 1, 'title' => 'Restricted', 'be_groups' => '5,6']);
        $row2 = $this->createTemplateRow(['uid' => 2, 'title' => 'Open', 'be_groups' => '']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->userGroupsUID = [99];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new TemplateService($this->createMockConnectionPoolForMultipleRows([$row1, $row2]));

        $templates = $service->loadForUser();

        self::assertCount(1, $templates);
        self::assertSame(2, $templates[0]->uid);
        self::assertSame('Open', $templates[0]->title);
    }

    #[Test]
    public function hydrateTemplateParsesCommaSeparatedFields(): void
    {
        $row = $this->createTemplateRow([
            'allowed_ctypes' => 'text,header',
            'page_fields' => 'seo_title,description',
            'reference_pages' => '10,20',
        ]);

        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(['text', 'header'], $template->allowedCTypes);
        self::assertSame(['seo_title', 'description'], $template->pageFields);
        self::assertSame([10, 20], $template->referencePages);
    }

    #[Test]
    public function toIntHandlesVariousTypes(): void
    {
        $rowWithStringUid = $this->createTemplateRow(['uid' => '42']);
        $service = new TemplateService($this->createMockConnectionPool($rowWithStringUid));
        $template = $service->loadByUid(42);
        self::assertNotNull($template);
        self::assertSame(42, $template->uid);

        $rowWithIntUid = $this->createTemplateRow(['uid' => 7]);
        $service = new TemplateService($this->createMockConnectionPool($rowWithIntUid));
        $template = $service->loadByUid(7);
        self::assertNotNull($template);
        self::assertSame(7, $template->uid);

        $rowWithBoolUid = $this->createTemplateRow(['uid' => false]);
        $service = new TemplateService($this->createMockConnectionPool($rowWithBoolUid));
        $template = $service->loadByUid(0);
        self::assertNotNull($template);
        self::assertSame(0, $template->uid);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createTemplateRow(array $overrides = []): array
    {
        return array_merge([
            'uid' => 1,
            'title' => 'Test Template',
            'identifier' => 'test',
            'description' => 'Test description',
            'llm_configuration' => 0,
            'system_prompt' => 'Test prompt',
            'allowed_ctypes' => 'text,header',
            'page_fields' => 'seo_title,description',
            'reference_pages' => '10,20',
            'briefing_mode' => 'optional',
            'publish_mode' => 'hidden',
            'be_groups' => '',
            'backend_layout' => '',
            'prompt_optimizer_context' => '',
            'prompt_optimizer_meta_prompt' => '',
        ], $overrides);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createMockConnectionPool(array|false $row): ConnectionPool
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $statement = $this->createMock(Result::class);
        $statement->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($statement);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createMockConnectionPoolForMultipleRows(array $rows): ConnectionPool
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $statement = $this->createMock(Result::class);
        $statement->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($statement);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    #[Test]
    public function loadByUidReturnsFalseWhenBeUserIsNotBackendUserAuthentication(): void
    {
        // Set BE_USER to something that is not BackendUserAuthentication
        $GLOBALS['BE_USER'] = new stdClass();

        $row = $this->createTemplateRow(['be_groups' => '1,2']);
        $connectionPool = $this->createMockConnectionPool($row);

        $service = new TemplateService($connectionPool);
        $result = $service->loadByUid(1);

        // Template has be_groups restriction, but BE_USER is not a BackendUserAuthentication → null
        self::assertNull($result);
    }

    #[Test]
    public function hydrateTemplateReadsBackendLayout(): void
    {
        $row = $this->createTemplateRow(['backend_layout' => 'pagets__default']);
        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('pagets__default', $template->backendLayout);
    }

    #[Test]
    public function hydrateTemplateReadsPromptOptimizerFields(): void
    {
        $row = $this->createTemplateRow([
            'prompt_optimizer_context' => 'Brand: Acme Corp',
            'prompt_optimizer_meta_prompt' => 'Custom meta-prompt',
        ]);
        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('Brand: Acme Corp', $template->promptOptimizerContext);
        self::assertSame('Custom meta-prompt', $template->promptOptimizerMetaPrompt);
    }

    #[Test]
    public function hydrateTemplateDefaultsPromptOptimizerFieldsToEmpty(): void
    {
        $row = $this->createTemplateRow();
        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('', $template->promptOptimizerContext);
        self::assertSame('', $template->promptOptimizerMetaPrompt);
    }

    #[Test]
    public function hydrateTemplateDefaultsBackendLayoutToEmpty(): void
    {
        $row = $this->createTemplateRow();
        $service = new TemplateService($this->createMockConnectionPool($row));

        $template = $service->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('', $template->backendLayout);
    }

    #[Test]
    public function getAvailablePageFieldsResolvesLllLabels(): void
    {
        $this->setPagesTca(
            columns: [
                'title' => ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.title', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;General,title',
        );

        $langService = $this->createMock(LanguageService::class);
        $langService->method('sL')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.title' => 'Title',
                default => '',
            });
        $GLOBALS['LANG'] = $langService;

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldItems = array_filter(
            $params['items'],
            static fn(array $item): bool => ($item['value'] ?? '') !== '--div--',
        );
        $firstField = array_values($fieldItems)[0];
        self::assertStringContainsString('Title', $firstField['label']);
        self::assertStringNotContainsString('LLL:', $firstField['label']);
    }

    #[Test]
    public function getAvailablePageFieldsFallsBackToHumanizedNameWhenLangUnavailable(): void
    {
        $this->setPagesTca(
            columns: [
                'seo_title' => ['label' => 'LLL:EXT:core/test.xlf:title', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;General,seo_title',
        );
        unset($GLOBALS['LANG']);

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        $fieldItems = array_filter(
            $params['items'],
            static fn(array $item): bool => ($item['value'] ?? '') !== '--div--',
        );
        $firstField = array_values($fieldItems)[0];
        // LLL resolution failed, so it should fall back to humanized field name
        self::assertStringContainsString('Seo Title', $firstField['label']);
        self::assertStringNotContainsString('LLL:', $firstField['label']);
    }

    #[Test]
    public function getAvailablePageFieldsResolvesLllTabLabels(): void
    {
        $this->setPagesTca(
            columns: [
                'title' => ['label' => 'Title', 'config' => ['type' => 'input']],
            ],
            showitem: '--div--;LLL:EXT:core/Resources/Private/Language/locallang.xlf:general,title',
        );

        $langService = $this->createMock(LanguageService::class);
        $langService->method('sL')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'LLL:EXT:core/Resources/Private/Language/locallang.xlf:general' => 'General',
                default => '',
            });
        $GLOBALS['LANG'] = $langService;

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertSame('General', $params['items'][0]['label']);
    }

    #[Test]
    public function getAvailableCTypesResolvesLllLabels(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config'] = [
            'items' => [
                ['label' => 'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:CType.text', 'value' => 'text', 'group' => 'default'],
            ],
            'itemGroups' => [
                'default' => 'Typical page content',
            ],
        ];

        $langService = $this->createMock(LanguageService::class);
        $langService->method('sL')
            ->willReturn('Regular Text Element');
        $GLOBALS['LANG'] = $langService;

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        // First item is the --div-- group header, second is the CType
        $ctypeItems = array_values(array_filter(
            $params['items'],
            static fn(array $item): bool => $item['value'] !== '--div--',
        ));
        self::assertSame('Regular Text Element', $ctypeItems[0]['label']);
    }

    #[Test]
    public function getAvailableBackendLayoutsUsesRecordPid(): void
    {
        $backendLayoutView = $this->createMock(BackendLayoutView::class);
        $backendLayoutView->expects(self::once())
            ->method('addBackendLayoutItems')
            ->with(self::callback(
                static fn(array $p): bool => ($p['row']['pid'] ?? null) === 42,
            ));

        $service = new TemplateService(
            $this->createMock(ConnectionPool::class),
            $backendLayoutView,
        );
        $params = ['items' => [], 'row' => ['pid' => 42]];
        $service->getAvailableBackendLayouts($params);
    }

    #[Test]
    public function getAvailableBackendLayoutsDefaultsPidToZero(): void
    {
        $backendLayoutView = $this->createMock(BackendLayoutView::class);
        $backendLayoutView->expects(self::once())
            ->method('addBackendLayoutItems')
            ->with(self::callback(
                static fn(array $p): bool => ($p['row']['pid'] ?? null) === 0,
            ));

        $service = new TemplateService(
            $this->createMock(ConnectionPool::class),
            $backendLayoutView,
        );
        $params = ['items' => []];
        $service->getAvailableBackendLayouts($params);
    }

    #[Test]
    public function getAvailableBackendLayoutsAddsItemsFromView(): void
    {
        $backendLayoutView = $this->createMock(BackendLayoutView::class);
        $backendLayoutView->method('addBackendLayoutItems')
            ->willReturnCallback(static function (array &$params): void {
                $params['items'][] = ['label' => 'Default', 'value' => 'pagets__default', 'icon' => 'EXT:my_ext/icon.svg'];
                $params['items'][] = ['label' => 'Two Column', 'value' => 'pagets__two_column'];
            });

        $service = new TemplateService(
            $this->createMock(ConnectionPool::class),
            $backendLayoutView,
        );
        $params = ['items' => []];
        $service->getAvailableBackendLayouts($params);

        self::assertCount(2, $params['items']);
        self::assertSame('pagets__default', $params['items'][0]['value']);
        self::assertSame('EXT:my_ext/icon.svg', $params['items'][0]['icon']);
        self::assertSame('Two Column', $params['items'][1]['label']);
        self::assertArrayNotHasKey('icon', $params['items'][1]);
    }

    #[Test]
    public function getAvailableBackendLayoutsHandlesNullView(): void
    {
        $service = new TemplateService(
            $this->createMock(ConnectionPool::class),
            null,
        );
        $params = ['items' => []];
        $service->getAvailableBackendLayouts($params);

        self::assertSame([], $params['items']);
    }
}
