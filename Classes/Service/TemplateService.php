<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Throwable;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

final readonly class TemplateService
{
    /** CType groups excluded from landing page generation (not LLM-generatable). */
    private const EXCLUDED_CTYPE_GROUPS = ['menu', 'forms', 'special', 'plugins'];

    /** Individual CTypes excluded even if their group is allowed. */
    private const EXCLUDED_CTYPES = ['html', 'div', 'shortcut', 'uploads'];

    /**
     * Well-known page fields that may not appear in TCA but exist as DB columns.
     *
     * In TYPO3 v14, EXT:seo no longer registers seo_title, og_title, etc. in
     * $GLOBALS['TCA']['pages']['columns']. They still exist as DB columns and
     * are writable via DataHandler. We offer them explicitly so editors can
     * select them for LLM generation.
     *
     * @var array<string, string> field name → human-readable label
     */
    private const WELL_KNOWN_PAGE_FIELDS = [
        'seo_title' => 'SEO Title',
        'og_title' => 'Open Graph Title',
        'og_description' => 'Open Graph Description',
        'no_index' => 'No Index',
        'no_follow' => 'No Follow',
        'canonical_link' => 'Canonical Link',
        'twitter_title' => 'Twitter Title',
        'twitter_description' => 'Twitter Description',
    ];

    /**
     * Page fields excluded from template selection.
     *
     * System fields, versioning, permissions, and technical fields that are
     * not meaningful for LLM-generated landing page content.
     */
    private const EXCLUDED_PAGE_FIELDS = [
        // System / versioning / permissions
        'uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting',
        'perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody',
        'editlock', 'doktype', 'is_siteroot',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
        'l10n_parent', 'l10n_source', 'sys_language_uid', 'l18n_cfg',
        // Structural / navigation (not LLM content)
        'mount_pid', 'mount_pid_ol', 'shortcut', 'shortcut_mode', 'content_from_pid',
        'nav_hide', 'nav_title', 'php_tree_stop', 'module',
        // Layout / appearance (set by template, not by LLM)
        'backend_layout', 'backend_layout_next_level', 'layout',
        'TSconfig', 'tsconfig_includes',
        // Cache / technical
        'cache_timeout', 'cache_tags', 'no_search',
        // Access / visibility (managed by editor, not LLM)
        'starttime', 'endtime', 'fe_group', 'extendToSubpages',
        // Media / misc (handled separately or not LLM-relevant)
        'media', 'categories', 'rowDescription', 'newUntil',
        // Redirects / external URLs
        'url', 'target', 'urltype',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private ?BackendLayoutView $backendLayoutView = null,
        private ?BackendLayoutService $backendLayoutService = null,
    ) {}

    /** @param array<string, mixed> $params */
    public function getAvailableCTypes(array &$params): void
    {
        $items = $this->getTcaColumnItems('tt_content', 'CType');
        $itemGroups = $this->getTcaItemGroups('tt_content', 'CType');

        // Group items by their 'group' key, filtering excluded groups and CTypes
        /** @var array<string, list<array{label: string, value: string}>> $grouped */
        $grouped = [];
        foreach ($items as $item) {
            $value = $item['value'] ?? '';
            if ($value === '' || $value === '--div--') {
                continue;
            }
            $group = $item['group'] ?? 'other';
            if (in_array($group, self::EXCLUDED_CTYPE_GROUPS, true)) {
                continue;
            }
            if (in_array($value, self::EXCLUDED_CTYPES, true)) {
                continue;
            }
            $grouped[$group][] = [
                'label' => $this->resolveLabel($item['label'] ?? $value),
                'value' => $value,
            ];
        }

        // Output with --div-- separators per group, ordered by itemGroups definition
        /** @var list<array{label: string, value: string}> $paramItems */
        $paramItems = $params['items'] ?? [];
        foreach ($itemGroups as $groupKey => $groupLabel) {
            if (!isset($grouped[$groupKey])) {
                continue;
            }
            $paramItems[] = [
                'label' => $this->resolveLabel($groupLabel),
                'value' => '--div--',
            ];
            foreach ($grouped[$groupKey] as $field) {
                $paramItems[] = $field;
            }
            unset($grouped[$groupKey]);
        }
        // Remaining groups not in itemGroups (third-party extensions with custom groups)
        ksort($grouped);
        foreach ($grouped as $groupKey => $fields) {
            $paramItems[] = [
                'label' => ucfirst($groupKey),
                'value' => '--div--',
            ];
            foreach ($fields as $field) {
                $paramItems[] = $field;
            }
        }
        $params['items'] = $paramItems;
    }

    /** @param array<string, mixed> $params */
    public function getAvailablePageFields(array &$params): void
    {
        $tca = $this->getTcaForTable('pages');
        $columns = $this->getTcaColumns('pages');

        // Build field → tab mapping from types[1].showitem (standard page type)
        $fieldTabMap = $this->buildFieldTabMap($tca);

        /** @var array<string, list<array{label: string, value: string}>> $groups */
        $groups = [];

        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, self::EXCLUDED_PAGE_FIELDS, true)) {
                continue;
            }
            $type = $fieldConfig['config']['type'] ?? '';
            if ($type === 'passthrough' || $type === '') {
                continue;
            }
            $rawLabel = is_string($fieldConfig['label'] ?? null) ? $fieldConfig['label'] : '';
            $resolvedLabel = $this->resolveLabel($rawLabel);

            // Fall back to humanized field name when label resolution failed:
            // - empty result
            // - still an LLL: reference (language file missing)
            // - looks like a technical key (e.g. "core.db.pages.title" from TYPO3 v14 auto-labels)
            if ($resolvedLabel === '' || str_starts_with($resolvedLabel, 'LLL:') || $this->looksLikeTechnicalKey($resolvedLabel)) {
                $resolvedLabel = $this->humanizeFieldName($fieldName);
            }

            $group = $fieldTabMap[$fieldName] ?? 'Other';
            $groups[$group][] = [
                'label' => $resolvedLabel,
                'value' => $fieldName,
            ];
        }

        // TYPO3 v14 compatibility: Add well-known page fields that exist as DB
        // columns but are no longer registered in TCA (e.g. EXT:seo fields).
        $knownInGroups = [];
        foreach ($groups as $fields) {
            foreach ($fields as $field) {
                $knownInGroups[$field['value']] = true;
            }
        }
        $wellKnownToAdd = [];
        foreach (self::WELL_KNOWN_PAGE_FIELDS as $fieldName => $label) {
            if (isset($knownInGroups[$fieldName])) {
                continue;
            }
            if (!$this->dbColumnExists('pages', $fieldName)) {
                continue;
            }
            $wellKnownToAdd[] = [
                'label' => $label,
                'value' => $fieldName,
            ];
        }
        if ($wellKnownToAdd !== []) {
            $groups['SEO / Social Media'] = array_merge(
                $groups['SEO / Social Media'] ?? [],
                $wellKnownToAdd,
            );
        }

        // Output groups in the order they appear in showitem
        $tabOrder = array_unique(array_values($fieldTabMap));
        /** @var list<array{label: string, value: string}> $paramItems */
        $paramItems = $params['items'] ?? [];

        // First: tabs in their natural TCA order
        foreach ($tabOrder as $tabName) {
            if (!isset($groups[$tabName])) {
                continue;
            }
            $paramItems[] = [
                'label' => $tabName,
                'value' => '--div--',
            ];
            foreach ($groups[$tabName] as $field) {
                $paramItems[] = $field;
            }
            unset($groups[$tabName]);
        }

        // Then: remaining groups (fields not found in showitem) alphabetically
        ksort($groups);
        foreach ($groups as $groupName => $fields) {
            $paramItems[] = [
                'label' => $groupName,
                'value' => '--div--',
            ];
            foreach ($fields as $field) {
                $paramItems[] = $field;
            }
        }

        $params['items'] = $paramItems;
    }

    /**
     * Build a map of field name → tab label from the pages TCA types[1].showitem.
     *
     * Parses the showitem string to resolve --div-- tabs and --palette-- references,
     * assigning each field to the tab it appears under. This works generically with
     * any extension that adds fields via addToAllTCAtypes/addFieldsToPalette.
     *
     * @param array<string, mixed> $tca
     * @return array<string, string> field name → tab label
     */
    private function buildFieldTabMap(array $tca): array
    {
        /** @var array<string, array<string, mixed>> $types */
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];
        $typeConfig = $types['1'] ?? $types[1] ?? [];
        $showitem = is_string($typeConfig['showitem'] ?? null) ? $typeConfig['showitem'] : '';

        if ($showitem === '') {
            return [];
        }

        $map = [];
        $currentTab = 'General';
        $parts = array_map('trim', explode(',', $showitem));

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            // Tab divider: --div--;Tab Label
            if (str_starts_with($part, '--div--')) {
                $segments = explode(';', $part);
                $tabLabel = trim($segments[1] ?? '');
                if ($tabLabel !== '') {
                    $resolved = $this->resolveLabel($tabLabel);
                    if ($resolved === '' || str_starts_with($resolved, 'LLL:') || $this->looksLikeTechnicalKey($resolved)) {
                        // Extract last key segment from LLL path or technical key
                        $lastPart = substr($tabLabel, strrpos($tabLabel, ':') + 1);
                        $resolved = $this->humanizeFieldName($lastPart !== '' ? $lastPart : $tabLabel);
                    }
                    $currentTab = $resolved;
                }
                continue;
            }

            // Palette: --palette--;;paletteName
            if (str_starts_with($part, '--palette--')) {
                $segments = explode(';', $part);
                $paletteName = trim($segments[2] ?? '');
                if ($paletteName !== '') {
                    foreach ($this->resolvePaletteFields($paletteName, $tca) as $fieldName) {
                        $map[$fieldName] = $currentTab;
                    }
                }
                continue;
            }

            // Direct field: fieldName or fieldName;Label
            $fieldName = trim(explode(';', $part)[0]);
            if ($fieldName !== '' && !str_starts_with($fieldName, '--')) {
                $map[$fieldName] = $currentTab;
            }
        }

        return $map;
    }

    /**
     * Resolve a palette to its field names.
     *
     * @param array<string, mixed> $tca
     * @return list<string>
     */
    private function resolvePaletteFields(string $paletteName, array $tca): array
    {
        /** @var array<string, array{showitem?: string}> $palettes */
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];
        $paletteConfig = $palettes[$paletteName] ?? [];
        if (!is_array($paletteConfig)) {
            return [];
        }

        $showitem = is_string($paletteConfig['showitem'] ?? null) ? $paletteConfig['showitem'] : '';
        $parts = array_map('trim', explode(',', $showitem));

        $fields = [];
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            $fieldName = trim(explode(';', $part)[0]);
            if ($fieldName !== '') {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    private function resolveLabel(string $label): string
    {
        if ($label === '') {
            return '';
        }

        if (str_starts_with($label, 'LLL:')) {
            $lang = $GLOBALS['LANG'] ?? null;
            if ($lang instanceof LanguageService) {
                $resolved = $lang->sL($label);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        return $label;
    }

    /**
     * Detect technical label keys like "core.db.pages.title" or "frontend.pages.subtitle"
     * that TYPO3 v14 may return when LLL resolution produces a dot-separated key
     * instead of a human-readable label.
     */
    private function looksLikeTechnicalKey(string $label): bool
    {
        // Technical keys have at least 2 dots and no spaces (e.g. "core.db.pages.title")
        return !str_contains($label, ' ') && substr_count($label, '.') >= 2;
    }

    /**
     * Convert a TCA field name like "og_description" to "Og Description".
     */
    private function humanizeFieldName(string $fieldName): string
    {
        return ucwords(str_replace('_', ' ', $fieldName));
    }

    /** @param array<string, mixed> $params */
    public function getAvailableBackendLayouts(array &$params): void
    {
        $pid = 0;
        if (is_array($params['row'] ?? null)) {
            $rawPid = $params['row']['pid'] ?? 0;
            $pid = is_numeric($rawPid) ? (int) $rawPid : 0;
        }

        $layoutParams = [
            'table' => 'pages',
            'row' => ['uid' => $pid, 'pid' => $pid],
            'field' => 'backend_layout',
            'items' => [],
        ];
        $this->backendLayoutView?->addBackendLayoutItems($layoutParams);
        /** @var list<array{label: string, value: string, icon?: string}> $layoutItems */
        $layoutItems = $layoutParams['items'] ?? [];
        foreach ($layoutItems as $item) {
            /** @var list<array{label: string, value: string, icon?: string}> $paramItems */
            $paramItems = $params['items'] ?? [];
            $entry = [
                'label' => $item['label'] ?? '',
                'value' => $item['value'] ?? '',
            ];
            $icon = $item['icon'] ?? '';
            if ($icon !== '') {
                $entry['icon'] = $icon;
            }
            $paramItems[] = $entry;
            $params['items'] = $paramItems;
        }
    }

    public function loadByUid(int $uid): ?Template
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrlandingpage_domain_model_template');
        $queryBuilder
            ->select('*')
            ->from('tx_nrlandingpage_domain_model_template')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0),
            );

        $row = $queryBuilder->executeQuery()->fetchAssociative();
        if ($row === false) {
            return null;
        }

        $template = $this->hydrateTemplate($row);

        if (!$this->isTemplateAccessible($template)) {
            return null;
        }

        return $template;
    }

    private function isTemplateAccessible(Template $template): bool
    {
        if ($template->beGroups === []) {
            return true;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        /** @var list<int> $userGroups */
        $userGroups = $backendUser->userGroupsUID;

        return array_intersect($template->beGroups, $userGroups) !== [];
    }

    /**
     * Lightweight check whether at least one template is accessible for the current user.
     *
     * Unlike loadForUser(), this avoids hydrating all Template objects. It still
     * loads all rows because group-based access filtering requires the be_groups
     * column, but stops as soon as the first accessible template is found.
     */
    public function hasTemplatesForUser(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrlandingpage_domain_model_template');
        $queryBuilder
            ->select('uid', 'be_groups')
            ->from('tx_nrlandingpage_domain_model_template')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->andWhere($queryBuilder->expr()->eq('hidden', 0));

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            $beGroupsRaw = is_string($row['be_groups'] ?? null) ? $row['be_groups'] : '';
            $allowedGroups = array_values(array_filter(
                array_map('intval', explode(',', $beGroupsRaw)),
                static fn(int $g): bool => $g > 0,
            ));

            if ($allowedGroups === []) {
                return true;
            }

            $backendUser = $GLOBALS['BE_USER'] ?? null;
            if (!$backendUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication) {
                continue;
            }
            if ($backendUser->isAdmin()) {
                return true;
            }
            /** @var list<int> $userGroups */
            $userGroups = $backendUser->userGroupsUID;
            if (array_intersect($allowedGroups, $userGroups) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Template> */
    public function loadForUser(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrlandingpage_domain_model_template');
        $queryBuilder
            ->select('*')
            ->from('tx_nrlandingpage_domain_model_template')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->andWhere($queryBuilder->expr()->eq('hidden', 0));

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $templates = [];
        foreach ($rows as $row) {
            $template = $this->hydrateTemplate($row);
            if ($this->isTemplateAccessible($template)) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTemplate(array $row): Template
    {
        $beGroupsRaw = is_string($row['be_groups'] ?? null) ? $row['be_groups'] : '';
        $allowedGroups = array_values(array_filter(
            array_map('intval', explode(',', $beGroupsRaw)),
            static fn(int $g): bool => $g > 0,
        ));

        $allowedCTypesRaw = is_string($row['allowed_ctypes'] ?? null) ? $row['allowed_ctypes'] : '';
        $pageFieldsRaw = is_string($row['page_fields'] ?? null) ? $row['page_fields'] : '';
        $referencePagesRaw = is_string($row['reference_pages'] ?? null) ? $row['reference_pages'] : '';
        $contentColumnsRaw = is_string($row['content_columns'] ?? null) ? $row['content_columns'] : '';

        return new Template(
            uid: self::toInt($row['uid'] ?? 0),
            title: is_string($row['title'] ?? null) ? $row['title'] : '',
            identifier: is_string($row['identifier'] ?? null) ? $row['identifier'] : '',
            description: is_string($row['description'] ?? null) ? $row['description'] : '',
            llmConfiguration: self::toInt($row['llm_configuration'] ?? 0),
            systemPrompt: is_string($row['system_prompt'] ?? null) ? $row['system_prompt'] : '',
            allowedCTypes: array_values(array_filter(explode(',', $allowedCTypesRaw))),
            pageFields: array_values(array_filter(explode(',', $pageFieldsRaw))),
            referencePages: array_values(array_filter(
                array_map('intval', explode(',', $referencePagesRaw)),
                static fn(int $v): bool => $v > 0,
            )),
            briefingMode: is_string($row['briefing_mode'] ?? null) ? $row['briefing_mode'] : 'optional',
            publishMode: is_string($row['publish_mode'] ?? null) ? $row['publish_mode'] : 'hidden',
            beGroups: $allowedGroups,
            backendLayout: is_string($row['backend_layout'] ?? null) ? $row['backend_layout'] : '',
            promptOptimizerContext: is_string($row['prompt_optimizer_context'] ?? null) ? $row['prompt_optimizer_context'] : '',
            promptOptimizerMetaPrompt: is_string($row['prompt_optimizer_meta_prompt'] ?? null) ? $row['prompt_optimizer_meta_prompt'] : '',
            imageTask: self::toInt($row['image_task'] ?? 0),
            generationMode: is_string($row['generation_mode'] ?? null) && in_array($row['generation_mode'], ['structured', 'creative'], true)
                ? $row['generation_mode']
                : 'structured',
            colorPrimary: is_string($row['color_primary'] ?? null) ? $row['color_primary'] : '',
            colorSecondary: is_string($row['color_secondary'] ?? null) ? $row['color_secondary'] : '',
            colorBackground: is_string($row['color_background'] ?? null) ? $row['color_background'] : '',
            colorText: is_string($row['color_text'] ?? null) ? $row['color_text'] : '',
            animationEnabled: (bool) ($row['animation_enabled'] ?? true),
            contentColumns: $contentColumnsRaw !== ''
                ? array_values(array_filter(
                    array_map('intval', explode(',', $contentColumnsRaw)),
                    static fn(int $v): bool => $v >= 0,
                ))
                : [],
        );
    }

    /**
     * itemsProcFunc: Populate content column checkboxes from the selected backend layout.
     *
     * @param array<string, mixed> $params
     */
    public function getAvailableContentColumns(array &$params): void
    {
        $row = $params['row'] ?? [];
        $rawLayout = is_array($row) ? ($row['backend_layout'] ?? '') : '';
        // TCA form engine may pass the value as single-element array in some contexts
        if (is_array($rawLayout)) {
            $firstElement = $rawLayout[0] ?? '';
            $rawLayout = is_string($firstElement) || is_int($firstElement) ? (string) $firstElement : '';
        }
        $backendLayout = is_string($rawLayout) ? $rawLayout : '';

        if ($backendLayout === '' || $this->backendLayoutService === null) {
            return;
        }

        $columnMap = $this->backendLayoutService->getColumnMap($backendLayout);

        /** @var list<array{label?: string, value?: string|int, group?: string}> $items */
        $items = is_array($params['items'] ?? null) ? $params['items'] : [];
        foreach ($columnMap as $colPos => $name) {
            $items[] = [
                'label' => $name . ' (colPos ' . $colPos . ')',
                'value' => $colPos,
            ];
        }
        $params['items'] = $items;
    }

    /**
     * @return array<int, array{label?: string, value?: string, group?: string}>
     */
    private function getTcaColumnItems(string $table, string $column): array
    {
        $tca = $this->getTcaForTable($table);
        /** @var array<string, array{config?: array{items?: array<int, array{label?: string, value?: string, group?: string}>}}> $columns */
        $columns = $tca['columns'] ?? [];
        $columnConfig = $columns[$column] ?? [];

        return $columnConfig['config']['items'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function getTcaItemGroups(string $table, string $column): array
    {
        $tca = $this->getTcaForTable($table);
        /** @var array<string, array{config?: array{itemGroups?: array<string, string>}}> $columns */
        $columns = $tca['columns'] ?? [];
        $columnConfig = $columns[$column] ?? [];

        return $columnConfig['config']['itemGroups'] ?? [];
    }

    /**
     * @return array<string, array{label?: string, config?: array{type?: string}}>
     */
    private function getTcaColumns(string $table): array
    {
        $tca = $this->getTcaForTable($table);
        /** @var array<string, array{label?: string, config?: array{type?: string}}> $columns */
        $columns = $tca['columns'] ?? [];

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTcaForTable(string $table): array
    {
        /** @var array<string, array<string, mixed>> $tca */
        $tca = $GLOBALS['TCA'] ?? [];

        return $tca[$table] ?? [];
    }

    /**
     * Check whether a column exists in the database table.
     *
     * Used for TYPO3 v14 compatibility where some fields (e.g. from EXT:seo)
     * exist as DB columns but are no longer registered in TCA.
     */
    private function dbColumnExists(string $table, string $column): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $schemaManager = $connection->createSchemaManager();
            $tableColumns = $schemaManager->listTableColumns($table);

            return isset($tableColumns[$column]);
        } catch (Throwable) {
            return false;
        }
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return (int) $value;
        }

        return 0;
    }
}
