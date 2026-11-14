<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Who may see, share and approve which draft (ADR-0001, section 8).
 *
 * A user without access to a draft gets the answer "not found", the same as
 * for a draft that does not exist: `load()` returns null for both.
 */
class DraftAccessGuard
{
    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly BackendAccessGuard $accessGuard,
    ) {}

    /**
     * The draft, when the current user may see it, else null.
     */
    public function load(int $uid): ?WorkspaceDraft
    {
        $draft = $this->drafts->findByUid($uid);

        return $draft !== null && $this->maySee($draft) ? $draft : null;
    }

    /**
     * @return list<WorkspaceDraft>
     */
    public function visibleDrafts(): array
    {
        $user = $this->backendUser();
        if ($user === null || !$this->accessGuard->mayUseWorkspace()) {
            return [];
        }

        return $this->drafts->findVisible($this->userUid($user), $user->isAdmin(), $this->groupUids($user));
    }

    /**
     * Administrators, the owner, and users in a group the draft is shared
     * with, all of them with access to the workspace module.
     */
    public function maySee(WorkspaceDraft $draft): bool
    {
        $user = $this->backendUser();
        if ($user === null || !$this->accessGuard->mayUseWorkspace()) {
            return false;
        }

        return $user->isAdmin()
            || $draft->owner === $this->userUid($user)
            || array_intersect($draft->beGroups, $this->groupUids($user)) !== [];
    }

    /**
     * Creating and revoking links hands content to people outside: the owner
     * and administrators only, not everyone who sees the draft through a group.
     */
    public function mayShare(WorkspaceDraft $draft): bool
    {
        $user = $this->backendUser();
        if ($user === null || !$this->maySee($draft)) {
            return false;
        }

        return $user->isAdmin() || $draft->owner === $this->userUid($user);
    }

    public function currentUserUid(): int
    {
        $user = $this->backendUser();

        return $user === null ? 0 : $this->userUid($user);
    }

    private function userUid(BackendUserAuthentication $user): int
    {
        $uid = $user->getUserId();

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * @return list<int>
     */
    private function groupUids(BackendUserAuthentication $user): array
    {
        $groups = [];
        foreach ($user->userGroupsUID as $uid) {
            if (is_numeric($uid) && (int) $uid > 0) {
                $groups[] = (int) $uid;
            }
        }

        return $groups;
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
