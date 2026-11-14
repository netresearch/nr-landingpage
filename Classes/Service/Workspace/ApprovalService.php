<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\GenerationContext;
use Netresearch\NrLandingpage\Domain\Model\ParentPageVerdict;
use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\ImageNotReadableException;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\SectionNormalizer;
use Netresearch\NrLandingpage\Service\TemplateService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;

/**
 * Approval of a draft and the creation of its page (ADR-0001, section 9).
 *
 * Only a backend user who sees the draft and may create pages below the
 * parent approves; no share link can. The approval binds to the version the
 * approver was shown, and the page is created from the stored draft.
 */
class ApprovalService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** How long an approval without a page must stand before it can be reset, in seconds. */
    public const RESET_AFTER = 600;

    public function __construct(
        private readonly DraftAccessGuard $access,
        private readonly BackendAccessGuard $accessGuard,
        private readonly DraftRepository $drafts,
        private readonly AuditRepository $audit,
        private readonly TemplateService $templateService,
        private readonly SectionNormalizer $sectionNormalizer,
        private readonly PageCreatorService $pageCreator,
        private readonly WorkspacePages $pages,
    ) {}

    /**
     * Approve the draft at `$version` and create the page below `$parentPageUid`.
     *
     * @return int the uid of the new page
     * @throws WorkspaceRefusedException
     */
    public function approve(int $draftUid, int $version, int $parentPageUid, int $now): int
    {
        $user = $this->access->currentUserUid();
        $draft = $this->access->load($draftUid);
        if ($draft === null) {
            throw new WorkspaceRefusedException('Not found.', 1799600101, WorkspaceRefusedException::NOT_FOUND);
        }
        if (!$draft->isDraft() || $draft->version !== $version) {
            throw $this->changed();
        }
        $this->assertMayCreateBelow($parentPageUid);

        // Everything that can be refused is checked before the draft is approved.
        $template = $this->templateService->loadByUid($draft->templateUid);
        if ($template === null) {
            throw new WorkspaceRefusedException('The template of this draft is not available to you any more.', 1799600102);
        }
        try {
            $sections = $this->sectionNormalizer->normalize($draft->sections, $template);
        } catch (ImageNotReadableException) {
            throw new WorkspaceRefusedException('A section uses an image you may not read.', 1799600103);
        }
        if ($sections === []) {
            throw new WorkspaceRefusedException('The draft has no section.', 1799600104);
        }
        $animations = $this->sectionNormalizer->animations($draft->sections);

        $attempt = bin2hex(random_bytes(16));
        $ref = $draft->uid . ':' . $attempt;
        if (!$this->drafts->approve($draft->uid, $version, $parentPageUid, $user, $attempt, $now)) {
            throw $this->changed();
        }
        $this->audit->record($draft->uid, 0, $user, AuditAction::Approved, $now);

        try {
            $result = $this->pageCreator->createLandingPage(
                $template,
                $parentPageUid,
                $draft->title,
                '',
                $draft->pageFields,
                $sections,
                new GenerationContext(briefingAnswers: $draft->briefingData),
                $animations,
                $ref,
            );
        } catch (Throwable $e) {
            // The class and the code only: the message can carry the content.
            $this->logger?->error('Creating the page of a workspace draft failed', ['draft' => $draft->uid, 'exception' => $e::class, 'code' => $e->getCode()]);
            $this->undo($draft->uid, $attempt, $ref, $user, $now);

            throw new WorkspaceRefusedException('The page could not be created. The approval was taken back and the draft is open again.', 1799600105, WorkspaceRefusedException::FAILED);
        }

        if (!$this->drafts->finalize($draft->uid, $attempt, $result['pageUid'], $now)) {
            // A reset or another approval took the draft over while the page was made.
            $this->pages->delete($this->pages->findByRef($ref));

            throw new WorkspaceRefusedException('The approval was reset while the page was being created; the page was removed.', 1799600106, WorkspaceRefusedException::CONFLICT);
        }
        $this->audit->record($draft->uid, 0, $user, AuditAction::PageCreated, $now);

        return $result['pageUid'];
    }

    /**
     * Reset an approval that never got its page, after RESET_AFTER seconds
     * (a crash between the approval and the creation). Whoever may approve
     * may reset. The pages carrying the draft's marker are deleted first.
     *
     * @throws WorkspaceRefusedException
     */
    public function reset(int $draftUid, int $now): void
    {
        $user = $this->access->currentUserUid();
        $draft = $this->access->load($draftUid);
        if ($draft === null) {
            throw new WorkspaceRefusedException('Not found.', 1799600107, WorkspaceRefusedException::NOT_FOUND);
        }
        if ($draft->status !== DraftStatus::Approved || $draft->createdPageUid > 0) {
            throw new WorkspaceRefusedException('There is no approval to reset.', 1799600108, WorkspaceRefusedException::CONFLICT);
        }
        if ($now - $draft->approvedAt < self::RESET_AFTER) {
            throw new WorkspaceRefusedException('The approval is still in progress. Try again in a few minutes.', 1799600109, WorkspaceRefusedException::CONFLICT);
        }
        $this->assertMayCreateBelow($draft->parentPageUid);

        $this->pages->delete($this->pages->findByRef($draft->uid . ':'));
        if (!$this->drafts->revert($draft->uid, $draft->approvalAttempt, $now)) {
            throw $this->changed();
        }
        $this->audit->record($draft->uid, 0, $user, AuditAction::ApprovalReset, $now);
    }

    /**
     * Whether the current user may approve this draft below the given page.
     */
    public function mayApprove(WorkspaceDraft $draft, int $parentPageUid): bool
    {
        return $draft->isDraft()
            && $this->access->maySee($draft)
            && $parentPageUid > 0
            && $this->accessGuard->checkParentPage($parentPageUid) === ParentPageVerdict::Allowed;
    }

    private function undo(int $draftUid, string $attempt, string $ref, int $user, int $now): void
    {
        $this->pages->delete($this->pages->findByRef($ref));
        if ($this->drafts->revert($draftUid, $attempt, $now)) {
            $this->audit->record($draftUid, 0, $user, AuditAction::ApprovalReverted, $now);
        }
    }

    /**
     * @throws WorkspaceRefusedException
     */
    private function assertMayCreateBelow(int $parentPageUid): void
    {
        if ($parentPageUid <= 0) {
            throw new WorkspaceRefusedException('Choose the page the new page goes below.', 1799600110);
        }
        if ($this->accessGuard->checkParentPage($parentPageUid) !== ParentPageVerdict::Allowed) {
            throw new WorkspaceRefusedException('You may not create a page below that page.', 1799600111);
        }
    }

    private function changed(): WorkspaceRefusedException
    {
        return new WorkspaceRefusedException('The draft changed since you opened it, or it was approved already. Reload the page and look at it again.', 1799600112, WorkspaceRefusedException::CONFLICT);
    }
}
