<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewComment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Storage of comments, suggestions and logged edits (ADR-0001).
 */
class CommentRepository
{
    public const TABLE = 'tx_nrlandingpage_workspace_comment';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function insert(
        int $draftUid,
        int $linkUid,
        int $beUser,
        string $sectionId,
        string $field,
        CommentKind $kind,
        string $authorName,
        string $body,
        string $baseText,
        string $suggestedText,
        CommentStatus $status,
        int $now,
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'draft_uid' => $draftUid,
            'link_uid' => $linkUid,
            'be_user' => $beUser,
            'section_id' => $sectionId,
            'field' => $field,
            'kind' => $kind->value,
            'author_name' => mb_substr($authorName, 0, 255),
            'body' => $body,
            'base_text' => $baseText,
            'suggested_text' => $suggestedText,
            'status' => $status->value,
            'resolved_by' => 0,
            'resolved_at' => 0,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return list<ReviewComment> oldest first
     */
    public function findByDraft(int $draftUid): array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('draft_uid', $builder->createNamedParameter($draftUid, Connection::PARAM_INT)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): ReviewComment => ReviewComment::fromRow($row), $rows);
    }

    public function countByDraft(int $draftUid): int
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $count = $builder->count('uid')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('draft_uid', $builder->createNamedParameter($draftUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * A comment of the given draft; one of another draft is not found.
     */
    public function findInDraft(int $uid, int $draftUid): ?ReviewComment
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)),
                $builder->expr()->eq('draft_uid', $builder->createNamedParameter($draftUid, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? ReviewComment::fromRow($row) : null;
    }
}
