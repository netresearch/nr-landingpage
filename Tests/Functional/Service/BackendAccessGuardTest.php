<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
}
