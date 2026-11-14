<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Storage of the workspace drafts (ADR-0001). Plain QueryBuilder: the table
 * has no TCA, so nothing edits it around the checks of the services.
 */
class DraftRepository
{
    public const TABLE = 'tx_nrlandingpage_workspace_draft';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $beGroups
     * @param array<array-key, mixed> $briefingData
     * @param array<string, string> $pageFields
     * @param list<array<string, mixed>> $sections
     */
    public function insert(
        int $owner,
        string $title,
        int $templateUid,
        int $parentPageUid,
        array $briefingData,
        array $pageFields,
        array $sections,
        array $beGroups,
        int $now,
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'owner' => $owner,
            'be_groups' => implode(',', array_values(array_filter($beGroups, static fn(int $group): bool => $group > 0))),
            'title' => mb_substr($title, 0, 255),
            'template_uid' => $templateUid,
            'parent_page_uid' => max(0, $parentPageUid),
            'briefing_data' => WorkspaceDraft::encode($briefingData),
            'page_fields' => WorkspaceDraft::encode($pageFields),
            'sections' => WorkspaceDraft::encode($sections),
            'version' => 1,
            'status' => DraftStatus::Draft->value,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function findByUid(int $uid): ?WorkspaceDraft
    {
        if ($uid <= 0) {
            return null;
        }
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)),
                $builder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? WorkspaceDraft::fromRow($row) : null;
    }

    /**
     * The drafts a backend user sees: all of them for an administrator, else
     * the user's own and those shared with one of the user's groups. A group
     * is matched with the platform's set function, never with LIKE, so group
     * 1 does not match group 12.
     *
     * @param list<int> $groupUids the user's resolved groups, subgroups included
     * @return list<WorkspaceDraft>
     */
    public function findVisible(int $userUid, bool $isAdmin, array $groupUids): array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('deleted', 0))
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC');

        if (!$isAdmin) {
            $visible = [$builder->expr()->eq('owner', $builder->createNamedParameter($userUid, Connection::PARAM_INT))];
            foreach ($groupUids as $group) {
                if ($group > 0) {
                    $visible[] = $builder->expr()->inSet('be_groups', $builder->quote((string) $group));
                }
            }
            $builder->andWhere($builder->expr()->or(...$visible));
        }

        return array_map(
            static fn(array $row): WorkspaceDraft => WorkspaceDraft::fromRow($row),
            $builder->executeQuery()->fetchAllAssociative(),
        );
    }
}
