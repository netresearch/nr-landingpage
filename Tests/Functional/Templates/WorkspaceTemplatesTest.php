<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Templates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The two module templates of the Content Workspace, rendered the way the
 * module renders them but without the page chrome: they must parse, and
 * everything an editor or a reviewer typed must come out escaped.
 */
#[CoversNothing]
final class WorkspaceTemplatesTest extends FunctionalTestCase
{
    private const PAYLOAD = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Workspace/be_users.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    #[Test]
    public function theListEscapesTitlesAndNames(): void
    {
        $html = $this->render('Index', [
            'drafts' => [['uid' => 1, 'title' => self::PAYLOAD, 'status' => 'draft', 'version' => 1, 'sectionCount' => 2, 'owner' => self::PAYLOAD, 'updated' => 1800000000, 'showUrl' => '/typo3/module/web/nr-landingpage-workspace/show?draft=1']],
            'wizardUrl' => '/typo3/module/web/nr-landingpage',
        ]);

        self::assertStringContainsString('nr-landingpage-workspace-drafts', $html);
        $this->assertEscaped($html);
    }

    #[Test]
    public function theEmptyListSaysHowToMakeADraft(): void
    {
        $html = $this->render('Index', ['drafts' => [], 'wizardUrl' => '/typo3/module/web/nr-landingpage']);

        self::assertStringContainsString('nr-landingpage-workspace-empty', $html);
        self::assertStringContainsString('Save as workspace draft', $html);
    }

    #[Test]
    public function theDraftPageEscapesEverythingAnEditorOrReviewerTyped(): void
    {
        $html = $this->render('Show', $this->showVariables(mayShare: true));

        self::assertStringContainsString('id="ws-link-form"', $html);
        self::assertStringContainsString('id="ws-reveal"', $html);
        self::assertStringContainsString('js-ws-revoke', $html);
        $this->assertEscaped($html);
        // The reveal field is empty: the page itself never carries a link.
        self::assertMatchesRegularExpression('/id="ws-reveal-value"[^>]*\/>/', $html);
        self::assertDoesNotMatchRegularExpression('/id="ws-reveal-value"[^>]*value=/', $html);
    }

    #[Test]
    public function aUserWhoMayNotShareSeesNeitherTheFormNorTheRevokeButtons(): void
    {
        $html = $this->render('Show', $this->showVariables(mayShare: false));

        self::assertStringNotContainsString('id="ws-link-form"', $html);
        self::assertStringNotContainsString('id="ws-reveal"', $html);
        self::assertStringNotContainsString('js-ws-revoke', $html);
    }

    #[Test]
    public function anApprovedDraftOffersNoNewLink(): void
    {
        $variables = $this->showVariables(mayShare: true);
        $variables['draft']['status'] = 'approved';

        self::assertStringNotContainsString('id="ws-link-form"', $this->render('Show', $variables));
    }

    #[Test]
    public function noFormIsOfferedWhileTheReviewPageIsSwitchedOff(): void
    {
        $variables = $this->showVariables(mayShare: true);
        $variables['linksEnabled'] = false;

        self::assertStringNotContainsString('id="ws-link-form"', $this->render('Show', $variables));
    }

