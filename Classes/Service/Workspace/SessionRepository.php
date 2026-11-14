<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewSession;
use SensitiveParameter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The server-side review sessions (ADR-0001, section 5). The session id
 * lives only in the reviewer's cookie; the table holds its SHA-256.
 */
class SessionRepository
{
    public const TABLE = 'tx_nrlandingpage_workspace_session';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function insert(int $linkUid, #[SensitiveParameter] string $sessionHash, string $reviewerName, int $expires, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'link_uid' => $linkUid,
            'session_hash' => $sessionHash,
            'reviewer_name' => mb_substr($reviewerName, 0, 255),
            'expires' => $expires,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function findByHash(#[SensitiveParameter] string $sessionHash): ?ReviewSession
    {
        if ($sessionHash === '') {
            return null;
        }
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('session_hash', $builder->createNamedParameter($sessionHash)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? ReviewSession::fromRow($row) : null;
    }

    public function delete(#[SensitiveParameter] string $sessionHash): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['session_hash' => $sessionHash]);
    }

    /**
     * Remove the sessions that ended; called when a new one opens, so no job
     * is needed.
     */
    public function deleteExpired(int $now): void
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $builder->delete(self::TABLE)
            ->where($builder->expr()->lte('expires', $builder->createNamedParameter($now, Connection::PARAM_INT)))
            ->executeStatement();
    }
}
