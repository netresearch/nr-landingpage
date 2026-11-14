<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use SensitiveParameter;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

/**
 * Issues and revokes share links (ADR-0001, sections 3 and 4).
 *
 * Who may do it is decided by the caller with DraftAccessGuard::mayShare();
 * this class enforces the properties of the link itself: a required, bounded
 * expiry, a hashed passphrase of a minimum length, a bounded usage limit.
 */
class ShareLinkService
{
    public const PASSPHRASE_MIN_LENGTH = 8;

    public const PASSPHRASE_MAX_LENGTH = 200;

    public const MAX_USES_LIMIT = 1000;

    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly AuditRepository $audit,
        private readonly WorkspaceSettings $settings,
        private readonly PasswordHashFactory $passwordHashFactory,
    ) {}

    /**
     * Create a link. The returned token is the only time it exists in clear:
     * only its prefix and hash are stored.
     *
     * @return array{uid: int, token: string, expires: int}
     * @throws WorkspaceRefusedException when the draft is not open for sharing or an input is out of bounds
     */
    public function issue(
        WorkspaceDraft $draft,
        LinkPermission $permission,
        int $lifetimeDays,
        string $label,
        #[SensitiveParameter]
        string $passphrase,
        int $maxUses,
        int $byUser,
        int $now,
    ): array {
        if (!$draft->isDraft()) {
            throw new WorkspaceRefusedException('A draft that is approved takes no new links.', 1799400201);
        }
        $max = $this->settings->maxLifetimeDays();
        if ($lifetimeDays < 1 || $lifetimeDays > $max) {
            throw new WorkspaceRefusedException(sprintf('A link lives between 1 and %d days.', $max), 1799400202);
        }
        if ($maxUses < 0 || $maxUses > self::MAX_USES_LIMIT) {
            throw new WorkspaceRefusedException(sprintf('The usage limit is between 1 and %d, or empty for no limit.', self::MAX_USES_LIMIT), 1799400203);
        }
        if ($passphrase !== '' && (mb_strlen($passphrase) < self::PASSPHRASE_MIN_LENGTH || mb_strlen($passphrase) > self::PASSPHRASE_MAX_LENGTH)) {
            throw new WorkspaceRefusedException(sprintf('A passphrase has %d to %d characters.', self::PASSPHRASE_MIN_LENGTH, self::PASSPHRASE_MAX_LENGTH), 1799400204);
        }
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '');

        $passphraseHash = $passphrase === ''
            ? ''
            : $this->passwordHashFactory->getDefaultHashInstance('FE')->getHashedPassword($passphrase)
                ?? throw new WorkspaceRefusedException('The passphrase could not be hashed.', 1799400207);

        $issued = ShareToken::generate();
        $expires = $now + $lifetimeDays * 86400;
        $uid = $this->links->insert(
            $draft->uid,
            $label,
            $issued['prefix'],
            $issued['hash'],
            $permission,
            $expires,
            $passphraseHash,
            $maxUses,
            $byUser,
            $now,
        );
        $this->audit->record($draft->uid, $uid, $byUser, AuditAction::LinkCreated, $now);

        return ['uid' => $uid, 'token' => $issued['token'], 'expires' => $expires];
    }

    /**
     * Revoke a link of the given draft. A link of another draft is not found.
     *
     * @throws WorkspaceRefusedException when the link does not belong to the draft or is revoked already
     */
    public function revoke(WorkspaceDraft $draft, int $linkUid, int $byUser, int $now): ShareLink
    {
        $link = $this->links->findByUid($linkUid);
        if ($link === null || $link->draftUid !== $draft->uid) {
            throw new WorkspaceRefusedException('The link was not found.', 1799400205);
        }
        if (!$this->links->revoke($link->uid, $byUser, $now)) {
            throw new WorkspaceRefusedException('The link is revoked already.', 1799400206);
        }
        $this->audit->record($draft->uid, $link->uid, $byUser, AuditAction::LinkRevoked, $now);

        return $link;
    }
}
