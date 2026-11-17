<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Service\Workspace\AuditRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftDiscardService;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\SessionRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(DraftDiscardService::class)]
final class DraftDiscardTest extends AbstractWorkspaceTestCase
{
    #[Test]
    public function aFailureHalfWayWritesNothing(): void
    {
        $uid = $this->makeDraft(2);
        $drafts = $this->service(DraftRepository::class);
        $draft = $drafts->findByUid($uid);
        self::assertNotNull($draft);
        $issued = $this->service(ShareLinkService::class)->issue($draft, LinkPermission::View, 7, 'x', '', 0, 2, time());
        $sessionHash = hash('sha256', 'an open review session');
        $this->service(SessionRepository::class)->insert($issued['uid'], $sessionHash, 'Reviewer', time() + 3600, time());

        // The audit write of the discard itself fails, after the draft and the link were changed.
        $audit = new class ($this->service(ConnectionPool::class)) extends AuditRepository {
            public function record(int $draftUid, int $linkUid, int $beUser, AuditAction $action, int $now): void
            {
                if ($action === AuditAction::DraftDiscarded) {
                    throw new RuntimeException('audit storage failed', 1799400399);
                }
                parent::record($draftUid, $linkUid, $beUser, $action, $now);
            }
        };
        $discard = new DraftDiscardService(
            $drafts,
            $this->service(ShareLinkRepository::class),
            $this->service(SessionRepository::class),
            $audit,
            $this->service(ConnectionPool::class),
        );

        try {
            $discard->discard($draft, 2, time());
            self::fail('the failing audit write must surface');
        } catch (RuntimeException $e) {
            self::assertSame(1799400399, $e->getCode());
        }

        self::assertNotNull($drafts->findByUid($uid), 'the draft is not deleted');
        self::assertFalse($this->service(ShareLinkRepository::class)->findByUid($issued['uid'])?->isRevoked(), 'the link is not revoked');
        self::assertNotNull($this->service(SessionRepository::class)->findByHash($sessionHash), 'the open session is kept');
        $actions = array_column($this->getConnectionPool()->getConnectionForTable('tx_nrlandingpage_workspace_audit')
            ->select(['action'], 'tx_nrlandingpage_workspace_audit', ['draft_uid' => $uid])->fetchAllAssociative(), 'action');
        self::assertSame(['link_created'], $actions, 'no revocation is recorded');
    }

    #[Test]
    public function aSecondDiscardOfTheSameDraftIsNotFoundAndRecordedOnce(): void
    {
        $uid = $this->makeDraft(2);
        $draft = $this->service(DraftRepository::class)->findByUid($uid);
        self::assertNotNull($draft);
        $discard = $this->service(DraftDiscardService::class);

        $discard->discard($draft, 2, time());
        try {
            // The same object the first caller held: a second request that loaded the draft before the first committed.
            $discard->discard($draft, 1, time());
            self::fail('a second discard must be refused');
        } catch (WorkspaceRefusedException $e) {
            self::assertSame(1799400301, $e->getCode());
            self::assertSame(WorkspaceRefusedException::NOT_FOUND, $e->reason);
        }

        $actions = array_column($this->getConnectionPool()->getConnectionForTable('tx_nrlandingpage_workspace_audit')
            ->select(['action'], 'tx_nrlandingpage_workspace_audit', ['draft_uid' => $uid])->fetchAllAssociative(), 'action');
        self::assertSame(['draft_discarded'], $actions);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
