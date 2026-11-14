<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service\Workspace;

use Netresearch\NrLandingpage\Service\Workspace\DraftAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DraftAccessGuard::class)]
#[CoversClass(DraftRepository::class)]
final class DraftAccessTest extends AbstractWorkspaceTestCase
{
    private DraftAccessGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $guard = $this->get(DraftAccessGuard::class);
        self::assertInstanceOf(DraftAccessGuard::class, $guard);
        $this->guard = $guard;
    }

    #[Test]
    public function aDraftRoundTripsThroughTheDatabase(): void
    {
        $uid = $this->makeDraft(2, [1]);
        $this->actAs(2);

        $draft = $this->guard->load($uid);

        self::assertNotNull($draft);
        self::assertSame($uid, $draft->uid);
        self::assertSame(2, $draft->owner);
        self::assertSame([1], $draft->beGroups);
        self::assertSame(1, $draft->version);
        self::assertTrue($draft->isDraft());
        self::assertSame('hero', $draft->sections[0]['section']);
        self::assertSame('aaaaaaaaaaaaaaaa', $draft->sections[0]['id']);
    }

    #[Test]
    public function theOwnerSeesTheDraftAndAdministratorsSeeEveryDraft(): void
    {
        $uid = $this->makeDraft(2);

        $this->actAs(2);
        self::assertNotNull($this->guard->load($uid));

        $this->actAs(1);
        self::assertNotNull($this->guard->load($uid));
    }

    #[Test]
    public function aUserOfAnotherGroupGetsNotFound(): void
    {
        $uid = $this->makeDraft(2, [1]);

        $this->actAs(4);

        self::assertNull($this->guard->load($uid));
        self::assertSame([], $this->guard->visibleDrafts());
    }

    #[Test]
    public function groupOneDoesNotMatchGroupTwelveOrTheOtherWayRound(): void
    {
        $sharedWithTwelve = $this->makeDraft(2, [12], 'for twelve');
        $sharedWithOne = $this->makeDraft(2, [1], 'for one');

        // user 3 is in group 1: sees the draft shared with group 1, not the one for 12
        $this->actAs(3);
        $visible = array_map(static fn($draft): string => $draft->title, $this->guard->visibleDrafts());
        self::assertSame(['for one'], $visible);
        self::assertNull($this->guard->load($sharedWithTwelve));
        self::assertNotNull($this->guard->load($sharedWithOne));

        // user 4 is in group 12: the other way round
        $this->actAs(4);
        $visible = array_map(static fn($draft): string => $draft->title, $this->guard->visibleDrafts());
        self::assertSame(['for twelve'], $visible);
    }

    #[Test]
    public function aGroupMemberSeesTheDraftButMayNotShareIt(): void
    {
        $uid = $this->makeDraft(2, [1]);

        $this->actAs(3);
        $draft = $this->guard->load($uid);

        self::assertNotNull($draft);
        self::assertFalse($this->guard->mayShare($draft));

        $this->actAs(2);
        self::assertTrue($this->guard->mayShare($draft));

        $this->actAs(1);
        self::assertTrue($this->guard->mayShare($draft));
    }

    #[Test]
    public function withoutTheWorkspaceModuleNothingIsVisibleEvenToTheOwner(): void
    {
        $uid = $this->makeDraft(2);

        $this->actAs(2, false);

        self::assertNull($this->guard->load($uid));
        self::assertSame([], $this->guard->visibleDrafts());
    }

    #[Test]
    public function aDeletedDraftIsGoneForEveryone(): void
    {
        $uid = $this->makeDraft(2);
        $this->getConnectionPool()->getConnectionForTable(DraftRepository::TABLE)->update(DraftRepository::TABLE, ['deleted' => 1], ['uid' => $uid]);

        $this->actAs(1);

        self::assertNull($this->guard->load($uid));
        self::assertSame([], $this->guard->visibleDrafts());
    }

    #[Test]
    public function aMissingDraftIsAnsweredLikeAnInvisibleOne(): void
    {
        $this->actAs(1);

        self::assertNull($this->guard->load(99999));
        self::assertNull($this->guard->load(0));
        self::assertNull($this->guard->load(-1));
    }
}
