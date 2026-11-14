<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Review;

use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\Review\EnterStatus;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewAuthenticator;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewRateLimits;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Tests\Functional\Service\Workspace\AbstractWorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The time-dependent rules of a review session and the one write rule of the
 * draft, with the clock passed in (ADR-0001, sections 4, 5 and 6).
 */
#[CoversClass(ReviewAuthenticator::class)]
#[CoversClass(DraftRepository::class)]
final class ReviewAuthenticatorTest extends AbstractWorkspaceTestCase
{
    private ReviewAuthenticator $authenticator;

    private ShareLinkService $links;

    private int $draftUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $authenticator = $this->get(ReviewAuthenticator::class);
        $links = $this->get(ShareLinkService::class);
        self::assertInstanceOf(ReviewAuthenticator::class, $authenticator);
        self::assertInstanceOf(ShareLinkService::class, $links);
        $this->authenticator = $authenticator;
        $this->links = $links;
        $this->draftUid = $this->makeDraft(2);
    }

    #[Test]
    public function aSessionNeverLastsLongerThanEightHours(): void
    {
        $short = $this->issue(1);
        $long = $this->issue(30);


        $shortOutcome = $this->authenticator->enter($short, '', 'a', '192.0.2.1', self::NOW);
        $longOutcome = $this->authenticator->enter($long, '', 'a', '192.0.2.1', self::NOW);

        self::assertSame(EnterStatus::Opened, $shortOutcome->status);
        self::assertSame(ReviewAuthenticator::SESSION_MAX_LIFETIME, $shortOutcome->sessionLifetime, 'a one day link still gives at most eight hours');
        self::assertSame(ReviewAuthenticator::SESSION_MAX_LIFETIME, $longOutcome->sessionLifetime);
        self::assertSame(28800, ReviewAuthenticator::SESSION_MAX_LIFETIME);

        self::assertNotNull($this->authenticator->resolve($longOutcome->sessionId, self::NOW + 28799));
        self::assertNull($this->authenticator->resolve($longOutcome->sessionId, self::NOW + 28800));
    }

    #[Test]
    public function aSessionEndsWhenItsLinkExpiresEvenIfItsOwnTimeIsNotUp(): void
    {
        // A link with two hours left: the session lasts two hours, not eight.
        $token = $this->issue(1);
        $link = $this->container(ShareLinkRepository::class)->findByPrefix((string) \Netresearch\NrLandingpage\Service\Workspace\ShareToken::prefixOf($token));
        self::assertNotNull($link);
        $this->getConnectionPool()->getConnectionForTable(ShareLinkRepository::TABLE)->update(ShareLinkRepository::TABLE, ['expires' => self::NOW + 7200], ['uid' => $link->uid]);

        $outcome = $this->authenticator->enter($token, '', 'a', '192.0.2.2', self::NOW);

        self::assertSame(7200, $outcome->sessionLifetime);
        self::assertNotNull($this->authenticator->resolve($outcome->sessionId, self::NOW + 7199));
        self::assertNull($this->authenticator->resolve($outcome->sessionId, self::NOW + 7200));
    }

    #[Test]
    public function aUsedUpLinkKeepsItsOpenSessionButAdmitsNoNewOne(): void
    {
        $token = $this->issue(7, maxUses: 1);

        $first = $this->authenticator->enter($token, '', 'a', '192.0.2.3', self::NOW);
        $second = $this->authenticator->enter($token, '', 'a', '192.0.2.3', self::NOW + 1);

        self::assertSame(EnterStatus::Opened, $first->status);
        self::assertSame(EnterStatus::Invalid, $second->status);
        self::assertNotNull($this->authenticator->resolve($first->sessionId, self::NOW + 2), 'the limit counts openings');
    }

    #[Test]
    public function aSessionResolvesOnlyToItsOwnDraftAndLink(): void
    {
        $otherDraft = $this->makeDraft(2, [], 'Other');
        $mine = $this->authenticator->enter($this->issue(7), '', 'Mine', '192.0.2.4', self::NOW);
        $draft = $this->container(DraftRepository::class)->findByUid($otherDraft);
        self::assertNotNull($draft);
        $theirs = $this->authenticator->enter($this->links->issue($draft, LinkPermission::Edit, 7, '', '', 0, 2, self::NOW)['token'], '', 'Theirs', '192.0.2.4', self::NOW);

        $mineContext = $this->authenticator->resolve($mine->sessionId, self::NOW);
        $theirContext = $this->authenticator->resolve($theirs->sessionId, self::NOW);

        self::assertNotNull($mineContext);
        self::assertNotNull($theirContext);
        self::assertSame($this->draftUid, $mineContext->draft->uid);
        self::assertSame($otherDraft, $theirContext->draft->uid);
        self::assertSame(LinkPermission::Comment, $mineContext->permission());
        self::assertSame(LinkPermission::Edit, $theirContext->permission());
        self::assertSame('Mine', $mineContext->session->reviewerName);
    }

    #[Test]
    public function aMalformedOrUnknownSessionIdResolvesToNothing(): void
    {
        foreach (['', 'x', str_repeat('A', 43), str_repeat('A', 43) . "\n", str_repeat('!', 43)] as $id) {
            self::assertNull($this->authenticator->resolve($id, self::NOW), $id);
        }
    }

    #[Test]
    public function leavingEndsTheSession(): void
    {
        $outcome = $this->authenticator->enter($this->issue(7), '', 'a', '192.0.2.5', self::NOW);
        self::assertNotNull($this->authenticator->resolve($outcome->sessionId, self::NOW));

        $this->authenticator->leave($outcome->sessionId);

        self::assertNull($this->authenticator->resolve($outcome->sessionId, self::NOW));
    }

    #[Test]
    public function theDraftWriteRuleRefusesAStaleVersionAndAClosedDraft(): void
    {
        $repository = $this->container(DraftRepository::class);
        $draft = $repository->findByUid($this->draftUid);
        self::assertNotNull($draft);
        $sections = [['id' => 'aaaaaaaaaaaaaaaa', 'header' => 'Changed']];

        self::assertTrue($repository->updateSections($this->draftUid, $sections, $draft->version, self::NOW + 1));
        self::assertSame($draft->version + 1, $repository->findByUid($this->draftUid)?->version);

        // The same version again: someone wrote in between.
        self::assertFalse($repository->updateSections($this->draftUid, [['id' => 'aaaaaaaaaaaaaaaa', 'header' => 'Second']], $draft->version, self::NOW + 2));
        self::assertSame('Changed', $repository->findByUid($this->draftUid)?->sections[0]['header']);

        // An approved draft takes no write, whatever the version.
        $current = $repository->findByUid($this->draftUid);
        self::assertNotNull($current);
        $this->getConnectionPool()->getConnectionForTable(DraftRepository::TABLE)->update(DraftRepository::TABLE, ['status' => DraftStatus::Approved->value], ['uid' => $this->draftUid]);
        self::assertFalse($repository->updateSections($this->draftUid, [['id' => 'aaaaaaaaaaaaaaaa', 'header' => 'Late']], $current->version, self::NOW + 3));
        self::assertSame('Changed', $repository->findByUid($this->draftUid)?->sections[0]['header']);
    }

    #[Test]
    public function garbageFromOneAddressNeverLocksOutAValidTokenButIsStillLimited(): void
    {
        $this->limitTo(ReviewRateLimits::REQUEST, 3);
        $this->limitTo(ReviewRateLimits::ENTER, 1000);
        $address = $this->freshAddress();
        $token = $this->issue(7);

        $statuses = [];
        for ($i = 0; $i < 6; ++$i) {
            $statuses[] = $this->authenticator->enter('garbage-' . $i, '', 'a', $address, self::NOW)->status;
        }
        $valid = $this->authenticator->enter($token, '', 'a', $address, self::NOW);

        self::assertSame(
            [EnterStatus::Invalid, EnterStatus::Invalid, EnterStatus::Invalid, EnterStatus::Limited, EnterStatus::Limited, EnterStatus::Limited],
            $statuses,
            'garbage-only traffic is still limited per address',
        );
        self::assertSame(EnterStatus::Opened, $valid->status, 'the holder of a valid token is not locked out by other clients on the same address');
    }

    #[Test]
    public function aValidTokenDoesNotConsumeTheAddressRequestAllowance(): void
    {
        $this->limitTo(ReviewRateLimits::REQUEST, 2);
        $address = $this->freshAddress();
        $token = $this->issue(7);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(EnterStatus::Opened, $this->authenticator->enter($token, '', 'a', $address, self::NOW + $i)->status);
        }

        self::assertSame(EnterStatus::Invalid, $this->authenticator->enter('garbage-a', '', 'a', $address, self::NOW + 5)->status);
        self::assertSame(EnterStatus::Invalid, $this->authenticator->enter('garbage-b', '', 'a', $address, self::NOW + 5)->status);
        self::assertSame(EnterStatus::Limited, $this->authenticator->enter('garbage-c', '', 'a', $address, self::NOW + 5)->status);
    }

    /** @var array<string, mixed> */
    private array $savedLimiterConfig = [];

    /**
     * The limiter factory reads the globals on every call, and the state of a
     * limiter outlives a test: set a limit here, put the old value back in tearDown().
     */
    private function limitTo(string $limiter, int $limit): void
    {
        $this->savedLimiterConfig[$limiter] ??= $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][$limiter] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][$limiter]['limit'] = $limit;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedLimiterConfig as $limiter => $saved) {
            if ($saved === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][$limiter]);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][$limiter] = $saved;
            }
        }
        $this->savedLimiterConfig = [];
        parent::tearDown();
    }

    /** A source address no earlier test used: the limiters' state outlives a test. */
    private function freshAddress(): string
    {
        return sprintf('10.%d.%d.%d', random_int(1, 250), random_int(1, 250), random_int(1, 250));
    }

    private function issue(int $days, int $maxUses = 0): string
    {
        $draft = $this->container(DraftRepository::class)->findByUid($this->draftUid);
        self::assertNotNull($draft);

        return $this->links->issue($draft, LinkPermission::Comment, $days, '', '', $maxUses, 2, self::NOW)['token'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function container(string $class): object
    {
        $service = $this->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
