<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service\Workspace;

use Closure;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\AnimationLoaderService;
use Netresearch\NrLandingpage\Service\AnimationScriptBuilder;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\SectionNormalizer;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLandingpage\Service\Workspace\ApprovalService;
use Netresearch\NrLandingpage\Service\Workspace\AuditRepository;
use Netresearch\NrLandingpage\Service\Workspace\CommentRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewAuthenticator;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Service\Workspace\SuggestionService;
use Netresearch\NrLandingpage\Service\Workspace\WorkspacePages;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Approval and the creation of the page (ADR-0001, sections 7 and 9), with the
 * real database and DataHandler.
 */
#[CoversClass(ApprovalService::class)]
#[CoversClass(SuggestionService::class)]
#[CoversClass(WorkspacePages::class)]
final class ApprovalTest extends AbstractWorkspaceTestCase
{
    /** "Open to everybody" in Fixtures/pages.csv: any user may create pages below it. */
    private const OPEN_PARENT = 6;

    /** "Restricted": nobody but its owner may. */
    private const RESTRICTED_PARENT = 2;

    private int $draftUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Workspace/template.csv');
        $this->draftUid = $this->makeDraft(2, [1], 'Spring campaign');
    }

    #[Test]
    public function approvingCreatesThePageFromTheStoredDraftAndRecordsIt(): void
    {
        $this->actAs(1);
        $draft = $this->draft();

        $pageUid = $this->approval()->approve($this->draftUid, $draft->version, self::OPEN_PARENT, self::NOW);

        $after = $this->draft();
        self::assertSame(DraftStatus::Created, $after->status);
        self::assertSame($pageUid, $after->createdPageUid);
        self::assertSame(1, $after->approvedBy);
        self::assertSame(self::NOW, $after->approvedAt);
        self::assertSame($draft->version, $after->approvedVersion);
        self::assertSame(self::OPEN_PARENT, $after->parentPageUid);

        $page = $this->row('pages', $pageUid);
        self::assertSame(self::OPEN_PARENT, (int) $page['pid']);
        self::assertSame('Spring campaign', $page['title']);
        self::assertSame(1, (int) $page['hidden'], 'the template publishes hidden');
        self::assertMatchesRegularExpression('/^' . $this->draftUid . ':[0-9a-f]{32}$/', (string) $page['tx_nrlandingpage_workspace_ref']);
        self::assertSame($this->draftUid . ':' . $after->approvalAttempt, $page['tx_nrlandingpage_workspace_ref'], 'the marker is the draft and the attempt that owns the approval');

        $content = $this->contentOf($pageUid);
        self::assertCount(1, $content);
        self::assertSame('H', $content[0]['header']);
        self::assertSame('<p>b</p>', $content[0]['bodytext']);

        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('approved', $actions);
        self::assertContains('page_created', $actions);
    }

    #[Test]
    public function aDraftThatChangedAfterTheApproverLookedIsNotApprovedAndNothingIsCreated(): void
    {
        $this->actAs(1);
        $shown = $this->draft();
        // A reviewer with an edit link changes a text after the editor's page was rendered.
        $this->editThroughALink('H', 'Changed by a reviewer');

        try {
            $this->approval()->approve($this->draftUid, $shown->version, self::OPEN_PARENT, self::NOW);
            self::fail('A changed draft was approved.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }

        self::assertSame(DraftStatus::Draft, $this->draft()->status);
        self::assertSame([], $this->pagesOfDraft());
    }

    #[Test]
    public function aDraftApprovedOnceCannotBeApprovedAgain(): void
    {
        $this->actAs(1);
        $version = $this->draft()->version;
        $this->approval()->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW);

        foreach ([$version, $this->draft()->version] as $try) {
            try {
                $this->approval()->approve($this->draftUid, $try, self::OPEN_PARENT, self::NOW + 1);
                self::fail('An approved draft was approved again.');
            } catch (WorkspaceRefusedException $e) {
                self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
            }
        }
        self::assertCount(1, $this->pagesOfDraft(), 'exactly one page');
    }

    #[Test]
    public function aUserWithoutTheRightToCreatePagesBelowTheParentCannotApprove(): void
    {
        $this->actAs(2);
        $version = $this->draft()->version;

        foreach ([self::RESTRICTED_PARENT, 99999, 0] as $parent) {
            try {
                $this->approval()->approve($this->draftUid, $version, $parent, self::NOW);
                self::fail('Approved below page ' . $parent);
            } catch (WorkspaceRefusedException $e) {
                self::assertSame(WorkspaceRefusedException::INVALID, $e->reason, 'page ' . $parent);
            }
        }
        self::assertSame(DraftStatus::Draft, $this->draft()->status);
        self::assertSame([], $this->pagesOfDraft());
    }

    #[Test]
    public function aUserWhoMayNotSeeTheDraftGetsNotFound(): void
    {
        $this->actAs(4);

        try {
            $this->approval()->approve($this->draftUid, 1, self::OPEN_PARENT, self::NOW);
            self::fail('A draft the user may not see was approved.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::NOT_FOUND, $e->reason);
        }
        self::assertSame(DraftStatus::Draft, $this->draft()->status);
    }

    #[Test]
    public function aFailedCreationTakesTheApprovalBackRemovesThePageAndAllowsAnotherTry(): void
    {
        $this->actAs(1);
        $version = $this->draft()->version;
        $failing = $this->approval($this->creator(failAfterCreation: true));

        try {
            $failing->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW);
            self::fail('The creation did not fail.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::FAILED, $e->reason);
        }

        $after = $this->draft();
        self::assertSame(DraftStatus::Draft, $after->status);
        self::assertSame('', $after->approvalAttempt);
        self::assertSame(0, $after->approvedBy);
        self::assertSame(0, $after->approvedAt);
        self::assertSame(0, $after->createdPageUid);
        self::assertSame([], $this->pagesOfDraft(), 'the page made before the failure is gone');
        self::assertContains('approval_reverted', array_column($this->audit()->findByDraft($this->draftUid), 'action'));

        // The draft is open again, at a newer version, and one more try creates exactly one page.
        try {
            $this->approval()->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW + 1);
            self::fail('The stale version was accepted.');
        } catch (WorkspaceRefusedException) {
            // the version moved with the revert: the approver looks again
        }
        $this->approval()->approve($this->draftUid, $after->version, self::OPEN_PARENT, self::NOW + 2);
        self::assertCount(1, $this->pagesOfDraft());
    }

    #[Test]
    public function anAttemptThatLostTheDraftToAResetNeitherFinishesNorRevertsAndRemovesItsPage(): void
    {
        $this->actAs(1);
        $version = $this->draft()->version;
        $drafts = $this->container(DraftRepository::class);
        // While the page is being made, the approval is reset under it and the draft approved again.
        $racing = $this->creator(afterCreation: function () use ($drafts): void {
            $current = $drafts->findByUid($this->draftUid);
            self::assertNotNull($current);
            self::assertTrue($drafts->revert($this->draftUid, $current->approvalAttempt, self::NOW + 5));
            $reopened = $drafts->findByUid($this->draftUid);
            self::assertNotNull($reopened);
            self::assertTrue($drafts->approve($this->draftUid, $reopened->version, self::OPEN_PARENT, 1, 'newerattemptnewerattemptnewer00', self::NOW + 6));
        });

        try {
            $this->approval($racing)->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW);
            self::fail('The stale attempt finished.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }

        $after = $this->draft();
        self::assertSame(DraftStatus::Approved, $after->status, 'the newer approval stands');
        self::assertSame('newerattemptnewerattemptnewer00', $after->approvalAttempt);
        self::assertSame(0, $after->createdPageUid);
        self::assertSame([], $this->pagesOfDraft(), 'the stale attempt deleted its own page');
    }

    #[Test]
    public function aStaleAttemptCannotRevertOrFinishANewerApproval(): void
    {
        $drafts = $this->container(DraftRepository::class);
        $version = $this->draft()->version;
        self::assertTrue($drafts->approve($this->draftUid, $version, self::OPEN_PARENT, 1, 'currentcurrentcurrentcurrentcu00', self::NOW));

        self::assertFalse($drafts->revert($this->draftUid, 'staleattempt', self::NOW + 1));
        self::assertFalse($drafts->finalize($this->draftUid, 'staleattempt', 99, self::NOW + 1));
        self::assertFalse($drafts->revert($this->draftUid, '', self::NOW + 1));
        self::assertFalse($drafts->finalize($this->draftUid, '', 99, self::NOW + 1));

        $draft = $this->draft();
        self::assertSame(DraftStatus::Approved, $draft->status);
        self::assertSame('currentcurrentcurrentcurrentcu00', $draft->approvalAttempt);
    }

    #[Test]
    public function aNewApprovalGetsANewAttemptAndTheOldOnesAreVoid(): void
    {
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 1, 'firstfirstfirstfirstfirstfirst00', self::NOW));
        self::assertTrue($drafts->revert($this->draftUid, 'firstfirstfirstfirstfirstfirst00', self::NOW + 1));
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 1, 'secondsecondsecondsecondsecond00', self::NOW + 2));

        self::assertFalse($drafts->finalize($this->draftUid, 'firstfirstfirstfirstfirstfirst00', 5, self::NOW + 3));
        self::assertTrue($drafts->finalize($this->draftUid, 'secondsecondsecondsecondsecond00', 5, self::NOW + 3));
        self::assertSame(5, $this->draft()->createdPageUid);
    }

    #[Test]
    public function anApprovalWithoutAPageCanBeResetAfterTenMinutesAndTakesItsPartialPageWith(): void
    {
        $this->actAs(1);
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 1, 'crashedcrashedcrashedcrashed0000', self::NOW));
        // The crash left a page with the marker behind.
        $partial = $this->creator()->createLandingPage($this->template(), self::OPEN_PARENT, 'Partial', '', [], [['section' => '', 'ctype' => 'text', 'header' => 'x', 'subheader' => '', 'bodytext' => '<p>x</p>', 'imageUid' => 0, 'imageorient' => 0, 'colPos' => 0]], null, [], $this->draftUid . ':crashedcrashedcrashedcrashed0000');
        self::assertCount(1, $this->pagesOfDraft());

        try {
            $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER - 1);
            self::fail('The reset came too early.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }
        self::assertCount(1, $this->pagesOfDraft());

        $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);

        self::assertSame([], $this->pagesOfDraft());
        self::assertNotNull($partial);
        $draft = $this->draft();
        self::assertSame(DraftStatus::Draft, $draft->status);
        self::assertSame('', $draft->approvalAttempt);
        self::assertContains('approval_reset', array_column($this->audit()->findByDraft($this->draftUid), 'action'));
    }

    #[Test]
    public function aResetDeletesOnlyThePageOfItsExactAttemptBelowTheApprovedParent(): void
    {
        $this->actAs(1);
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 1, 'crashedcrashedcrashedcrashed0000', self::NOW));
        $own = $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000');
        $otherAttempt = $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000x');
        $elsewhere = $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['pid' => 1], ['uid' => $elsewhere]);

        $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);

        self::assertSame(1, (int) $this->row('pages', $own)['deleted'], 'the page of this attempt is removed');
        self::assertSame(0, (int) $this->row('pages', $otherAttempt)['deleted'], 'a marker that only starts like it survives');
        self::assertSame(0, (int) $this->row('pages', $elsewhere)['deleted'], 'a page below another parent survives');
    }

    #[Test]
    public function aDataHandlerWriteToTheMarkerIsIgnoredAndACopyDoesNotCarryIt(): void
    {
        $this->actAs(1);
        $GLOBALS['LANG'] = $this->container(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $plain = $this->pageWithMarker('');
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['pages' => [$plain => ['tx_nrlandingpage_workspace_ref' => $this->draftUid . ':forged'], 'NEW_forged' => ['pid' => self::OPEN_PARENT, 'title' => 'Forged', 'tx_nrlandingpage_workspace_ref' => $this->draftUid . ':forged']]], []);
        $handler->process_datamap();

        self::assertSame('', $this->row('pages', $plain)['tx_nrlandingpage_workspace_ref'], 'an update cannot set the marker');
        $created = (int) ($handler->substNEWwithIDs['NEW_forged'] ?? 0);
        self::assertGreaterThan(0, $created);
        self::assertSame('', $this->row('pages', $created)['tx_nrlandingpage_workspace_ref'], 'an insert cannot set it either');

        $marked = $this->pageWithMarker($this->draftUid . ':realrealrealrealrealrealreal0000');
        $copier = GeneralUtility::makeInstance(DataHandler::class);
        $copier->start([], ['pages' => [$marked => ['copy' => self::OPEN_PARENT]]]);
        $copier->process_cmdmap();
        $copy = (int) ($copier->copyMappingArray_merged['pages'][$marked] ?? 0);
        self::assertGreaterThan(0, $copy);
        self::assertSame('', $this->row('pages', $copy)['tx_nrlandingpage_workspace_ref'], 'a copied page does not carry the marker');
        self::assertSame($this->draftUid . ':realrealrealrealrealrealreal0000', $this->row('pages', $marked)['tx_nrlandingpage_workspace_ref'], 'the original keeps it');
    }

    #[Test]
    public function aCreatedDraftHasNothingToReset(): void
    {
        $this->actAs(1);
        $this->approval()->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, self::NOW);

        $this->expectException(WorkspaceRefusedException::class);
        $this->approval()->reset($this->draftUid, self::NOW + 100000);
    }

    #[Test]
    public function textChangedThroughALinkReachesThePageEscapedAndThePageIsMadeFromTheStoredDraft(): void
    {
        $payload = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $this->editThroughALink('H', $payload);
        $this->editThroughALink('b', "Paragraph one\n\nParagraph two " . $payload);
        $this->actAs(1);

        $pageUid = $this->approval()->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, self::NOW);

        $content = $this->contentOf($pageUid);
        self::assertSame($payload, $content[0]['header'], 'a header is stored as the plain text it is, and escaped when it is output');
        self::assertStringNotContainsString('<script', (string) $content[0]['bodytext']);
        self::assertStringNotContainsString('<img', (string) $content[0]['bodytext']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $content[0]['bodytext']);
        self::assertStringStartsWith('<p>Paragraph one</p>', (string) $content[0]['bodytext']);
    }

    #[Test]
    public function acceptingASuggestionAppliesItOnceAndRecordsIt(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $version = $this->draft()->version;

        $this->suggestions()->accept($this->draftUid, $suggestion, self::NOW + 1);

        self::assertSame('A better heading', $this->draft()->sections[0]['header']);
        self::assertSame($version + 1, $this->draft()->version);
        $comment = $this->container(CommentRepository::class)->findInDraft($suggestion, $this->draftUid);
        self::assertNotNull($comment);
        self::assertSame(CommentStatus::Accepted, $comment->status);
        self::assertSame(1, $comment->resolvedBy);
        self::assertContains('suggestion_accepted', array_column($this->audit()->findByDraft($this->draftUid), 'action'));

        $this->expectException(WorkspaceRefusedException::class);
        $this->suggestions()->accept($this->draftUid, $suggestion, self::NOW + 2);
    }

    #[Test]
    public function aSuggestionForBodyTextIsWrittenAsEscapedParagraphs(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('bodytext', 'b', "New <b>text</b>\n\nSecond");

        $this->suggestions()->accept($this->draftUid, $suggestion, self::NOW + 1);

        self::assertSame("<p>New &lt;b&gt;text&lt;/b&gt;</p>\n<p>Second</p>", $this->draft()->sections[0]['bodytext']);
    }

    #[Test]
    public function aSuggestionMadeAgainstTextThatChangedIsMarkedOutdatedAndWritesNothing(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $this->editThroughALink('H', 'Changed meanwhile');
        $version = $this->draft()->version;

        try {
            $this->suggestions()->accept($this->draftUid, $suggestion, self::NOW + 1);
            self::fail('A suggestion against changed text was applied.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }

        self::assertSame('Changed meanwhile', $this->draft()->sections[0]['header']);
        self::assertSame($version, $this->draft()->version);
        self::assertSame(CommentStatus::Stale, $this->container(CommentRepository::class)->findInDraft($suggestion, $this->draftUid)?->status);
    }

    #[Test]
    public function rejectingASuggestionChangesNothingButItsState(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $version = $this->draft()->version;

        $this->suggestions()->reject($this->draftUid, $suggestion, self::NOW + 1);

        self::assertSame('H', $this->draft()->sections[0]['header']);
        self::assertSame($version, $this->draft()->version);
        self::assertSame(CommentStatus::Rejected, $this->container(CommentRepository::class)->findInDraft($suggestion, $this->draftUid)?->status);
        self::assertContains('suggestion_rejected', array_column($this->audit()->findByDraft($this->draftUid), 'action'));
    }

    #[Test]
    public function aSuggestionOfAnotherDraftIsNotFoundAndAnApprovedDraftTakesNoAcceptance(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $otherDraft = $this->makeDraft(2, [], 'Other');

        try {
            $this->suggestions()->accept($otherDraft, $suggestion, self::NOW + 1);
            self::fail('A suggestion of another draft was accepted through this one.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::NOT_FOUND, $e->reason);
        }
        self::assertSame('H', $this->draft()->sections[0]['header']);

        $this->approval()->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, self::NOW);
        try {
            $this->suggestions()->accept($this->draftUid, $suggestion, self::NOW + 2);
            self::fail('A suggestion was accepted after the approval.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }
        self::assertSame('H', $this->draft()->sections[0]['header']);
    }

    #[Test]
    public function aUserWhoMayNotSeeTheDraftCannotSettleItsSuggestions(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $this->actAs(4);

        foreach (['accept', 'reject'] as $method) {
            try {
                $this->suggestions()->{$method}($this->draftUid, $suggestion, self::NOW + 1);
                self::fail($method . ' worked for a user who may not see the draft');
            } catch (WorkspaceRefusedException $e) {
                self::assertSame(WorkspaceRefusedException::NOT_FOUND, $e->reason);
            }
        }
        self::assertSame(CommentStatus::Open, $this->container(CommentRepository::class)->findInDraft($suggestion, $this->draftUid)?->status);
    }

    // ---------------------------------------------------------------- helpers

    private function approval(?PageCreatorService $creator = null): ApprovalService
    {
        $imageSearch = $this->createMock(\Netresearch\NrLandingpage\Service\ImageSearchService::class);
        $imageSearch->method('isReadable')->willReturn(true);

        return new ApprovalService(
            $this->container(DraftAccessGuard::class),
            $this->container(BackendAccessGuard::class),
            $this->container(DraftRepository::class),
            $this->container(AuditRepository::class),
            $this->container(TemplateService::class),
            new SectionNormalizer($imageSearch, new \Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer()),
            $creator ?? $this->creator(),
            $this->container(WorkspacePages::class),
        );
    }

    private function suggestions(): SuggestionService
    {
        return $this->container(SuggestionService::class);
    }

    /**
     * The real page creator, optionally failing after the page exists (a crash
     * in a later pass) or running a callback at that point.
     */
    private function creator(bool $failAfterCreation = false, ?Closure $afterCreation = null): PageCreatorService
    {
        return new class (
            $this->container(EventDispatcherInterface::class),
            $this->container(ResourceFactory::class),
            $this->container(AnimationLoaderService::class),
            $this->container(AnimationScriptBuilder::class),
            $failAfterCreation,
            $afterCreation,
        ) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $events,
                ResourceFactory $resources,
                AnimationLoaderService $animationLoader,
                AnimationScriptBuilder $animationBuilder,
                private readonly bool $failAfterCreation,
                private readonly ?Closure $afterCreation,
            ) {
                parent::__construct($events, $resources, $animationLoader, $animationBuilder);
            }

            public function createLandingPage(Template $template, int $parentPageId, string $title, string $slug, array $pageFields, array $contentSections, ?\Netresearch\NrLandingpage\Domain\Model\GenerationContext $generationContext = null, array $animations = [], string $workspaceRef = ''): array
            {
                $result = parent::createLandingPage($template, $parentPageId, $title, $slug, $pageFields, $contentSections, $generationContext, $animations, $workspaceRef);
                if ($this->afterCreation !== null) {
                    ($this->afterCreation)();
                }
                if ($this->failAfterCreation) {
                    throw new RuntimeException('simulated failure after the page was made');
                }

                return $result;
            }
        };
    }

    private function template(): Template
    {
        $template = $this->container(TemplateService::class)->loadByUid(1);
        self::assertNotNull($template);

        return $template;
    }

    private function draft(): WorkspaceDraft
    {
        $draft = $this->container(DraftRepository::class)->findByUid($this->draftUid);
        self::assertNotNull($draft);

        return $draft;
    }

    private function audit(): AuditRepository
    {
        return $this->container(AuditRepository::class);
    }

    /**
     * A reviewer with an edit link changes one field, through the real review service.
     */
    private function editThroughALink(string $baseText, string $newText): void
    {
        $context = $this->reviewContext(LinkPermission::Edit);
        $field = $baseText === 'b' ? 'bodytext' : 'header';
        $this->container(ReviewService::class)->edit($context, 'aaaaaaaaaaaaaaaa', $field, $baseText, $newText, self::NOW);
    }

    private function suggest(string $field, string $baseText, string $text): int
    {
        $context = $this->reviewContext(LinkPermission::Comment);
        $this->container(ReviewService::class)->suggest($context, 'aaaaaaaaaaaaaaaa', $field, $text, self::NOW);
        $rows = $this->container(CommentRepository::class)->findByDraft($this->draftUid);
        $last = end($rows);
        self::assertNotFalse($last);
        self::assertSame($baseText, $last->baseText);

        return $last->uid;
    }

    private function reviewContext(LinkPermission $permission): \Netresearch\NrLandingpage\Service\Workspace\Review\ReviewContext
    {
        $draft = $this->draft();
        $token = $this->container(ShareLinkService::class)->issue($draft, $permission, 7, '', '', 0, 2, self::NOW)['token'];
        $authenticator = $this->container(ReviewAuthenticator::class);
        $outcome = $authenticator->enter($token, '', 'Reviewer', '192.0.2.' . random_int(1, 250), self::NOW);
        $context = $authenticator->resolve($outcome->sessionId, self::NOW);
        self::assertNotNull($context);

        return $context;
    }

    /**
     * A page below the open parent carrying the marker, made the way the approval makes it.
     */
    private function pageWithMarker(string $marker): int
    {
        $result = $this->creator()->createLandingPage($this->template(), self::OPEN_PARENT, 'Marked', '', [], [['section' => '', 'ctype' => 'text', 'header' => 'x', 'subheader' => '', 'bodytext' => '<p>x</p>', 'imageUid' => 0, 'imageorient' => 0, 'colPos' => 0]], null, [], $marker);

        return $result['pageUid'];
    }

    /**
     * @return list<int>
     */
    private function pagesOfDraft(): array
    {
        $builder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid')->from('pages')
            ->where(
                $builder->expr()->like('tx_nrlandingpage_workspace_ref', $builder->createNamedParameter($this->draftUid . ':%')),
                $builder->expr()->eq('deleted', $builder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->executeQuery()->fetchFirstColumn();

        return array_values(array_map(static fn(mixed $uid): int => (int) $uid, $rows));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        // A QueryBuilder without restrictions: Connection::select() hides hidden and deleted rows.
        $builder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('*')->from($table)->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentOf(int $pageUid): array
    {
        $builder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $builder->getRestrictions()->removeAll();

        return $builder->select('*')->from('tt_content')
            ->where(
                $builder->expr()->eq('pid', $builder->createNamedParameter($pageUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                $builder->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function container(string $class): object
    {
        $service = $this->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
