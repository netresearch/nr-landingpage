<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review;

use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewCookies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewCookies::class)]
final class ReviewCookiesTest extends TestCase
{
    #[Test]
    public function overHttpsTheCookieIsAHostCookie(): void
    {
        $header = ReviewCookies::header(ReviewCookies::SESSION, 'abc', 600, true);

        self::assertSame('__Host-nrws_session=abc; Max-Age=600; Path=/; HttpOnly; SameSite=Strict; Secure', $header);
        self::assertStringNotContainsString('Domain', $header);
    }

    #[Test]
    public function overPlainHttpItHasNeitherThePrefixNorSecure(): void
    {
        $header = ReviewCookies::header(ReviewCookies::ENTRY, 'abc', 600, false);

        self::assertSame('nrws_entry=abc; Max-Age=600; Path=/; HttpOnly; SameSite=Strict', $header);
    }

    #[Test]
    public function aMaxAgeOfZeroDeletesTheCookie(): void
    {
        self::assertStringContainsString('Max-Age=0;', ReviewCookies::header(ReviewCookies::SESSION, '', 0, true));
        self::assertStringContainsString('Max-Age=0;', ReviewCookies::header(ReviewCookies::SESSION, '', -5, true));
    }

    #[Test]
    public function aCookieIsReadUnderItsOwnNameOnly(): void
    {
        $cookies = ['__Host-nrws_session' => 'secure-value', 'nrws_session' => 'plain-value', 'other' => ['array']];

        self::assertSame('secure-value', ReviewCookies::read($cookies, ReviewCookies::SESSION, true));
        self::assertSame('plain-value', ReviewCookies::read($cookies, ReviewCookies::SESSION, false));
        self::assertSame('', ReviewCookies::read($cookies, ReviewCookies::ENTRY, true));
        self::assertSame('', ReviewCookies::read(['__Host-nrws_session' => ['x']], ReviewCookies::SESSION, true));
    }
}
