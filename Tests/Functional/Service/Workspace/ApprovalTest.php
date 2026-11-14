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
use Netresearch\NrLandingpage\Service\Workspace\ApprovalContentPolicy;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
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

    /** No content type and no page field list: the template leaves everything to the approval's own limits. */
    private const OPEN_TEMPLATE = 2;

    /** Lists html and text, and the page fields description and fe_group. */
    private const HTML_TEMPLATE = 3;

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
    #[DataProvider('commandsThatCopyAPageIntoAnotherLanguage')]
    public function aTranslationOfAMarkedPageDoesNotCarryTheMarker(string $command): void
    {
        $this->actAs(1);
        $GLOBALS['LANG'] = $this->container(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $directory = Environment::getConfigPath() . '/sites/workspace-test';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'https://workspace.example/'",
            'languages:',
            '  - title: English',
            '    enabled: true',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '  - title: Deutsch',
            '    enabled: true',
            '    languageId: 1',
            "    base: '/de/'",
            '    locale: de_DE.UTF-8',
            '',
        ]));
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->getCache('core')->flush();
        $cacheManager->getCache('runtime')->flush();
        $marked = $this->pageWithMarker($this->draftUid . ':realrealrealrealrealrealreal0000');

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], ['pages' => [$marked => [$command => 1]]]);
        $handler->process_cmdmap();

        self::assertSame([], $handler->errorLog);
        $translations = $this->uidsBelow(self::OPEN_PARENT, 'l10n_parent', $marked);
        self::assertCount(1, $translations, 'the command made one translation');
        self::assertSame('', $this->row('pages', $translations[0])['tx_nrlandingpage_workspace_ref'], 'a translation does not carry the marker');
        self::assertSame($this->draftUid . ':realrealrealrealrealrealreal0000', $this->row('pages', $marked)['tx_nrlandingpage_workspace_ref'], 'the original keeps it');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandsThatCopyAPageIntoAnotherLanguage(): iterable
    {
        yield 'localize' => ['localize'];
        yield 'copyToLanguage' => ['copyToLanguage'];
    }

    // ------------------------------- what an approval creates (owner's content, approver's rights)

    /**
     * @return iterable<string, array{string}>
     */
    public static function contentTypesAnOwnerMustNotSmuggleIn(): iterable
    {
        yield 'html' => ['html'];
        yield 'list' => ['list'];
        yield 'shortcut' => ['shortcut'];
        yield 'plugin' => ['menu_pages'];
        yield 'form' => ['form_formframework'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('contentTypesAnOwnerMustNotSmuggleIn')]
    public function aTemplateWithoutAContentTypeListRefusesEverythingTheWizardDoesNotProduce(string $ctype): void
    {
        $draftUid = $this->makeDraftOf(self::OPEN_TEMPLATE, [], [
            $this->section('hero', 'text', 'Fine', '<p>ok</p>'),
            $this->section('embed', $ctype, 'Embed', '<script>alert(1)</script>'),
        ]);
        $this->actAs(1);
        $draft = $this->draftOf($draftUid);

        try {
            $this->approval()->approve($draftUid, $draft->version, self::OPEN_PARENT, self::NOW);
            self::fail('A section of the type "' . $ctype . '" was approved.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::INVALID, $e->reason);
            self::assertStringContainsString('Section 2 (embed)', $e->getMessage(), 'the refusal names the section');
        }

        $after = $this->draftOf($draftUid);
        self::assertSame(DraftStatus::Draft, $after->status, 'a refusal leaves the draft as it was');
        self::assertSame($draft->version, $after->version);
        self::assertSame(0, $after->approvedBy);
        self::assertSame('', $after->approvalAttempt);
        self::assertSame([], $this->pagesOfDraft($draftUid));
        self::assertNotContains('approved', array_column($this->audit()->findByDraft($draftUid), 'action'));
    }

    #[Test]
    public function aTemplateWithoutAContentTypeListApprovesTheTypesTheWizardProduces(): void
    {
        $draftUid = $this->makeDraftOf(self::OPEN_TEMPLATE, [], [
            $this->section('a', 'text', 'A', '<p>a</p>'),
            $this->section('b', 'textmedia', 'B', '<p>b</p>'),
            $this->section('c', 'textpic', 'C', '<p>c</p>'),
        ]);
        $this->actAs(1);

        $pageUid = $this->approval()->approve($draftUid, $this->draftOf($draftUid)->version, self::OPEN_PARENT, self::NOW);

        self::assertSame(['A' => 'text', 'B' => 'textmedia', 'C' => 'textpic'], $this->typesByHeader($pageUid));
    }

    #[Test]
    public function aContentTypeTheTemplateListNamesIsApprovedAndOneItDoesNotNameIsTurnedIntoText(): void
    {
        $draftUid = $this->makeDraftOf(self::HTML_TEMPLATE, [], [
            $this->section('embed', 'html', 'Embed', '<p>x</p>'),
            $this->section('list', 'list', 'List', '<p>y</p>'),
        ]);
        $this->actAs(1);

        $pageUid = $this->approval()->approve($draftUid, $this->draftOf($draftUid)->version, self::OPEN_PARENT, self::NOW);

        self::assertSame(['Embed' => 'html', 'List' => 'text'], $this->typesByHeader($pageUid), 'the allowlist of the template decides: html is its own, list is not');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageFieldsAnOwnerMustNotSet(): iterable
    {
        yield 'content_from_pid' => ['content_from_pid', '7'];
        yield 'fe_group' => ['fe_group', '-2'];
        yield 'shortcut' => ['shortcut', '3'];
        yield 'url' => ['url', 'https://evil.example/'];
        yield 'nav_hide' => ['nav_hide', '1'];
        yield 'target' => ['target', '_blank'];
        yield 'extendToSubpages' => ['extendToSubpages', '1'];
    }

    #[Test]
    #[DataProvider('pageFieldsAnOwnerMustNotSet')]
    public function aTemplateWithoutAPageFieldListRefusesAFieldOutsideTheMetadataSet(string $field, string $value): void
    {
        $draftUid = $this->makeDraftOf(self::OPEN_TEMPLATE, ['description' => 'd', $field => $value], [$this->section('a', 'text', 'A', '<p>a</p>')]);
        $this->actAs(1);
        $draft = $this->draftOf($draftUid);

        try {
            $this->approval()->approve($draftUid, $draft->version, self::OPEN_PARENT, self::NOW);
            self::fail('The page field "' . $field . '" was approved.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::INVALID, $e->reason);
            self::assertStringContainsString('"' . $field . '"', $e->getMessage());
        }

        $after = $this->draftOf($draftUid);
        self::assertSame(DraftStatus::Draft, $after->status);
        self::assertSame($draft->version, $after->version);
        self::assertSame([], $this->pagesOfDraft($draftUid));
    }

    #[Test]
    public function aTemplateWithoutAPageFieldListApprovesTheMetadataSetAndIgnoresReservedFields(): void
    {
        $fields = ['description' => 'Desc', 'abstract' => 'Abs', 'keywords' => 'a, b', 'author' => 'Ann', 'subtitle' => 'Sub', 'nav_title' => 'Nav', 'slug' => '/forged', 'doktype' => '254', 'hidden' => '0'];
        $draftUid = $this->makeDraftOf(self::OPEN_TEMPLATE, $fields, [$this->section('a', 'text', 'A', '<p>a</p>')]);
        $this->actAs(1);

        $pageUid = $this->approval()->approve($draftUid, $this->draftOf($draftUid)->version, self::OPEN_PARENT, self::NOW);

        $page = $this->row('pages', $pageUid);
        self::assertSame('Desc', $page['description']);
        self::assertSame('Abs', $page['abstract']);
        self::assertSame('a, b', $page['keywords']);
        self::assertSame('Ann', $page['author']);
        self::assertSame('Sub', $page['subtitle']);
        self::assertSame('Nav', $page['nav_title']);
        self::assertSame(1, (int) $page['doktype'], 'a reserved field is dropped, as it always was');
        self::assertSame(1, (int) $page['hidden']);
        self::assertNotSame('/forged', $page['slug']);
    }

    #[Test]
    public function aPageFieldTheTemplateListNamesIsAppliedAndOneItDoesNotNameIsDropped(): void
    {
        $draftUid = $this->makeDraftOf(self::HTML_TEMPLATE, ['description' => 'Desc', 'fe_group' => '-2', 'keywords' => 'k'], [$this->section('a', 'text', 'A', '<p>a</p>')]);
        $this->actAs(1);

        $pageUid = $this->approval()->approve($draftUid, $this->draftOf($draftUid)->version, self::OPEN_PARENT, self::NOW);

        $page = $this->row('pages', $pageUid);
        self::assertSame('Desc', $page['description']);
        self::assertSame('-2', (string) $page['fe_group'], 'the template list is the template admin\'s decision');
        self::assertSame('', (string) $page['keywords'], 'a field outside the template list is dropped');
    }

    // ------------------------------------------------ a delete DataHandler refuses (editlock)

    #[Test]
    public function anApproverWithoutAdminRightsCreatesThePage(): void
    {
        $this->actAsAnEditor(3);

        $pageUid = $this->approval()->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, self::NOW);

        self::assertSame($this->draftUid . ':' . $this->draft()->approvalAttempt, $this->row('pages', $pageUid)['tx_nrlandingpage_workspace_ref']);
        self::assertCount(1, $this->contentOf($pageUid));
    }

    #[Test]
    public function aPageThatCannotBeDeletedKeepsTheApprovalAndItsMarkerAndIsRecordedInTheAudit(): void
    {
        $this->actAsAnEditor(3);
        $version = $this->draft()->version;
        // The creation fails after the page exists, and the page is edit-locked meanwhile: the undo cannot delete it.
        $locking = $this->creator(failAfterCreation: true, afterCreation: fn() => $this->lockPagesOfDraft(1));

        try {
            $this->approval($locking)->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW);
            self::fail('The creation did not fail.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::FAILED, $e->reason);
            self::assertStringContainsString('could not be removed', $e->getMessage());
        }

        $after = $this->draft();
        self::assertSame(DraftStatus::Approved, $after->status, 'the draft is not reopened over a page that still exists');
        self::assertNotSame('', $after->approvalAttempt, 'the marker stays, so the page can be found');
        self::assertCount(1, $this->pagesOfDraft(), 'the page is still there');
        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('approval_cleanup_failed', $actions);
        self::assertNotContains('approval_reverted', $actions);

        // A second approval cannot make a duplicate while the first stands.
        try {
            $this->approval()->approve($this->draftUid, $after->version, self::OPEN_PARENT, self::NOW + 1);
            self::fail('A draft that stands approved was approved again.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }
        self::assertCount(1, $this->pagesOfDraft());

        // Once the page can be deleted, the reset finds it by its marker and removes it.
        $this->lockPagesOfDraft(0);
        $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);
        self::assertSame([], $this->pagesOfDraft());
        self::assertSame(DraftStatus::Draft, $this->draft()->status);
    }

    #[Test]
    public function aResetThatCannotDeleteThePageRefusesAndKeepsTheApproval(): void
    {
        $this->actAsAnEditor(3);
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 3, 'crashedcrashedcrashedcrashed0000', self::NOW));
        $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000');
        $this->lockPagesOfDraft(1);

        try {
            $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);
            self::fail('The reset went on over a page it could not delete.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::FAILED, $e->reason);
        }

        $after = $this->draft();
        self::assertSame(DraftStatus::Approved, $after->status);
        self::assertSame('crashedcrashedcrashedcrashed0000', $after->approvalAttempt);
        self::assertCount(1, $this->pagesOfDraft());
        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('approval_cleanup_failed', $actions);
        self::assertNotContains('approval_reset', $actions);

        $this->lockPagesOfDraft(0);
        $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER + 1);
        self::assertSame([], $this->pagesOfDraft());
        self::assertSame(DraftStatus::Draft, $this->draft()->status);
    }

    #[Test]
    public function aResetOverAPageThatDataHandlerOnlyDiscardsRefusesAndKeepsTheApproval(): void
    {
        $this->actAsAnEditor(3);
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 3, 'crashedcrashedcrashedcrashed0000', self::NOW));
        $pageUid = $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000');
        // A page that lives in a workspace: from Live, DataHandler turns its delete into a discard and reports no error.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['t3ver_wsid' => 1, 't3ver_state' => 1], ['uid' => $pageUid]);

        try {
            $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);
            self::fail('The reset went on over a page that is still there.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::FAILED, $e->reason);
        }

        self::assertSame(0, (int) $this->row('pages', $pageUid)['deleted'], 'the page is still there');
        $after = $this->draft();
        self::assertSame(DraftStatus::Approved, $after->status);
        self::assertSame('crashedcrashedcrashedcrashed0000', $after->approvalAttempt);
        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('approval_cleanup_failed', $actions);
        self::assertNotContains('approval_reset', $actions);
    }

    #[Test]
    public function aResetRefusesToDeleteAPageThatGotAForeignChildPage(): void
    {
        $this->actAsAnEditor(3);
        $drafts = $this->container(DraftRepository::class);
        self::assertTrue($drafts->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, 3, 'crashedcrashedcrashedcrashed0000', self::NOW));
        $partial = $this->pageWithMarker($this->draftUid . ':crashedcrashedcrashedcrashed0000');
        // An editor moved an unrelated page (with content) below the stalled approval's page.
        $foreign = $this->pageWithMarker('');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['pid' => $partial], ['uid' => $foreign]);

        try {
            $this->approval()->reset($this->draftUid, self::NOW + ApprovalService::RESET_AFTER);
            self::fail('The reset deleted a page that holds a foreign page.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::FAILED, $e->reason);
        }

        self::assertSame(0, (int) $this->row('pages', $partial)['deleted'], 'the marked page stays');
        self::assertSame(0, (int) $this->row('pages', $foreign)['deleted'], 'the foreign page survives');
        self::assertCount(1, $this->contentOf($foreign), 'and so does its content');
        $after = $this->draft();
        self::assertSame(DraftStatus::Approved, $after->status);
        self::assertSame('crashedcrashedcrashedcrashed0000', $after->approvalAttempt);
        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('approval_cleanup_failed', $actions);
        self::assertNotContains('approval_reset', $actions);
    }

    #[Test]
    public function anApprovalThatLostTheDraftAndDeletesItsPageLeavesAnAuditRow(): void
    {
        $this->actAs(1);
        $version = $this->draft()->version;
        $drafts = $this->container(DraftRepository::class);
        $racing = $this->creator(afterCreation: function () use ($drafts): void {
            $current = $drafts->findByUid($this->draftUid);
            self::assertNotNull($current);
            self::assertTrue($drafts->revert($this->draftUid, $current->approvalAttempt, self::NOW + 5));
        });

        try {
            $this->approval($racing)->approve($this->draftUid, $version, self::OPEN_PARENT, self::NOW);
            self::fail('The stale attempt finished.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }

        self::assertSame([], $this->pagesOfDraft());
        self::assertContains('approval_conflict', array_column($this->audit()->findByDraft($this->draftUid), 'action'));
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
        self::assertContains('suggestion_stale', array_column($this->audit()->findByDraft($this->draftUid), 'action'));
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
    public function aSuggestionOfAnApprovedDraftCannotBeRejected(): void
    {
        $this->actAs(1);
        $suggestion = $this->suggest('header', 'H', 'A better heading');
        $this->approval()->approve($this->draftUid, $this->draft()->version, self::OPEN_PARENT, self::NOW);

        try {
            $this->suggestions()->reject($this->draftUid, $suggestion, self::NOW + 2);
            self::fail('A suggestion was rejected after the approval.');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(WorkspaceRefusedException::CONFLICT, $e->reason);
        }
        self::assertSame(CommentStatus::Open, $this->container(CommentRepository::class)->findInDraft($suggestion, $this->draftUid)?->status);
        self::assertNotContains('suggestion_rejected', array_column($this->audit()->findByDraft($this->draftUid), 'action'));
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
            new ApprovalContentPolicy(),
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

    /**
     * A draft of the given template with the given page fields and sections, owned by user 2.
     *
     * @param array<string, string> $pageFields
     * @param list<array<string, mixed>> $sections
     */
    private function makeDraftOf(int $templateUid, array $pageFields, array $sections): int
    {
        return $this->container(DraftRepository::class)->insert(2, 'Owned content', $templateUid, 0, [], $pageFields, $sections, [1], self::NOW);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name, string $ctype, string $header, string $bodytext): array
    {
        return ['id' => bin2hex(random_bytes(8)), 'section' => $name, 'ctype' => $ctype, 'header' => $header, 'subheader' => '', 'bodytext' => $bodytext, 'imageUid' => 0, 'imageorient' => 0, 'colPos' => 0, 'animation' => []];
    }

    /**
     * @return array<string, mixed> the content type of each element, by header
     */
    private function typesByHeader(int $pageUid): array
    {
        $types = array_column($this->contentOf($pageUid), 'CType', 'header');
        ksort($types);

        return $types;
    }

    private function draftOf(int $uid): WorkspaceDraft
    {
        $draft = $this->container(DraftRepository::class)->findByUid($uid);
        self::assertNotNull($draft);

        return $draft;
    }

    /**
     * Log in as a user of group 1 ("Editors"), who is not an administrator and
     * may create pages and the content types text, textmedia and html below the
     * open parent: the rights of an approver who is no administrator.
     */
    private function actAsAnEditor(int $uid): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', [
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'pages,tt_content,sys_file_reference',
            'pagetypes_select' => '1',
            'db_mountpoints' => '1',
        ], ['uid' => 1]);
        $this->writeEnglishSite();
        $user = $this->actAs($uid);
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:text,tt_content:CType:textmedia,tt_content:CType:html';
    }

    /**
     * A site with one language above the open parent. DataHandler of TYPO3 13.4 fills the language of a new
     * record for a user who is no administrator from the site language list of the page (there is no site,
     * no language, and the access check fails with "languageField ... not found in testing record").
     * Every real installation has a site above the pages an editor creates.
     */
    private function writeEnglishSite(): void
    {
        $directory = Environment::getConfigPath() . '/sites/approval-editor';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'https://workspace.example/'",
            'languages:',
            '  - title: English',
            '    enabled: true',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '',
        ]));
    }

    /**
     * Lock or unlock the pages of the draft: DataHandler refuses to delete an edit-locked page for anybody but an administrator.
     */
    private function lockPagesOfDraft(int $locked): void
    {
        foreach ($this->pagesOfDraft() as $uid) {
            $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['editlock' => $locked], ['uid' => $uid]);
        }
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
    private function pagesOfDraft(?int $draftUid = null): array
    {
        $draftUid ??= $this->draftUid;
        $builder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid')->from('pages')
            ->where(
                $builder->expr()->like('tx_nrlandingpage_workspace_ref', $builder->createNamedParameter($draftUid . ':%')),
                $builder->expr()->eq('deleted', $builder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->executeQuery()->fetchFirstColumn();

        return array_values(array_map(static fn(mixed $uid): int => (int) $uid, $rows));
    }

    /**
     * @return list<int>
     */
    private function uidsBelow(int $pid, string $column, int $value): array
    {
        $builder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();

        return array_values(array_map(static fn(mixed $uid): int => (int) $uid, $builder->select('uid')->from('pages')
            ->where(
                $builder->expr()->eq('pid', $builder->createNamedParameter($pid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                $builder->expr()->eq($column, $builder->createNamedParameter($value, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->executeQuery()->fetchFirstColumn()));
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
