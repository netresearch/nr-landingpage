<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

use SensitiveParameter;

/**
 * A review session of one share link (ADR-0001, section 5). It holds the
 * hash of the session id and the name the reviewer gave.
 */
final readonly class ReviewSession
{
    public function __construct(
        public int $uid,
        public int $linkUid,
        #[SensitiveParameter]
        public string $sessionHash,
        public string $reviewerName,
        public int $expires,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int = static fn(string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string = static fn(string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

        return new self($int('uid'), $int('link_uid'), $string('session_hash'), $string('reviewer_name'), $int('expires'));
    }

    public function isExpired(int $now): bool
    {
        return $this->expires <= $now;
    }
}
