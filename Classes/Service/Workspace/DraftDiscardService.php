<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;

/**
 * Discards a draft (ADR-0001, section 8): a soft delete of the draft, every
 * link of it revoked and every open review session of those links ended.
 *
 * Who may do it is decided by the caller with DraftAccessGuard::mayDiscard().
 */
class DraftDiscardService
{
    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly ShareLinkRepository $links,
        private readonly SessionRepository $sessions,
        private readonly AuditRepository $audit,
    ) {}

    /**
     * @throws WorkspaceRefusedException when the draft is gone already, or approved and creating its page
     */
    public function discard(WorkspaceDraft $draft, int $byUser, int $now): void
    {
        // The conditional update decides, not the status the caller read: an
        // approval that starts in between is not discarded under its feet.
        if (!$this->drafts->discard($draft->uid, $now)) {
            if ($this->drafts->findByUid($draft->uid) === null) {
                throw new WorkspaceRefusedException('Not found', 1799400301, WorkspaceRefusedException::NOT_FOUND);
            }
            throw new WorkspaceRefusedException('The draft is approved and its page is being created, so it cannot be discarded. Discard it once the page exists, or reset the approval first.', 1799400302, WorkspaceRefusedException::CONFLICT);
        }

        // The review page refuses a deleted draft on every request already;
        // revoking the links and ending the sessions keeps that true without
        // relying on the one check, and shows the links as revoked in the trail.
        foreach ($this->links->findByDraft($draft->uid) as $link) {
            if (!$link->isRevoked() && $this->links->revoke($link->uid, $byUser, $now)) {
                $this->audit->record($draft->uid, $link->uid, $byUser, AuditAction::LinkRevoked, $now);
            }
            $this->sessions->deleteByLink($link->uid);
        }
        $this->audit->record($draft->uid, 0, $byUser, AuditAction::DraftDiscarded, $now);
    }
}
