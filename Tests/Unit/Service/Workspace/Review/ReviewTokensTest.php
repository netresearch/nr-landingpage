<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review;

use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReviewTokens::class)]
final class ReviewTokensTest extends TestCase
{
    private const NOW = 1_800_000_000;

    #[Test]
    public function anEntryTokenIsValidForTheCookieItWasMadeFor(): void
    {
        $tokens = new ReviewTokens('key-one');
        $entry = ReviewTokens::newEntryValue();

        self::assertTrue($tokens->isValidEntryToken($tokens->entryToken($entry, self::NOW), $entry, self::NOW + 5));
    }

    #[Test]
    public function anEntryTokenIsUselessWithAnotherOrNoCookie(): void
    {
        $tokens = new ReviewTokens('key-one');
        $token = $tokens->entryToken(ReviewTokens::newEntryValue(), self::NOW);

        self::assertFalse($tokens->isValidEntryToken($token, ReviewTokens::newEntryValue(), self::NOW));
        self::assertFalse($tokens->isValidEntryToken($token, '', self::NOW));
        self::assertFalse($tokens->isValidEntryToken($token, 'not-a-cookie-value', self::NOW));
    }

    #[Test]
    public function anEntryTokenExpiresAndDoesNotComeFromTheFuture(): void
    {
        $tokens = new ReviewTokens('key-one');
        $entry = ReviewTokens::newEntryValue();
        $token = $tokens->entryToken($entry, self::NOW);

        self::assertTrue($tokens->isValidEntryToken($token, $entry, self::NOW + ReviewTokens::ENTRY_LIFETIME));
        self::assertFalse($tokens->isValidEntryToken($token, $entry, self::NOW + ReviewTokens::ENTRY_LIFETIME + 1));
        self::assertFalse($tokens->isValidEntryToken($tokens->entryToken($entry, self::NOW + 3600), $entry, self::NOW));
    }

    #[Test]
    public function anEntryTokenFromAnotherKeyOrWithAChangedTimeIsRefused(): void
    {
        $entry = ReviewTokens::newEntryValue();
        $token = (new ReviewTokens('key-one'))->entryToken($entry, self::NOW);

        self::assertFalse((new ReviewTokens('key-two'))->isValidEntryToken($token, $entry, self::NOW));
        [$time, $mac] = explode('.', $token);
        self::assertFalse((new ReviewTokens('key-one'))->isValidEntryToken(($time + 1) . '.' . $mac, $entry, self::NOW + 1));
        self::assertFalse((new ReviewTokens('key-one'))->isValidEntryToken('', $entry, self::NOW));
        self::assertFalse((new ReviewTokens('key-one'))->isValidEntryToken($token . "\n", $entry, self::NOW));
    }

    #[Test]
    public function aSessionTokenBelongsToOneSession(): void
    {
        $tokens = new ReviewTokens('key-one');
        $one = ReviewTokens::newSessionId();
        $two = ReviewTokens::newSessionId();

        self::assertTrue($tokens->isValidSessionToken($one, $tokens->sessionToken($one)));
        self::assertFalse($tokens->isValidSessionToken($two, $tokens->sessionToken($one)));
        self::assertFalse($tokens->isValidSessionToken($one, ''));
        self::assertFalse($tokens->isValidSessionToken('short', $tokens->sessionToken('short')));
        self::assertFalse((new ReviewTokens('key-two'))->isValidSessionToken($one, $tokens->sessionToken($one)));
    }

    #[Test]
    public function theEntryTokenAndTheSessionTokenAreNotInterchangeable(): void
    {
        $tokens = new ReviewTokens('key-one');
        $session = ReviewTokens::newSessionId();

        self::assertNotSame($tokens->sessionToken($session), hash_hmac('sha256', 'nrws-entry|' . $session . '|' . self::NOW, 'key-one'));
        self::assertFalse($tokens->isValidSessionToken($session, $tokens->entryToken($session, self::NOW)));
    }

    #[Test]
    public function opaqueValuesHaveTheirLengthAndAlphabetAndDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; ++$i) {
            $entry = ReviewTokens::newEntryValue();
            $session = ReviewTokens::newSessionId();
            self::assertTrue(ReviewTokens::isEntryValue($entry));
            self::assertTrue(ReviewTokens::isSessionId($session));
            self::assertFalse(ReviewTokens::isSessionId($entry));
            $seen[$entry] = $seen[$session] = true;
        }
        self::assertCount(200, $seen);
        self::assertFalse(ReviewTokens::isSessionId(ReviewTokens::newSessionId() . "\n"));
    }

    #[Test]
    public function theSessionHashIsASha256AndNotTheId(): void
    {
        $id = ReviewTokens::newSessionId();

        self::assertSame(hash('sha256', $id), ReviewTokens::sessionHash($id));
        self::assertNotSame($id, ReviewTokens::sessionHash($id));
    }

    #[Test]
    public function withoutAnEncryptionKeyNothingIsIssued(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReviewTokens(''))->sessionToken(ReviewTokens::newSessionId());
    }
}
