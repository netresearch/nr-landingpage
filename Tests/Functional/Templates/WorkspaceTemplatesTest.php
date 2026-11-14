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

    /**
     * @return array<string, mixed>
     */
    private function showVariables(bool $mayShare): array
    {
        return [
            'draft' => ['uid' => 1, 'title' => self::PAYLOAD, 'status' => 'draft', 'version' => 3, 'owner' => self::PAYLOAD, 'updated' => 1800000000],
            'sections' => [[
                'id' => 'aaaaaaaaaaaaaaaa',
                'name' => self::PAYLOAD,
                'header' => self::PAYLOAD,
                'subheader' => self::PAYLOAD,
                'lines' => [self::PAYLOAD, 'second line'],
                'comments' => [[
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
