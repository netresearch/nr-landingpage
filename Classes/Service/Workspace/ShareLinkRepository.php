<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use SensitiveParameter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Storage of the share links (ADR-0001). Only the token's prefix and hash
 * are stored.
 */
class ShareLinkRepository
{
    public const TABLE = 'tx_nrlandingpage_workspace_link';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function insert(
        int $draftUid,
        string $label,
        string $tokenPrefix,
        #[SensitiveParameter]
        string $tokenHash,
        LinkPermission $permission,
        int $expires,
        #[SensitiveParameter]
        string $passphraseHash,
        int $maxUses,
        int $createdBy,
        int $now,
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'draft_uid' => $draftUid,
            'label' => mb_substr($label, 0, 255),
            'token_prefix' => $tokenPrefix,
            'token_hash' => $tokenHash,
            'permission' => $permission->value,
            'expires' => $expires,
            'passphrase_hash' => $passphraseHash,
            'max_uses' => max(0, $maxUses),
            'use_count' => 0,
            'created_by' => $createdBy,
            'revoked_at' => 0,
            'revoked_by' => 0,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function findByUid(int $uid): ?ShareLink
    {
        return $this->findOneBy('uid', $uid);
    }

    public function findByPrefix(string $prefix): ?ShareLink
    {
        return $prefix === '' ? null : $this->findOneBy('token_prefix', $prefix);
    }

    /**
     * @return list<ShareLink> newest first
     */
    public function findByDraft(int $draftUid): array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('draft_uid', $builder->createNamedParameter($draftUid, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): ShareLink => ShareLink::fromRow($row), $rows);
    }

    /**
     * Revoke a link that is not revoked yet. False when it was already.
     */
    public function revoke(int $uid, int $byUser, int $now): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ?, revoked_by = ?, tstamp = ? WHERE uid = ? AND revoked_at = 0',
            [$now, $byUser, $now, $uid],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
        );

        return $affected === 1;
    }

    /**
     * Take one use of a link, in one statement: two simultaneous entries
     * cannot exceed the limit. False when the link is revoked, expired or
     * used up.
     */
    public function consumeUse(int $uid, int $now): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET use_count = use_count + 1, tstamp = ?'
            . ' WHERE uid = ? AND revoked_at = 0 AND expires > ? AND (max_uses = 0 OR use_count < max_uses)',
            [$now, $uid, $now],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
        );

        return $affected === 1;
    }

    private function findOneBy(string $column, int|string $value): ?ShareLink
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq($column, $builder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? ShareLink::fromRow($row) : null;
    }
}
