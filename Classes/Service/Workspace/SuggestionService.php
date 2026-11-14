<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewComment;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * An editor accepts or rejects a reviewer's suggestion (ADR-0001, section 7).
 *
 * Accepting goes through the draft's one write rule (open draft, the version
 * just read) and writes the text through `PlainText::toBodytextHtml()` like
 * every other writer. It applies only when the field's text is still the text
 * the suggestion was made against.
 */
class SuggestionService
{
    public function __construct(
        private readonly DraftAccessGuard $access,
        private readonly DraftRepository $drafts,
        private readonly CommentRepository $comments,
        private readonly AuditRepository $audit,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @throws WorkspaceRefusedException
     */
    public function accept(int $draftUid, int $commentUid, int $now): void
    {
        $user = $this->access->currentUserUid();
        $this->resolveDraft($draftUid);
        $suggestion = $this->suggestion($draftUid, $commentUid);

        $stale = false;
        $this->connectionPool->getConnectionForTable(DraftRepository::TABLE)->transactional(
            function () use ($draftUid, $suggestion, $user, $now, &$stale): void {
                $draft = $this->drafts->findByUid($draftUid);
                if ($draft === null || !$draft->isDraft()) {
                    throw new WorkspaceRefusedException('The draft is closed.', 1799600001, WorkspaceRefusedException::CONFLICT);
                }
                $section = $this->section($draft, $suggestion->sectionId);
                if ($section === null || ReviewService::fieldText($section, $suggestion->field) !== $suggestion->baseText) {
                    $stale = true;

                    return;
                }
                $sections = [];
                foreach ($draft->sections as $candidate) {
                    if (($candidate['id'] ?? null) === $suggestion->sectionId) {
                        $candidate[$suggestion->field] = $suggestion->field === 'bodytext'
                            ? PlainText::toBodytextHtml($suggestion->suggestedText)
                            : $suggestion->suggestedText;
                    }
                    $sections[] = $candidate;
                }
                if (!$this->drafts->updateSections($draftUid, $sections, $draft->version, $now)) {
                    throw new WorkspaceRefusedException('The draft changed in the meantime. Reload the page.', 1799600002, WorkspaceRefusedException::CONFLICT);
                }
                if (!$this->comments->resolve($suggestion->uid, CommentStatus::Accepted, $user, $now)) {
                    throw new WorkspaceRefusedException('The suggestion was settled in the meantime.', 1799600003, WorkspaceRefusedException::CONFLICT);
                }
                $this->audit->record($draftUid, $suggestion->linkUid, $user, AuditAction::SuggestionAccepted, $now);
            },
        );

        if ($stale) {
            // Nothing was written: the text moved on. Say so, on the suggestion.
            if ($this->comments->resolve($suggestion->uid, CommentStatus::Stale, $user, $now)) {
                $this->audit->record($draftUid, $suggestion->linkUid, $user, AuditAction::SuggestionStale, $now);
            }

            throw new WorkspaceRefusedException('The text changed since the suggestion was made, so it was marked outdated and nothing was applied.', 1799600004, WorkspaceRefusedException::CONFLICT);
        }
    }

    /**
     * @throws WorkspaceRefusedException
     */
    public function reject(int $draftUid, int $commentUid, int $now): void
    {
        $user = $this->access->currentUserUid();
        $draft = $this->resolveDraft($draftUid);
        if (!$draft->isDraft()) {
            throw new WorkspaceRefusedException('The draft is closed.', 1799600009, WorkspaceRefusedException::CONFLICT);
        }
        $suggestion = $this->suggestion($draftUid, $commentUid);

        if (!$this->comments->resolve($suggestion->uid, CommentStatus::Rejected, $user, $now)) {
            throw new WorkspaceRefusedException('The suggestion was settled in the meantime.', 1799600005, WorkspaceRefusedException::CONFLICT);
        }
        $this->audit->record($draftUid, $suggestion->linkUid, $user, AuditAction::SuggestionRejected, $now);
    }

    /**
     * @throws WorkspaceRefusedException
     */
    private function resolveDraft(int $draftUid): WorkspaceDraft
    {
        $draft = $this->access->load($draftUid);
        if ($draft === null) {
            throw new WorkspaceRefusedException('Not found.', 1799600006, WorkspaceRefusedException::NOT_FOUND);
        }

        return $draft;
    }

    /**
     * An open suggestion of this draft; a comment of another draft is not found.
     *
     * @throws WorkspaceRefusedException
     */
    private function suggestion(int $draftUid, int $commentUid): ReviewComment
    {
        $comment = $this->comments->findInDraft($commentUid, $draftUid);
        if ($comment === null || $comment->kind !== CommentKind::Suggestion) {
            throw new WorkspaceRefusedException('Not found.', 1799600007, WorkspaceRefusedException::NOT_FOUND);
        }
        if ($comment->status !== CommentStatus::Open) {
            throw new WorkspaceRefusedException('The suggestion is settled already.', 1799600008, WorkspaceRefusedException::CONFLICT);
        }

        return $comment;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function section(WorkspaceDraft $draft, string $sectionId): ?array
    {
        foreach ($draft->sections as $section) {
            if ($sectionId !== '' && ($section['id'] ?? null) === $sectionId) {
                return $section;
            }
        }

        return null;
    }
}
