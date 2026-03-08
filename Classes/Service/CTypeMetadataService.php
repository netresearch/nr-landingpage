<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Extracts LLM-friendly metadata from TCA for allowed CTypes.
 *
 * Reads labels, descriptions, groups, and content-relevant fields
 * from $GLOBALS['TCA']['tt_content'] at runtime. Works generically
 * with any CType registration (core, mask, bootstrap_package, etc.).
 */
class CTypeMetadataService
{
    /**
     * Fields that are irrelevant for LLM content generation (appearance, access, system).
     */
    private const IGNORED_FIELDS = [
        'CType', 'colPos', 'sys_language_uid', 'l18n_parent', 'l10n_source',
        'hidden', 'starttime', 'endtime', 'fe_group', 'editlock',
        'layout', 'frame_class', 'space_before_class', 'space_after_class',
        'sectionIndex', 'linkToTop', 'categories', 'rowDescription',
        'date', '--linebreak--', '--div--',
    ];

    /**
     * Palettes that contain only layout/appearance fields.
     */
    private const IGNORED_PALETTES = [
        'general', 'hidden', 'language', 'access', 'frames', 'appearanceLinks',
    ];

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * Build an LLM-friendly JSON description for a set of CTypes.
     *
     * @param list<string> $cTypes CType identifiers (e.g. ['text', 'textmedia'])
     * @return string JSON string with metadata per CType
     */
    public function buildCTypeDescription(array $cTypes): string
    {
        if ($cTypes === []) {
            return '';
        }

        /** @var array<string, array<string, mixed>> $globalTca */
        $globalTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        /** @var array<string, mixed> $tca */
        $tca = is_array($globalTca['tt_content'] ?? null) ? $globalTca['tt_content'] : [];
        if (!is_array($tca) || $tca === []) {
            return '';
        }

        $languageService = $this->getLanguageService();
        $itemMeta = $this->buildItemMetaMap($tca, $languageService);

        /** @var array<string, array<string, mixed>> $types */
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];

        $result = [];
        foreach ($cTypes as $cType) {
            if ($cType === '' || str_starts_with($cType, '--')) {
                continue;
            }

            $meta = $itemMeta[$cType] ?? null;
            $typeConfig = $types[$cType] ?? null;

            $entry = [
                'label' => $meta['label'] ?? $cType,
                'description' => $meta['description'] ?? '',
            ];

            if (($meta['group'] ?? '') !== '') {
                $entry['group'] = $meta['group'];
            }

            if (is_array($typeConfig)) {
                $fields = $this->extractContentFields($tca, $typeConfig, $languageService);
                if ($fields !== []) {
                    $entry['fields'] = $fields;
                }
            }

            $result[$cType] = $entry;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * Build a lookup map of CType item metadata (label, description, group).
     *
     * @param array<string, mixed> $tca
     * @return array<string, array{label: string, description: string, group: string}>
     */
    private function buildItemMetaMap(array $tca, LanguageService $languageService): array
    {
        /** @var array<string, mixed> $columns */
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        /** @var array<string, mixed> $ctypeCol */
        $ctypeCol = is_array($columns['CType'] ?? null) ? $columns['CType'] : [];
        /** @var array<string, mixed> $ctypeConfig */
        $ctypeConfig = is_array($ctypeCol['config'] ?? null) ? $ctypeCol['config'] : [];
        /** @var list<array{label?: string, value?: string, description?: string, group?: string}> $items */
        $items = is_array($ctypeConfig['items'] ?? null) ? $ctypeConfig['items'] : [];
        $map = [];

        foreach ($items as $item) {
            $value = $item['value'] ?? '';
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                continue;
            }

            $map[$value] = [
                'label' => $this->translate($item['label'] ?? '', $languageService),
                'description' => $this->translate($item['description'] ?? '', $languageService),
                'group' => is_string($item['group'] ?? null) ? $item['group'] : '',
            ];
        }

        return $map;
    }

    /**
     * Extract content-relevant fields from a CType's type configuration.
     *
     * Resolves palettes, filters out appearance/system fields, and returns
     * a compact field description map.
     *
     * @param array<string, mixed> $tca
     * @param array<string, mixed> $typeConfig
     * @return array<string, string> field name → short description
     */
    private function extractContentFields(array $tca, array $typeConfig, LanguageService $languageService): array
    {
        $showitem = is_string($typeConfig['showitem'] ?? null) ? $typeConfig['showitem'] : '';
        /** @var array<string, array{config?: array<string, mixed>}> $rawOverrides */
        $rawOverrides = $typeConfig['columnsOverrides'] ?? [];
        $columnsOverrides = is_array($rawOverrides) ? $rawOverrides : [];

        $fieldNames = $this->parseShowitemFields($showitem, $tca);

        $fields = [];
        /** @var array<string, array{label?: string, config?: array{type?: string, renderType?: string, foreign_table?: string, allowed?: string, enableRichtext?: bool}}>  $columns */
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];

