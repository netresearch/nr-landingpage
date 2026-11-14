<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The append-only audit trail of a draft (ADR-0001, section 2). It has an
 * insert method and a read method, and no update or delete: a record cannot
 * be rewritten through it. It holds no token, no passphrase and no text of a
 * reviewer.
 */
class AuditRepository
{
    public const TABLE = 'tx_nrlandingpage_workspace_audit';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function record(int $draftUid, int $linkUid, int $beUser, AuditAction $action, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'draft_uid' => $draftUid,
            'link_uid' => $linkUid,
            'be_user' => $beUser,
            'action' => $action->value,
        ]);
    }

    /**
     * @return list<array{linkUid: int, beUser: int, action: string, crdate: int}> newest first
     */
    public function findByDraft(int $draftUid, int $limit = 50): array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('link_uid', 'be_user', 'action', 'crdate')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('draft_uid', $builder->createNamedParameter($draftUid, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $int = static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0;

        return array_map(
            static fn(array $row): array => [
                'linkUid' => $int($row['link_uid'] ?? 0),
                'beUser' => $int($row['be_user'] ?? 0),
                'action' => is_string($row['action'] ?? null) ? $row['action'] : '',
                'crdate' => $int($row['crdate'] ?? 0),
            ],
            $rows,
        );
    }
}
