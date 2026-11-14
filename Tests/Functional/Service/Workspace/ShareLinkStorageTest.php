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
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Service\Workspace\ShareToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

#[CoversClass(ShareLinkRepository::class)]
#[CoversClass(AuditRepository::class)]
#[CoversClass(ShareLinkService::class)]
final class ShareLinkStorageTest extends AbstractWorkspaceTestCase
{
    private ShareLinkRepository $links;

    private ShareLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $links = $this->get(ShareLinkRepository::class);
        $service = $this->get(ShareLinkService::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        self::assertInstanceOf(ShareLinkService::class, $service);
        $this->links = $links;
        $this->service = $service;
    }

    #[Test]
    public function theDatabaseHoldsOnlyThePrefixAndTheHashNeverTheToken(): void
    {
        $draft = $this->draft();

        $issued = $this->service->issue($draft, LinkPermission::Comment, 7, 'Agency', 'a passphrase', 0, 2, self::NOW);

        $rows = $this->getConnectionPool()->getConnectionForTable(ShareLinkRepository::TABLE)
            ->select(['*'], ShareLinkRepository::TABLE)->fetchAllAssociative();
        self::assertCount(1, $rows);
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($issued['token'], $dump);
        self::assertStringNotContainsString(substr($issued['token'], -43), $dump, 'the secret part is not stored');
        self::assertStringNotContainsString('a passphrase', $dump);
        self::assertSame(ShareToken::prefixOf($issued['token']), $rows[0]['token_prefix']);
        self::assertSame(hash('sha256', $issued['token']), $rows[0]['token_hash']);
        self::assertNotSame('', $rows[0]['passphrase_hash']);

        // And the audit trail names who created it, nothing else.
        $audit = $this->get(AuditRepository::class);
        self::assertInstanceOf(AuditRepository::class, $audit);
        $trail = $audit->findByDraft($draft->uid);
        self::assertSame([['linkUid' => $issued['uid'], 'beUser' => 2, 'action' => 'link_created', 'crdate' => self::NOW]], $trail);
        self::assertStringNotContainsString($issued['token'], json_encode($trail, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aLinkIsFoundByItsPrefixAndVerifiedByItsHash(): void
    {
        $issued = $this->service->issue($this->draft(), LinkPermission::Edit, 7, '', '', 0, 2, self::NOW);
        $prefix = ShareToken::prefixOf($issued['token']);
        self::assertNotNull($prefix);

        $link = $this->links->findByPrefix($prefix);

        self::assertNotNull($link);
        self::assertSame(LinkPermission::Edit, $link->permission);
        self::assertTrue(ShareToken::matches($link->tokenHash, $issued['token']));
        self::assertFalse(ShareToken::matches($link->tokenHash, ShareToken::generate()['token']));
        self::assertNull($this->links->findByPrefix('0000000000000000'));
        self::assertNull($this->links->findByPrefix(''));
    }

    #[Test]
    public function aPrefixCannotBeStoredTwice(): void
    {
        $issued = ShareToken::generate();
        $this->links->insert(1, 'a', $issued['prefix'], $issued['hash'], LinkPermission::View, self::NOW + 100, '', 0, 2, self::NOW);

        $this->expectException(Throwable::class);
        $this->links->insert(1, 'b', $issued['prefix'], $issued['hash'], LinkPermission::View, self::NOW + 100, '', 0, 2, self::NOW);
    }

    #[Test]
    public function aUsageLimitOfTwoAdmitsTwoEntriesAndRefusesTheThird(): void
    {
        $issued = $this->service->issue($this->draft(), LinkPermission::View, 7, '', '', 2, 2, self::NOW);

        self::assertTrue($this->links->consumeUse($issued['uid'], self::NOW + 1));
        self::assertTrue($this->links->consumeUse($issued['uid'], self::NOW + 2));
        self::assertFalse($this->links->consumeUse($issued['uid'], self::NOW + 3));

        $link = $this->links->findByUid($issued['uid']);
        self::assertNotNull($link);
        self::assertSame(2, $link->useCount);
        self::assertTrue($link->isUsedUp());
    }

    #[Test]
    public function aLinkWithoutALimitCountsButNeverRunsOut(): void
    {
        $issued = $this->service->issue($this->draft(), LinkPermission::View, 7, '', '', 0, 2, self::NOW);

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($this->links->consumeUse($issued['uid'], self::NOW + 1));
        }
        self::assertSame(5, $this->links->findByUid($issued['uid'])?->useCount);
    }

    #[Test]
    public function aRevokedOrExpiredLinkTakesNoUse(): void
    {
        $revoked = $this->service->issue($this->draft(), LinkPermission::View, 7, '', '', 0, 2, self::NOW);
        $expired = $this->service->issue($this->draft(), LinkPermission::View, 1, '', '', 0, 2, self::NOW);

        self::assertTrue($this->links->revoke($revoked['uid'], 2, self::NOW + 1));

        self::assertFalse($this->links->consumeUse($revoked['uid'], self::NOW + 2));
        self::assertFalse($this->links->consumeUse($expired['uid'], self::NOW + 86400), 'expired exactly now');
        self::assertFalse($this->links->consumeUse($expired['uid'], self::NOW + 90000));
        self::assertSame(0, $this->links->findByUid($revoked['uid'])?->useCount);
        self::assertSame(0, $this->links->findByUid($expired['uid'])?->useCount);
    }

    #[Test]
    public function revokingRecordsWhoAndWhenAndOnlyHappensOnce(): void
    {
        $draft = $this->draft();
        $issued = $this->service->issue($draft, LinkPermission::View, 7, '', '', 0, 2, self::NOW);

        $this->service->revoke($draft, $issued['uid'], 1, self::NOW + 60);

        $link = $this->links->findByUid($issued['uid']);
        self::assertNotNull($link);
        self::assertTrue($link->isRevoked());
        self::assertSame(1, $link->revokedBy);
        self::assertSame(self::NOW + 60, $link->revokedAt);
        self::assertFalse($link->isUsable(self::NOW + 61));

        $this->expectException(\Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException::class);
        $this->service->revoke($draft, $issued['uid'], 1, self::NOW + 120);
    }

    #[Test]
    public function aLinkOfAnotherDraftCannotBeRevokedThroughThisOne(): void
    {
        $mine = $this->draft();
        $other = $this->draft();
        $foreign = $this->service->issue($other, LinkPermission::View, 7, '', '', 0, 2, self::NOW);

        try {
            $this->service->revoke($mine, $foreign['uid'], 2, self::NOW + 1);
            self::fail('A link of another draft was revoked.');
        } catch (\Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException) {
            // expected
        }
        self::assertFalse($this->links->findByUid($foreign['uid'])?->isRevoked());
    }

    #[Test]
    public function theLinksOfADraftAreListedNewestFirst(): void
    {
        $draft = $this->draft();
        $first = $this->service->issue($draft, LinkPermission::View, 7, 'first', '', 0, 2, self::NOW);
        $second = $this->service->issue($draft, LinkPermission::View, 7, 'second', '', 0, 2, self::NOW);
        $this->service->issue($this->draft(), LinkPermission::View, 7, 'foreign', '', 0, 2, self::NOW);

        $listed = $this->links->findByDraft($draft->uid);

        self::assertSame([$second['uid'], $first['uid']], array_map(static fn($link): int => $link->uid, $listed));
    }

    #[Test]
    public function theAuditTrailHasNoWayToChangeARecord(): void
    {
        $methods = array_map(static fn(ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(AuditRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC));

        self::assertEqualsCanonicalizing(['__construct', 'record', 'findByDraft'], $methods);
        $audit = $this->get(AuditRepository::class);
        self::assertInstanceOf(AuditRepository::class, $audit);
        $audit->record(1, 0, 2, AuditAction::Approved, self::NOW);
        self::assertSame('approved', $audit->findByDraft(1)[0]['action']);
    }

    private function draft(): \Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft
    {
        $repository = $this->get(DraftRepository::class);
        self::assertInstanceOf(DraftRepository::class, $repository);
        $draft = $repository->findByUid($this->makeDraft(2));
        self::assertNotNull($draft);

        return $draft;
    }
}
