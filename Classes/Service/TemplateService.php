<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class TemplateService
{
    private const EXCLUDED_PAGE_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting',
        'perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody',
        'editlock', 'doktype', 'is_siteroot', 'mount_pid', 'mount_pid_ol',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 'l10n_parent', 'l10n_source',
        'sys_language_uid',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /** @param array<string, mixed> $params */
    public function getAvailableCTypes(array &$params): void
    {
        $items = $this->getTcaColumnItems('tt_content', 'CType');
        foreach ($items as $item) {
            $value = $item['value'] ?? '';
            if ($value === '' || $value === '--div--') {
                continue;
            }
            /** @var list<array{label: string, value: string}> $paramItems */
            $paramItems = $params['items'] ?? [];
            $paramItems[] = [
                'label' => $item['label'] ?? $value,
                'value' => $value,
            ];
            $params['items'] = $paramItems;
        }
    }

    /** @param array<string, mixed> $params */
    public function getAvailablePageFields(array &$params): void
    {
        $columns = $this->getTcaColumns('pages');
        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, self::EXCLUDED_PAGE_FIELDS, true)) {
                continue;
            }
            $type = $fieldConfig['config']['type'] ?? '';
            if ($type === 'passthrough' || $type === '') {
                continue;
            }
            $label = $fieldConfig['label'] ?? $fieldName;
            /** @var list<array{label: string, value: string}> $paramItems */
            $paramItems = $params['items'] ?? [];
            $paramItems[] = [
                'label' => $label . ' [' . $fieldName . ']',
                'value' => $fieldName,
            ];
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
        );
    }

    /**
     * @return array<int, array{label?: string, value?: string}>
     */
    private function getTcaColumnItems(string $table, string $column): array
    {
        $tca = $this->getTcaForTable($table);
        /** @var array<string, array{config?: array{items?: array<int, array{label?: string, value?: string}>}}> $columns */
        $columns = $tca['columns'] ?? [];
        $columnConfig = $columns[$column] ?? [];

        return $columnConfig['config']['items'] ?? [];
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
