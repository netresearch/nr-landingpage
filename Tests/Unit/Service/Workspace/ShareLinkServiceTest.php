<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\Workspace\AuditRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Service\Workspace\ShareToken;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

#[CoversClass(ShareLinkService::class)]
final class ShareLinkServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private ShareLinkRepository&MockObject $links;

    private AuditRepository&MockObject $audit;

    private ShareLinkService $subject;

    /** @var list<array<string, mixed>> */
    private array $inserted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms'] = [BcryptPasswordHash::class];
        $GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing'] = [
            'className' => BcryptPasswordHash::class,
            'options' => ['cost' => 10],
        ];
        $this->inserted = [];
        $this->links = $this->createMock(ShareLinkRepository::class);
        $this->links->method('insert')->willReturnCallback(
            function (int $draftUid, string $label, string $prefix, string $hash, LinkPermission $permission, int $expires, string $passphraseHash, int $maxUses, int $createdBy, int $now): int {
                $this->inserted[] = compact('draftUid', 'label', 'prefix', 'hash', 'permission', 'expires', 'passphraseHash', 'maxUses', 'createdBy', 'now');

                return 42;
            },
        );
        $this->audit = $this->createMock(AuditRepository::class);
        $this->subject = new ShareLinkService(
            $this->links,
            $this->audit,
            new WorkspaceSettings(['maxLinkLifetimeDays' => '30']),
            new PasswordHashFactory(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing'], $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms']);
        parent::tearDown();
    }

    #[Test]
    public function onlyThePrefixAndTheHashOfTheTokenAreStored(): void
    {
        $issued = $this->subject->issue($this->draft(), LinkPermission::Comment, 7, 'Agency', '', 0, 5, self::NOW);

        self::assertSame(42, $issued['uid']);
        self::assertMatchesRegularExpression('/^nrws_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $issued['token']);
        self::assertCount(1, $this->inserted);
        $row = $this->inserted[0];
        self::assertSame(ShareToken::prefixOf($issued['token']), $row['prefix']);
        self::assertSame(hash('sha256', $issued['token']), $row['hash']);
        foreach ($row as $name => $value) {
            self::assertStringNotContainsString($issued['token'], (string) (is_scalar($value) ? $value : ''), 'the token is in ' . $name);
        }
    }

    #[Test]
    public function theExpiryIsCountedFromNowInDays(): void
    {
        $issued = $this->subject->issue($this->draft(), LinkPermission::View, 3, '', '', 0, 5, self::NOW);

        self::assertSame(self::NOW + 3 * 86400, $issued['expires']);
        self::assertSame(self::NOW + 3 * 86400, $this->inserted[0]['expires']);
    }

    #[Test]
    #[DataProvider('badLifetimes')]
    public function aLifetimeOutsideTheBoundsIsRefusedAndNothingIsStored(int $days): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        try {
            $this->subject->issue($this->draft(), LinkPermission::View, $days, '', '', 0, 5, self::NOW);
        } finally {
            self::assertSame([], $this->inserted);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badLifetimes(): iterable
    {
        yield 'no expiry' => [0];
        yield 'negative' => [-1];
        yield 'one over the maximum' => [31];
        yield 'a year' => [365];
    }

    #[Test]
    public function theMaximumLifetimeItselfIsAllowed(): void
    {
        $issued = $this->subject->issue($this->draft(), LinkPermission::View, 30, '', '', 0, 5, self::NOW);

        self::assertSame(self::NOW + 30 * 86400, $issued['expires']);
    }

    #[Test]
    public function aPassphraseIsStoredHashedAndVerifiable(): void
    {
        $this->subject->issue($this->draft(), LinkPermission::View, 7, '', 'correct horse', 0, 5, self::NOW);

        $hash = $this->inserted[0]['passphraseHash'];
        self::assertIsString($hash);
        self::assertNotSame('', $hash);
        self::assertStringNotContainsString('correct horse', $hash);
        $hasher = (new PasswordHashFactory())->getDefaultHashInstance('FE');
        self::assertTrue($hasher->checkPassword('correct horse', $hash));
        self::assertFalse($hasher->checkPassword('wrong horse', $hash));
    }

    #[Test]
    public function noPassphraseStoresNoHash(): void
    {
        $this->subject->issue($this->draft(), LinkPermission::View, 7, '', '', 0, 5, self::NOW);

        self::assertSame('', $this->inserted[0]['passphraseHash']);
    }

    #[Test]
    #[DataProvider('badPassphrases')]
    public function aTooShortOrTooLongPassphraseIsRefused(string $passphrase): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->issue($this->draft(), LinkPermission::View, 7, '', $passphrase, 0, 5, self::NOW);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPassphrases(): iterable
    {
        yield 'one character' => ['a'];
        yield 'seven characters' => ['1234567'];
        yield 'two hundred and one' => [str_repeat('x', 201)];
    }

    #[Test]
    #[DataProvider('badUsageLimits')]
    public function aUsageLimitOutsideTheBoundsIsRefused(int $maxUses): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->issue($this->draft(), LinkPermission::View, 7, '', '', $maxUses, 5, self::NOW);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badUsageLimits(): iterable
    {
        yield 'negative' => [-1];
        yield 'above the cap' => [1001];
    }

    #[Test]
    public function aDraftThatIsNotOpenTakesNoNewLink(): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->issue($this->draft(DraftStatus::Approved), LinkPermission::View, 7, '', '', 0, 5, self::NOW);
    }

    #[Test]
    public function theLabelLosesControlCharacters(): void
    {
        $this->subject->issue($this->draft(), LinkPermission::View, 7, "  Agency\x00\n\x1B[31m team ", '', 0, 5, self::NOW);

        self::assertSame('Agency [31m team', $this->inserted[0]['label']);
    }

    #[Test]
    public function creatingALinkIsAuditedWithoutTheToken(): void
    {
        $this->audit->expects(self::once())->method('record')->with(7, 42, 5, AuditAction::LinkCreated, self::NOW);

        $this->subject->issue($this->draft(), LinkPermission::View, 7, '', '', 0, 5, self::NOW);
    }

    #[Test]
    public function aLinkOfAnotherDraftCannotBeRevoked(): void
    {
        $this->links->method('findByUid')->willReturn($this->linkOf(99));
        $this->links->expects(self::never())->method('revoke');
        $this->audit->expects(self::never())->method('record');

        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->revoke($this->draft(), 1, 5, self::NOW);
    }

    #[Test]
    public function aMissingLinkCannotBeRevoked(): void
    {
        $this->links->method('findByUid')->willReturn(null);

        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->revoke($this->draft(), 1, 5, self::NOW);
    }

    #[Test]
    public function aLinkOfTheDraftIsRevokedAndAudited(): void
    {
        $this->links->method('findByUid')->willReturn($this->linkOf(7));
        $this->links->expects(self::once())->method('revoke')->with(1, 5, self::NOW)->willReturn(true);
        $this->audit->expects(self::once())->method('record')->with(7, 1, 5, AuditAction::LinkRevoked, self::NOW);

        $this->subject->revoke($this->draft(), 1, 5, self::NOW);
    }

    #[Test]
    public function aLinkRevokedAlreadyIsRefusedAndNotAuditedTwice(): void
    {
        $this->links->method('findByUid')->willReturn($this->linkOf(7));
        $this->links->method('revoke')->willReturn(false);
        $this->audit->expects(self::never())->method('record');

        $this->expectException(WorkspaceRefusedException::class);
        $this->subject->revoke($this->draft(), 1, 5, self::NOW);
    }

    private function draft(DraftStatus $status = DraftStatus::Draft): WorkspaceDraft
    {
        return new WorkspaceDraft(7, 5, [], 'Draft', 1, 0, [], [], [], 1, $status, 0, 0, 0, 0, 0, 0);
    }

    private function linkOf(int $draftUid): ShareLink
    {
        return ShareLink::fromRow(['uid' => 1, 'draft_uid' => $draftUid, 'expires' => self::NOW + 100]);
    }
}
