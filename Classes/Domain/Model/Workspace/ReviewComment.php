<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

/**
 * A comment, a suggestion or a logged edit on a draft (ADR-0001, section 2).
 * Everything a reviewer wrote is plain text and stays plain text until it is
 * escaped on output.
 */
final readonly class ReviewComment
{
    public function __construct(
        public int $uid,
        public int $draftUid,
        public int $linkUid,
        public int $beUser,
        public string $sectionId,
        public string $field,
        public CommentKind $kind,
        public string $authorName,
        public string $body,
        public string $baseText,
        public string $suggestedText,
        public CommentStatus $status,
        public int $resolvedBy,
        public int $resolvedAt,
        public int $crdate,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int = static fn(string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string = static fn(string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

        return new self(
            uid: $int('uid'),
            draftUid: $int('draft_uid'),
            linkUid: $int('link_uid'),
            beUser: $int('be_user'),
            sectionId: $string('section_id'),
            field: $string('field'),
            kind: CommentKind::tryFrom($string('kind')) ?? CommentKind::Comment,
            authorName: $string('author_name'),
            body: $string('body'),
            baseText: $string('base_text'),
            suggestedText: $string('suggested_text'),
            status: CommentStatus::tryFrom($string('status')) ?? CommentStatus::Open,
            resolvedBy: $int('resolved_by'),
            resolvedAt: $int('resolved_at'),
            crdate: $int('crdate'),
        );
    }

    /**
     * Written by a reviewer through a link, not by a backend user.
     */
    public function isFromReviewer(): bool
    {
        return $this->beUser === 0;
    }
}
