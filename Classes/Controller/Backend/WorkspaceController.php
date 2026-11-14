<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Controller\Backend;

use Netresearch\NrLandingpage\Domain\Model\ParentPageVerdict;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewComment;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\ApprovalService;
use Netresearch\NrLandingpage\Service\Workspace\AuditRepository;
use Netresearch\NrLandingpage\Service\Workspace\CommentRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\PlainText;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The backend module "Content Workspace" (ADR-0001): the list of drafts the
 * user may see, and one draft with its share links.
 *
 * Read-only: every change is a POST to {@see WorkspaceAjaxController}.
 */
final class WorkspaceController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
        private readonly DraftAccessGuard $draftAccess,
        private readonly ShareLinkRepository $links,
        private readonly AuditRepository $audit,
        private readonly CommentRepository $comments,
        private readonly WorkspaceSettings $settings,
        private readonly BackendAccessGuard $accessGuard,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $names = $this->userNames();

        $rows = [];
        foreach ($this->draftAccess->visibleDrafts() as $draft) {
            $rows[] = [
                'uid' => $draft->uid,
                'title' => $draft->title,
                'status' => $draft->status->value,
                'version' => $draft->version,
                'sectionCount' => count($draft->sections),
                'owner' => $names[$draft->owner] ?? '',
                'updated' => $draft->tstamp,
                'showUrl' => (string) $this->uriBuilder->buildUriFromRoute('nr_landingpage_workspace.show', ['draft' => $draft->uid]),
            ];
        }

        $moduleTemplate->assign('drafts', $rows);
        $moduleTemplate->assign('wizardUrl', (string) $this->uriBuilder->buildUriFromRoute('nr_landingpage'));

        return $moduleTemplate->renderResponse('Backend/Workspace/Index');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $rawUid = $request->getQueryParams()['draft'] ?? 0;
        $draft = $this->draftAccess->load(is_numeric($rawUid) ? (int) $rawUid : 0);
        if ($draft === null) {
            // A draft the user may not see is not found, as one that does not exist.
            return new HtmlResponse('Not found', 404);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $names = $this->userNames();
        $now = time();
        $mayShare = $this->draftAccess->mayShare($draft);

        $this->pageRenderer->addInlineSettingArray('NrLandingpage', [
            'workspace' => [
                'draftUid' => $draft->uid,
                'ajaxUrls' => [
                    'createLink' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_create_link'),
                    'revokeLink' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_revoke_link'),
                    'approve' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_approve'),
                    'resetApproval' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_reset_approval'),
                    'acceptSuggestion' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_accept_suggestion'),
                    'rejectSuggestion' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_workspace_reject_suggestion'),
                ],
            ],
        ]);
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:nr_landingpage/Resources/Private/Language/locallang.xlf', 'workspace.', '');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-landingpage/workspace.js');

        $moduleTemplate->assignMultiple([
            'draft' => $this->draftView($draft, $names),
            'sections' => $this->sectionViews($draft, $names),
            'links' => array_map(fn(ShareLink $link): array => $this->linkView($link, $now, $names), $this->links->findByDraft($draft->uid)),
            'audit' => $this->auditViews($draft, $names),
            'mayShare' => $mayShare,
            'approval' => $this->approvalView($draft, $names, $now),
            'linksEnabled' => $this->settings->isEnabled(),
            'defaultLifetimeDays' => $this->settings->defaultLifetimeDays(),
            'maxLifetimeDays' => $this->settings->maxLifetimeDays(),
            'indexUrl' => (string) $this->uriBuilder->buildUriFromRoute('nr_landingpage_workspace'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Workspace/Show');
    }

    /**
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private function draftView(WorkspaceDraft $draft, array $names): array
    {
        return [
            'uid' => $draft->uid,
            'title' => $draft->title,
            'status' => $draft->status->value,
            'version' => $draft->version,
            'owner' => $names[$draft->owner] ?? '',
            'updated' => $draft->tstamp,
        ];
    }

    /**
     * The sections as plain text, the same text a reviewer sees, with the
     * comments, suggestions and logged edits of each.
     *
     * @param array<int, string> $names
     * @return list<array{id: string, name: string, ctype: string, header: string, subheader: string, lines: list<string>, comments: list<array<string, mixed>>}>
     */
    private function sectionViews(WorkspaceDraft $draft, array $names): array
    {
        $bySection = [];
        foreach ($this->comments->findByDraft($draft->uid) as $comment) {
            $bySection[$comment->sectionId][] = $this->commentView($comment, $names, $draft);
        }

        $views = [];
        foreach ($draft->sections as $section) {
            $text = static fn(string $key): string => is_string($section[$key] ?? null) ? $section[$key] : '';
            $id = $text('id');
            $views[] = [
                'id' => $id,
                'name' => $text('section'),
                'ctype' => $text('ctype'),
                'header' => $text('header'),
                'subheader' => $text('subheader'),
                'lines' => self::lines(PlainText::fromHtml($text('bodytext'))),
                'comments' => $bySection[$id] ?? [],
            ];
        }

        return $views;
    }

    /**
     * What the approval box shows: the state, the parent page, and what the
     * current user may do.
     *
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private function approvalView(WorkspaceDraft $draft, array $names, int $now): array
    {
        $parentTitle = $draft->parentPageUid > 0 ? $this->accessGuard->readablePageTitle($draft->parentPageUid) : null;

        return [
            'status' => $draft->status->value,
            'isOpen' => $draft->isDraft(),
            'version' => $draft->version,
            'parentPageUid' => $draft->parentPageUid,
            'parentPageTitle' => $parentTitle ?? '',
            'pageFields' => $this->pageFieldViews($draft),
            'approvedBy' => $names[$draft->approvedBy] ?? '',
            'approvedAt' => $draft->approvedAt,
            'createdPageUid' => $draft->createdPageUid,
            'pageUrl' => $draft->createdPageUid > 0 && $this->accessGuard->mayReadPage($draft->createdPageUid)
                ? (string) $this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $draft->createdPageUid])
                : '',
            'mayReset' => $draft->status === DraftStatus::Approved
                && $draft->createdPageUid === 0
                && $now - $draft->approvedAt >= ApprovalService::RESET_AFTER
                && $this->accessGuard->checkParentPage($draft->parentPageUid) === ParentPageVerdict::Allowed,
        ];
    }

    /**
     * The page fields the new page gets, as the approver should see them.
     *
     * @return list<array{name: string, value: string}>
     */
    private function pageFieldViews(WorkspaceDraft $draft): array
    {
        $views = [];
        foreach ($draft->pageFields as $name => $value) {
            if (is_string($value) && $value !== '') {
                $views[] = ['name' => (string) $name, 'value' => $value];
            }
        }

        return $views;
    }

    /**
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private function commentView(ReviewComment $comment, array $names, WorkspaceDraft $draft): array
    {
        $isComment = $comment->kind === CommentKind::Comment;
        $canResolve = $draft->isDraft() && $comment->kind === CommentKind::Suggestion && $comment->status === CommentStatus::Open;
        $stale = false;
        if ($canResolve) {
            $current = null;
            foreach ($draft->sections as $section) {
                if (($section['id'] ?? null) === $comment->sectionId) {
                    $current = ReviewService::fieldText($section, $comment->field);
                }
            }
            $stale = $current === null || $current !== $comment->baseText;
        }

        return [
            'uid' => $comment->uid,
            'canResolve' => $canResolve,
            'stale' => $stale,
            'baseLines' => self::lines($comment->baseText),
            'author' => $comment->isFromReviewer() ? $comment->authorName : ($names[$comment->beUser] ?? $comment->authorName),
            'fromReviewer' => $comment->isFromReviewer(),
            'kind' => $comment->kind->value,
            'isComment' => $isComment,
            'field' => $comment->field,
            'status' => $comment->status->value,
            'lines' => self::lines($isComment ? $comment->body : $comment->suggestedText),
            'when' => $comment->crdate,
        ];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $text)), static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private function linkView(ShareLink $link, int $now, array $names): array
    {
        $state = match (true) {
            $link->isRevoked() => 'revoked',
            $link->isExpired($now) => 'expired',
            $link->isUsedUp() => 'usedUp',
            default => 'active',
        };

        return [
            'uid' => $link->uid,
            'label' => $link->label,
            'permission' => $link->permission->value,
            // Eight characters tell the links apart; they restore nothing.
            'shortId' => substr($link->tokenPrefix, 0, 8),
            'state' => $state,
            'active' => $state === 'active',
            'expires' => $link->expires,
            'hasPassphrase' => $link->hasPassphrase(),
            'maxUses' => $link->maxUses,
            'useCount' => $link->useCount,
            'createdBy' => $names[$link->createdBy] ?? '',
            'created' => $link->crdate,
            'revokedBy' => $names[$link->revokedBy] ?? '',
        ];
    }

    /**
     * @param array<int, string> $names
     * @return list<array{action: string, user: string, linkUid: int, when: int}>
     */
    private function auditViews(WorkspaceDraft $draft, array $names): array
    {
        return array_map(
            static fn(array $entry): array => [
                'action' => $entry['action'],
                'user' => $names[$entry['beUser']] ?? '',
                'linkUid' => $entry['linkUid'],
                'when' => $entry['crdate'],
            ],
            $this->audit->findByDraft($draft->uid, 20),
        );
    }

    /**
     * @return array<int, string>
     */
    private function userNames(): array
    {
        $names = [];
        foreach (BackendUtility::getUserNames('username') as $uid => $row) {
            if (is_array($row) && is_string($row['username'] ?? null)) {
                $names[(int) $uid] = $row['username'];
            }
        }

        return $names;
    }
}
