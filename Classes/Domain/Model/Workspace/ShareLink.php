<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

use SensitiveParameter;

/**
 * A share link of one draft (ADR-0001). It holds the hash of the token and
 * never the token itself.
 */
final readonly class ShareLink
{
    public function __construct(
        public int $uid,
        public int $draftUid,
        public string $label,
        public string $tokenPrefix,
        #[SensitiveParameter]
        public string $tokenHash,
        public LinkPermission $permission,
        public int $expires,
        #[SensitiveParameter]
        public string $passphraseHash,
        public int $maxUses,
        public int $useCount,
        public int $createdBy,
        public int $crdate,
        public int $revokedAt,
        public int $revokedBy,
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
            label: $string('label'),
            tokenPrefix: $string('token_prefix'),
            tokenHash: $string('token_hash'),
            permission: LinkPermission::tryFrom($string('permission')) ?? LinkPermission::View,
            expires: $int('expires'),
            passphraseHash: $string('passphrase_hash'),
            maxUses: $int('max_uses'),
            useCount: $int('use_count'),
            createdBy: $int('created_by'),
            crdate: $int('crdate'),
            revokedAt: $int('revoked_at'),
            revokedBy: $int('revoked_by'),
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt > 0;
    }

    public function isExpired(int $now): bool
    {
        return $this->expires <= $now;
    }

    public function isUsedUp(): bool
    {
        return $this->maxUses > 0 && $this->useCount >= $this->maxUses;
    }

    public function hasPassphrase(): bool
    {
        return $this->passphraseHash !== '';
    }

    /**
     * Whether the link can open a review session now.
     */
    public function isUsable(int $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now) && !$this->isUsedUp();
    }
}
