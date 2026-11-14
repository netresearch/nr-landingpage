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
     * Write new sections: the one write rule of ADR-0001, section 6. The row
     * changes only when the draft is still open and still at the version the
     * caller read together with the content it changed, and the version rises
     * in the same statement. False means someone else wrote first (or the
     * draft is approved): nothing was written.
     *
     * @param list<array<string, mixed>> $sections
     */
    public function updateSections(int $uid, array $sections, int $readVersion, int $now): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET sections = ?, version = version + 1, tstamp = ?'
            . ' WHERE uid = ? AND deleted = 0 AND status = ? AND version = ?',
            [WorkspaceDraft::encode($sections), $now, $uid, DraftStatus::Draft->value, $readVersion],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        );

        return $affected === 1;
    }

    /**
     * Approve: the one conditional update of ADR-0001, section 9. It also
     * takes ownership of the page creation (`approval_attempt`) and writes the
     * parent page the approver chose, which is part of the version. False means
     * the draft changed or was approved after the approver looked.
     */
    public function approve(int $uid, int $version, int $parentPageUid, int $user, string $attempt, int $now): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, approved_by = ?, approved_at = ?, approved_version = ?,'
            . ' approval_attempt = ?, parent_page_uid = ?, version = version + 1, tstamp = ?'
            . ' WHERE uid = ? AND deleted = 0 AND status = ? AND version = ?',
            [DraftStatus::Approved->value, $user, $now, $version, $attempt, $parentPageUid, $now, $uid, DraftStatus::Draft->value, $version],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        );

        return $affected === 1;
    }

    /**
     * The attempt that approved the draft ends: the page exists. Only the
     * attempt that owns the approval can finish it.
     */
    public function finalize(int $uid, string $attempt, int $pageUid, int $now): bool
    {
        if ($attempt === '') {
            return false;
        }
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, created_page_uid = ?, tstamp = ?'
            . ' WHERE uid = ? AND status = ? AND approval_attempt = ?',
            [DraftStatus::Created->value, $pageUid, $now, $uid, DraftStatus::Approved->value, $attempt],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR],
        );

        return $affected === 1;
    }

    /**
     * Take an approval back: the draft is open again and the attempt that
     * owned it is void. Guarded by that attempt, so a stale attempt cannot
     * revert a newer approval.
     */
    public function revert(int $uid, string $attempt, int $now): bool
    {
        if ($attempt === '') {
            return false;
        }
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . " SET status = ?, approved_by = 0, approved_at = 0, approved_version = 0, approval_attempt = '', version = version + 1, tstamp = ?"
            . ' WHERE uid = ? AND status = ? AND approval_attempt = ?',
            [DraftStatus::Draft->value, $now, $uid, DraftStatus::Approved->value, $attempt],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR],
        );

        return $affected === 1;
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
