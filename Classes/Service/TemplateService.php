<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class TemplateService
{
    private const EXCLUDED_PAGE_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting',
        'perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody',
        'editlock', 'doktype', 'is_siteroot', 'mount_pid', 'mount_pid_ol',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 'l10n_parent', 'l10n_source',
        'sys_language_uid',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /** @param array<string, mixed> $params */
    public function getAvailableCTypes(array &$params): void
    {
        $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [];
        foreach ($items as $item) {
            $value = $item['value'] ?? '';
            if ($value === '' || $value === '--div--') {
                continue;
            }
            $params['items'][] = [
                'label' => $item['label'] ?? $value,
                'value' => $value,
            ];
        }
    }

    /** @param array<string, mixed> $params */
    public function getAvailablePageFields(array &$params): void
    {
        $columns = $GLOBALS['TCA']['pages']['columns'] ?? [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, self::EXCLUDED_PAGE_FIELDS, true)) {
                continue;
            }
            $type = $fieldConfig['config']['type'] ?? '';
            if ($type === 'passthrough' || $type === '') {
                continue;
            }
            $label = $fieldConfig['label'] ?? $fieldName;
            $params['items'][] = [
                'label' => $label . ' [' . $fieldName . ']',
                'value' => $fieldName,
            ];
        }
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

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $isAdmin = $backendUser?->isAdmin() ?? false;
        $userGroups = $backendUser?->userGroupsUID ?? [];

        $templates = [];
        foreach ($rows as $row) {
            $allowedGroups = array_filter(
                array_map('intval', explode(',', (string)($row['be_groups'] ?? ''))),
                static fn(int $g): bool => $g > 0,
            );

            if ($allowedGroups === [] || $isAdmin || array_intersect($allowedGroups, $userGroups) !== []) {
                $templates[] = new Template(
                    uid: (int)$row['uid'],
                    title: (string)$row['title'],
                    identifier: (string)$row['identifier'],
                    description: (string)($row['description'] ?? ''),
                    llmConfiguration: (int)($row['llm_configuration'] ?? 0),
                    systemPrompt: (string)($row['system_prompt'] ?? ''),
                    allowedCTypes: array_filter(explode(',', (string)($row['allowed_ctypes'] ?? ''))),
                    pageFields: array_filter(explode(',', (string)($row['page_fields'] ?? ''))),
                    referencePages: array_filter(
                        array_map('intval', explode(',', (string)($row['reference_pages'] ?? ''))),
                        static fn(int $v): bool => $v > 0,
                    ),
                    briefingMode: (string)($row['briefing_mode'] ?? 'optional'),
                    publishMode: (string)($row['publish_mode'] ?? 'hidden'),
                    beGroups: $allowedGroups,
                );
            }
        }

        return $templates;
    }
}
