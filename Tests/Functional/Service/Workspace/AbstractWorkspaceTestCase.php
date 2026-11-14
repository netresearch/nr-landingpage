<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service\Workspace;

use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Backend users 1 (admin), 2 (owner) and 3 (colleague) are in group 1, user 4
 * is in group 12, user 5 in none. Modules are granted per test.
 */
abstract class AbstractWorkspaceTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Workspace/be_users.csv');
    }

    /**
     * Log in as the given backend user, with or without access to the
     * workspace module.
     */
    protected function actAs(int $uid, bool $withModule = true): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        $user->fetchGroupData();
        if ($withModule && !$user->isAdmin()) {
            $user->groupData['modules'] = 'web,' . BackendAccessGuard::WORKSPACE_MODULE_IDENTIFIER . ',' . BackendAccessGuard::MODULE_IDENTIFIER;
        }

        return $user;
    }

    /**
     * @param list<int> $groups
     */
    protected function makeDraft(int $owner = 2, array $groups = [], string $title = 'Draft'): int
    {
        $repository = $this->get(DraftRepository::class);
        self::assertInstanceOf(DraftRepository::class, $repository);

        return $repository->insert(
            $owner,
            $title,
            1,
            0,
            [],
            ['description' => 'd'],
            [['id' => 'aaaaaaaaaaaaaaaa', 'section' => 'hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>b</p>', 'imageUid' => 0, 'imageorient' => 0, 'colPos' => 0, 'animation' => []]],
            $groups,
            self::NOW,
        );
    }
}
