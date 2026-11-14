<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Domain\Model\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkPermission::class)]
#[CoversClass(ShareLink::class)]
#[CoversClass(WorkspaceDraft::class)]
final class WorkspaceModelTest extends TestCase
{
    #[Test]
    #[DataProvider('permissionMatrix')]
    public function aPermissionIncludesTheLevelsBelowItAndNoneAbove(LinkPermission $has, LinkPermission $needs, bool $allowed): void
    {
        self::assertSame($allowed, $has->allows($needs));
    }

    /**
     * @return iterable<string, array{LinkPermission, LinkPermission, bool}>
     */
    public static function permissionMatrix(): iterable
    {
        yield 'view views' => [LinkPermission::View, LinkPermission::View, true];
        yield 'view cannot comment' => [LinkPermission::View, LinkPermission::Comment, false];
        yield 'view cannot edit' => [LinkPermission::View, LinkPermission::Edit, false];
        yield 'comment views' => [LinkPermission::Comment, LinkPermission::View, true];
        yield 'comment comments' => [LinkPermission::Comment, LinkPermission::Comment, true];
        yield 'comment cannot edit' => [LinkPermission::Comment, LinkPermission::Edit, false];
        yield 'edit views' => [LinkPermission::Edit, LinkPermission::View, true];
        yield 'edit comments' => [LinkPermission::Edit, LinkPermission::Comment, true];
        yield 'edit edits' => [LinkPermission::Edit, LinkPermission::Edit, true];
    }

    #[Test]
    public function anUnknownPermissionIsNotAPermission(): void
    {
        self::assertNull(LinkPermission::tryFrom('admin'));
        self::assertNull(LinkPermission::tryFrom('approve'));
        self::assertNull(LinkPermission::tryFrom(''));
    }

    #[Test]
    public function aRowBecomesALinkThatKnowsItsState(): void
    {
        $link = ShareLink::fromRow(self::linkRow());

        self::assertSame(LinkPermission::Comment, $link->permission);
        self::assertSame(7, $link->draftUid);
        self::assertTrue($link->isUsable(1000));
        self::assertFalse($link->hasPassphrase());
    }

    #[Test]
    #[DataProvider('unusableLinks')]
    public function aLinkThatIsRevokedExpiredOrUsedUpIsNotUsable(array $override): void
    {
        $link = ShareLink::fromRow($override + self::linkRow());

        self::assertFalse($link->isUsable(1000));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableLinks(): iterable
    {
        yield 'revoked' => [['revoked_at' => '900']];
        yield 'expired exactly now' => [['expires' => '1000']];
        yield 'expired' => [['expires' => '999']];
        yield 'no expiry at all' => [['expires' => '0']];
        yield 'used up' => [['max_uses' => '2', 'use_count' => '2']];
    }

    #[Test]
    public function aLinkWithRoomLeftIsUsable(): void
    {
        self::assertTrue(ShareLink::fromRow(['max_uses' => '2', 'use_count' => '1'] + self::linkRow())->isUsable(1000));
        self::assertTrue(ShareLink::fromRow(['max_uses' => '0', 'use_count' => '50'] + self::linkRow())->isUsable(1000));
    }

    #[Test]
    public function aLinkWithAnUnknownPermissionFallsBackToTheLowest(): void
    {
        self::assertSame(LinkPermission::View, ShareLink::fromRow(['permission' => 'root'] + self::linkRow())->permission);
    }

    #[Test]
    public function aRowBecomesADraft(): void
    {
        $draft = WorkspaceDraft::fromRow([
            'uid' => '3',
            'owner' => '5',
            'be_groups' => '1,12,,x',
            'title' => 'Launch',
            'template_uid' => '2',
            'parent_page_uid' => '9',
            'briefing_data' => '{"topic":"x"}',
            'page_fields' => '{"description":"d","bad":5}',
            'sections' => '[{"id":"a1","header":"H"},"junk"]',
            'version' => '4',
            'status' => 'approved',
        ]);

        self::assertSame([1, 12], $draft->beGroups);
        self::assertSame(['description' => 'd'], $draft->pageFields);
        self::assertSame([['id' => 'a1', 'header' => 'H']], $draft->sections);
        self::assertSame(DraftStatus::Approved, $draft->status);
        self::assertFalse($draft->isDraft());
        self::assertSame(4, $draft->version);
    }

    #[Test]
    public function brokenJsonAndUnknownStatusFallBackToAnEmptyOpenDraft(): void
    {
        $draft = WorkspaceDraft::fromRow(['uid' => 1, 'sections' => '{not json', 'status' => 'weird', 'version' => 0]);

        self::assertSame([], $draft->sections);
        self::assertSame(DraftStatus::Draft, $draft->status);
        self::assertTrue($draft->isDraft());
        self::assertSame(1, $draft->version);
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkRow(): array
    {
        return [
            'uid' => '1',
            'draft_uid' => '7',
            'label' => 'Agency',
            'token_prefix' => 'abcdef0123456789',
            'token_hash' => str_repeat('a', 64),
            'permission' => 'comment',
            'expires' => '5000',
            'passphrase_hash' => '',
            'max_uses' => '0',
            'use_count' => '0',
            'created_by' => '2',
            'crdate' => '100',
            'revoked_at' => '0',
            'revoked_by' => '0',
        ];
    }
}