    #[Test]
    public function theApprovalBoxBindsToTheVersionShownAndEscapesTheParentTitle(): void
    {
        $html = $this->render('Show', $this->showVariables(mayShare: true));

        self::assertMatchesRegularExpression('/id="ws-approve-form"[^>]*data-version="3"/', $html);
        self::assertStringContainsString('id="ws-approve-submit"', $html);
        self::assertMatchesRegularExpression('/id="ws-parent"[^>]*value="6"/', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('id="ws-approval-reset"', $html);
    }

    #[Test]
    public function theApproverSeesEachSectionsContentTypeAndThePageFieldsEscaped(): void
    {
        $html = $this->render('Show', $this->showVariables(mayShare: true));

        self::assertMatchesRegularExpression('/<code class="ws-section-ctype">&lt;script&gt;alert\(1\)&lt;\/script&gt;[^<]*<\/code>/', $html, 'the content type of the section');
        self::assertStringContainsString('id="ws-page-fields"', $html);
        self::assertMatchesRegularExpression('/<th scope="row">[^<]*fe_group[^<]*<\/th>\s*<td>-2<\/td>/', $html, 'a page field with its value');
        self::assertStringContainsString('Content type', $html);
        $this->assertEscaped($html);
    }

    #[Test]
    public function aDraftWithoutPageFieldsShowsNoPageFieldTable(): void
    {
        $variables = $this->showVariables(mayShare: true);
        $variables['approval']['pageFields'] = [];

        self::assertStringNotContainsString('id="ws-page-fields"', $this->render('Show', $variables));
    }

    #[Test]
    public function aCreatedDraftLinksToItsPageAndOffersNoApproval(): void
    {
        $variables = $this->showVariables(mayShare: true);
        $variables['approval'] = ['status' => 'created', 'isOpen' => false, 'version' => 4, 'parentPageUid' => 6, 'parentPageTitle' => '', 'approvedBy' => 'admin', 'approvedAt' => 1800000000, 'createdPageUid' => 12, 'pageUrl' => '/typo3/module/web/layout?id=12', 'mayReset' => false];

        $html = $this->render('Show', $variables);

        self::assertStringNotContainsString('id="ws-approve-form"', $html);
        self::assertStringContainsString('href="/typo3/module/web/layout?id=12"', $html);
    }

    #[Test]
    public function theResetButtonShowsOnlyForAnApprovalThatStoodLongWithoutAPage(): void
    {
        $variables = $this->showVariables(mayShare: true);
        $variables['approval'] = ['status' => 'approved', 'isOpen' => false, 'version' => 4, 'parentPageUid' => 6, 'parentPageTitle' => '', 'approvedBy' => 'admin', 'approvedAt' => 1800000000, 'createdPageUid' => 0, 'pageUrl' => '', 'mayReset' => false];
        self::assertStringNotContainsString('id="ws-approval-reset"', $this->render('Show', $variables));

        $variables['approval']['mayReset'] = true;
        $html = $this->render('Show', $variables);

        self::assertStringContainsString('id="ws-approval-reset"', $html);
        self::assertStringNotContainsString('id="ws-approve-form"', $html);
    }

    #[Test]
    public function anOpenSuggestionOffersAcceptAndRejectAndAnOutdatedOneOnlyReject(): void
    {
        $html = $this->render('Show', $this->showVariables(mayShare: true));
        self::assertStringContainsString('js-ws-accept', $html);
        self::assertStringContainsString('js-ws-reject', $html);

        $variables = $this->showVariables(mayShare: true);
        $variables['sections'][0]['comments'][0]['stale'] = true;
        $stale = $this->render('Show', $variables);
        self::assertStringNotContainsString('js-ws-accept', $stale);
        self::assertStringContainsString('js-ws-reject', $stale);

        $variables['sections'][0]['comments'][0]['canResolve'] = false;
        $settled = $this->render('Show', $variables);
        self::assertStringNotContainsString('js-ws-accept', $settled);
        self::assertStringNotContainsString('js-ws-reject', $settled);
    }

    /**
     * @return array<string, mixed>
     */
    private function showVariables(bool $mayShare): array
    {
        return [
            'draft' => ['uid' => 1, 'title' => self::PAYLOAD, 'status' => 'draft', 'version' => 3, 'owner' => self::PAYLOAD, 'updated' => 1800000000],
            'sections' => [[
                'id' => 'aaaaaaaaaaaaaaaa',
                'ctype' => '<script>alert(1)</script>',
                'name' => self::PAYLOAD,
                'header' => self::PAYLOAD,
                'subheader' => self::PAYLOAD,
                'lines' => [self::PAYLOAD, 'second line'],
                'comments' => [[
                    'uid' => 9,
                    'canResolve' => true,
                    'stale' => false,
                    'baseLines' => [self::PAYLOAD],
                    'author' => self::PAYLOAD,
                    'fromReviewer' => true,
                    'kind' => 'suggestion',
                    'isComment' => false,
                    'field' => 'header',
                    'status' => 'open',
                    'lines' => [self::PAYLOAD],
                    'when' => 1800000000,
                ]],
            ]],
            'links' => [[
                'uid' => 5, 'label' => self::PAYLOAD, 'permission' => 'comment', 'shortId' => 'abcdef01', 'state' => 'active', 'active' => true,
                'expires' => 1800000000, 'hasPassphrase' => true, 'maxUses' => 3, 'useCount' => 1, 'createdBy' => self::PAYLOAD, 'created' => 1800000000, 'revokedBy' => '',
            ]],
            'audit' => [['action' => 'link_created', 'user' => self::PAYLOAD, 'linkUid' => 5, 'when' => 1800000000]],
            'mayShare' => $mayShare,
            'approval' => [
                'status' => 'draft', 'isOpen' => true, 'version' => 3, 'parentPageUid' => 6, 'parentPageTitle' => self::PAYLOAD, 'approvedBy' => '',
                'approvedAt' => 0, 'createdPageUid' => 0, 'pageUrl' => '', 'mayReset' => false,
                'pageFields' => [['name' => 'fe_group', 'value' => '-2'], ['name' => self::PAYLOAD, 'value' => self::PAYLOAD]],
            ],
            'linksEnabled' => true,
            'defaultLifetimeDays' => 7,
            'maxLifetimeDays' => 30,
            'indexUrl' => '/typo3/module/web/nr-landingpage-workspace',
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables): string
    {
        $root = ExtensionManagementUtility::extPath('nr_landingpage') . 'Resources/Private/';
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/nr-landingpage-workspace', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [$root . 'Templates/Backend/Workspace/'],
            layoutRootPaths: [$root . 'Layouts/'],
            request: $request,
            format: 'html',
        ));
        $view->assignMultiple($variables);

        return $view->render($template);
    }

    private function assertEscaped(string $html): void
    {
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