        foreach ($fieldNames as $fieldName) {
            if (in_array($fieldName, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $columnConfig = $columns[$fieldName]['config'] ?? [];
            $overrideConfig = $columnsOverrides[$fieldName]['config'] ?? [];
            $merged = array_merge(
                is_array($columnConfig) ? $columnConfig : [],
                is_array($overrideConfig) ? $overrideConfig : [],
            );

            $label = $this->translate($columns[$fieldName]['label'] ?? '', $languageService);
            $desc = $this->describeField($fieldName, $merged, $label);

            if ($desc !== '') {
                $fields[$fieldName] = $desc;
            }
        }

        return $fields;
    }

    /**
     * Parse a showitem string into a flat list of field names, resolving palettes.
     *
     * @param array<string, mixed> $tca
     * @return list<string>
     */
    private function parseShowitemFields(string $showitem, array $tca): array
    {
        $parts = array_map('trim', explode(',', $showitem));
        $fields = [];

        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '--div--')) {
                continue;
            }

            if (str_starts_with($part, '--palette--')) {
                $paletteName = $this->extractPaletteName($part);
                if ($paletteName !== '' && !in_array($paletteName, self::IGNORED_PALETTES, true)) {
                    $paletteFields = $this->resolvePalette($paletteName, $tca);
                    foreach ($paletteFields as $pf) {
                        $fields[] = $pf;
                    }
                }
                continue;
            }

            // Field with optional label override: "fieldName;Label"
            $fieldName = explode(';', $part)[0];
            $fieldName = trim($fieldName);
            if ($fieldName !== '') {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Extract palette name from "--palette--;;paletteName" or "--palette--;Label;paletteName".
     */
    private function extractPaletteName(string $part): string
    {
        $segments = explode(';', $part);
        // Format: --palette--;optionalLabel;paletteName
        return trim($segments[2] ?? '');
    }

    /**
     * Resolve a palette to its field names.
     *
     * @param array<string, mixed> $tca
     * @return list<string>
     */
    private function resolvePalette(string $paletteName, array $tca): array
    {
        /** @var array<string, mixed> $palettes */
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];
        $paletteConfig = $palettes[$paletteName] ?? [];
        if (!is_array($paletteConfig)) {
            return [];
        }

        $showitem = is_string($paletteConfig['showitem'] ?? null) ? $paletteConfig['showitem'] : '';
        $parts = array_map('trim', explode(',', $showitem));

        $fields = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '--linebreak--') {
                continue;
            }
            $fieldName = explode(';', $part)[0];
            $fieldName = trim($fieldName);
            if ($fieldName !== '') {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Generate a short human-readable description of a TCA field for LLM consumption.
     *
     * @param array<string, mixed> $config Merged TCA column config
     */
    private function describeField(string $fieldName, array $config, string $label): string
    {
        $type = is_string($config['type'] ?? null) ? $config['type'] : '';
        $renderType = is_string($config['renderType'] ?? null) ? $config['renderType'] : '';

        // File references (image, assets, media)
        if ($type === 'file' || $type === 'inline' || $type === 'group') {
            $allowed = is_string($config['allowed'] ?? null) ? $config['allowed'] : '';
            $foreignTable = is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';
            if ($foreignTable === 'sys_file_reference' || $allowed !== '') {
                return ($label !== '' ? $label : $fieldName) . ' (file references)';
            }
        }

        // Richtext
        if (!empty($config['enableRichtext'])) {
            return ($label !== '' ? $label : $fieldName) . ' (rich text HTML)';
        }

        // Special render types
        if ($renderType === 'textTable') {
            return ($label !== '' ? $label : $fieldName) . ' (table data, pipe-separated)';
        }

        if ($renderType === 'codeEditor') {
            return ($label !== '' ? $label : $fieldName) . ' (raw HTML code)';
        }

        // Text area (plain text, e.g. bullets)
        if ($type === 'text') {
            $wrap = is_string($config['wrap'] ?? null) ? $config['wrap'] : '';
            if ($wrap === 'off') {
                return ($label !== '' ? $label : $fieldName) . ' (plain text, one item per line)';
            }
            return ($label !== '' ? $label : $fieldName) . ' (text)';
        }

        // Select with items
        if ($type === 'select' && is_array($config['items'] ?? null)) {
            /** @var list<array{label?: string, value?: string|int}> $items */
            $items = $config['items'];
            $values = [];
            foreach ($items as $item) {
                $v = $item['value'] ?? $item['label'] ?? '';
                if (is_string($v) || is_int($v)) {
                    $values[] = (string) $v;
                }
            }
            return ($label !== '' ? $label : $fieldName) . ' (select: ' . implode(', ', $values) . ')';
        }

        // Input
        if ($type === 'input') {
            return ($label !== '' ? $label : $fieldName) . ' (string)';
        }

        // Check
        if ($type === 'check') {
            return ($label !== '' ? $label : $fieldName) . ' (boolean)';
        }

        // Link
        if ($type === 'link') {
            return ($label !== '' ? $label : $fieldName) . ' (URL/link)';
        }

        // Default: use label if available
        if ($label !== '') {
            return $label . ' (' . ($type !== '' ? $type : 'unknown') . ')';
        }

        return '';
    }

    private function translate(string $value, LanguageService $languageService): string
    {
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, 'LLL:')) {
            return $value;
        }

        $translated = $languageService->sL($value);

        return $translated !== '' ? $translated : $value;
    }

    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            return $lang;
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $user = $beUser instanceof \TYPO3\CMS\Core\Authentication\AbstractUserAuthentication ? $beUser : null;

        return $this->languageServiceFactory->createFromUserPreferences($user);
    }
}
