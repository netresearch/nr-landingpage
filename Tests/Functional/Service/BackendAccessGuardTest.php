<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\Domain\Model\ParentPageVerdict;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(BackendAccessGuard::class)]
final class BackendAccessGuardTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    private BackendAccessGuard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->subject = $this->get(BackendAccessGuard::class);
    }

    #[Test]
    public function adminMayUseEverything(): void
    {
        $this->setUpBackendUser(1);

        self::assertTrue($this->subject->mayUseWizard());
        self::assertTrue($this->subject->mayEditTemplates());
        self::assertTrue($this->subject->mayReadPage(1));
        self::assertTrue($this->subject->mayCreatePageBelow(1));
    }

    #[Test]
    public function editorWithoutPermissionsMayUseNothing(): void
    {
        $this->setUpBackendUser(2);

        self::assertFalse($this->subject->mayUseWizard());
        self::assertFalse($this->subject->mayEditTemplates());
        self::assertFalse($this->subject->mayReadPage(1));
        self::assertFalse($this->subject->mayCreatePageBelow(1));
    }

    #[Test]
    public function editorGrantedTheModuleMayUseTheWizard(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->groupData['modules'] = 'web,' . BackendAccessGuard::MODULE_IDENTIFIER;

        self::assertTrue($this->subject->mayUseWizard());
        self::assertFalse($this->subject->mayEditTemplates());
    }

    #[Test]
    public function editorGrantedTheTemplateTableMayEditTemplates(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->groupData['tables_modify'] = BackendAccessGuard::TEMPLATE_TABLE;

        self::assertTrue($this->subject->mayEditTemplates());
        self::assertFalse($this->subject->mayUseWizard());
    }

    #[Test]
    public function pagePermissionsFollowTheWebMountAndPagePermissions(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['db_mountpoints'] = '1';
        $backendUser->fetchGroupData();

        // Page 1 grants "show" to everybody, page 2 grants nothing to others.
        self::assertTrue($this->subject->mayReadPage(1));
        self::assertFalse($this->subject->mayCreatePageBelow(1));
        self::assertFalse($this->subject->mayReadPage(2));
        self::assertFalse($this->subject->mayReadPage(0));
    }

    #[Test]
    public function adminMayCreateBelowAPageAndAFolder(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(1));
        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(4));
        // Page 8 grants nothing to the admin's user, group or everybody.
        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(8));
    }

    #[Test]
    public function theOwningGroupMayCreatePagesWhereOnlyItIsGrantedIt(): void
    {
        $editor = $this->setUpBackendUser(2);
        $editor->user['db_mountpoints'] = '1';
        $editor->fetchGroupData();
        $editor->groupData['tables_modify'] = 'pages';

        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(7));
    }

    #[Test]
    public function aUserOutsideTheOwningGroupMayNotCreatePagesThere(): void
    {
        $user = $this->setUpBackendUser(3);
        $user->user['db_mountpoints'] = '1';
        $user->fetchGroupData();
        $user->groupData['tables_modify'] = 'pages';

        self::assertSame(ParentPageVerdict::NoPermission, $this->subject->checkParentPage(7));
    }

    #[Test]
    public function aMissingOrDeletedPageIsNotFound(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(ParentPageVerdict::NotFound, $this->subject->checkParentPage(0));
        self::assertSame(ParentPageVerdict::NotFound, $this->subject->checkParentPage(5));
        self::assertSame(ParentPageVerdict::NotFound, $this->subject->checkParentPage(999));
    }

    #[Test]
    public function aPageTypeThatHoldsNoSubpagesIsRefused(): void
    {
        $this->setUpBackendUser(1);
        $this->get(PageDoktypeRegistry::class)->add(123, ['allowedTables' => 'sys_category']);

        self::assertSame(ParentPageVerdict::NoSubpages, $this->subject->checkParentPage(3));
    }

    #[Test]
    public function anEditorGetsTheVerdictOfPagePermissionAndWebMount(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['db_mountpoints'] = '1';
        $backendUser->fetchGroupData();
        $backendUser->groupData['tables_modify'] = 'pages';

        // Page 1: "show" only. Page 2: nothing for others. Page 6: everything.
        self::assertSame(ParentPageVerdict::NoPermission, $this->subject->checkParentPage(1));
        self::assertSame(ParentPageVerdict::NotFound, $this->subject->checkParentPage(2));
        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(6));
    }

    #[Test]
    public function aPageOutsideTheWebMountIsNotFound(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['db_mountpoints'] = '4';
        $backendUser->fetchGroupData();
        $backendUser->groupData['tables_modify'] = 'pages';

        self::assertSame(ParentPageVerdict::NotFound, $this->subject->checkParentPage(6));
        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(4));
    }

    #[Test]
    public function anEditorWhoMayNotModifyPagesMayNotCreateThem(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['db_mountpoints'] = '1';
        $backendUser->fetchGroupData();

        self::assertSame(ParentPageVerdict::NoPermission, $this->subject->checkParentPage(6));
    }

    #[Test]
    public function anEditorWithoutBackendGroupGetsThePermissionsForEverybody(): void
    {
        // DataHandler grants "create page" through perms_everybody without
        // any group; BackendUserAuthentication::calcPerms() would not.
        $backendUser = $this->setUpBackendUser(3);
        $backendUser->user['db_mountpoints'] = '1';
        $backendUser->fetchGroupData();
        $backendUser->groupData['tables_modify'] = 'pages';

        self::assertSame(ParentPageVerdict::Allowed, $this->subject->checkParentPage(6));
        self::assertSame(ParentPageVerdict::NoPermission, $this->subject->checkParentPage(1));
    }

    #[Test]
    public function pageLabelNamesAReadablePageOnly(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['db_mountpoints'] = '1';
        $backendUser->fetchGroupData();

        self::assertSame('Open to everybody', $this->subject->readablePageTitle(6));
        self::assertNull($this->subject->readablePageTitle(2));
        self::assertNull($this->subject->readablePageTitle(0));
    }
}
