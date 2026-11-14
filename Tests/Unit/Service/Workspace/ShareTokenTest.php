<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Service\Workspace\ShareToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShareToken::class)]
final class ShareTokenTest extends TestCase
{
    #[Test]
    public function aTokenHasThePrefixAndA256BitSecretInBase64Url(): void
    {
        $issued = ShareToken::generate();

        self::assertMatchesRegularExpression('/^nrws_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $issued['token']);
        $secret = substr($issued['token'], strlen('nrws_') + 16 + 1);
        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);
        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded), 'the secret is 256 bits');
    }

    #[Test]
    public function thePrefixIsTheLookupKeyAndTheHashCoversTheWholeToken(): void
    {
        $issued = ShareToken::generate();

        self::assertSame($issued['prefix'], ShareToken::prefixOf($issued['token']));
        self::assertSame(hash('sha256', $issued['token']), $issued['hash']);
        self::assertSame(64, strlen($issued['hash']));
        self::assertStringNotContainsString($issued['token'], $issued['hash']);
    }

    #[Test]
    public function thePrefixIsNotDerivedFromTheSecret(): void
    {
        $issued = ShareToken::generate();
        $secret = substr($issued['token'], strlen('nrws_') + 16 + 1);

        self::assertStringNotContainsString($issued['prefix'], $secret);
        self::assertNotSame(substr($issued['hash'], 0, 16), $issued['prefix']);
    }

    #[Test]
    public function tokensDoNotRepeat(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $tokens[ShareToken::generate()['token']] = true;
        }

        self::assertCount(200, $tokens);
    }

    #[Test]
    public function aMatchingTokenIsAccepted(): void
    {
        $issued = ShareToken::generate();

        self::assertTrue(ShareToken::matches($issued['hash'], $issued['token']));
    }

    #[Test]
    public function aWrongTokenWithTheSamePrefixIsRefused(): void
    {
        $issued = ShareToken::generate();
        $other = ShareToken::generate();
        $forged = 'nrws_' . $issued['prefix'] . '_' . substr($other['token'], strlen('nrws_') + 17);

        self::assertSame($issued['prefix'], ShareToken::prefixOf($forged));
        self::assertFalse(ShareToken::matches($issued['hash'], $forged));
    }

    #[Test]
    #[DataProvider('noStoredHash')]
    public function aMissingHashNeverMatchesAnything(?string $stored): void
    {
        self::assertFalse(ShareToken::matches($stored, ShareToken::generate()['token']));
        self::assertFalse(ShareToken::matches($stored, ''));
        // The constant a missing row is compared with is not a hash anyone can match.
        self::assertFalse(ShareToken::matches($stored, str_repeat('0', 64)));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noStoredHash(): iterable
    {
        yield 'no row' => [null];
        yield 'empty' => [''];
        yield 'the constant itself' => [str_repeat('0', 64)];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function aMalformedTokenHasNoPrefix(string $token): void
    {
        self::assertNull(ShareToken::prefixOf($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        $good = ShareToken::generate()['token'];
        yield 'empty' => [''];
        yield 'wrong scheme' => ['nra2a_' . substr($good, 5)];
        yield 'short secret' => [substr($good, 0, -1)];
        yield 'long secret' => [$good . 'A'];
        yield 'upper case prefix' => ['nrws_' . strtoupper(substr($good, 5, 16)) . substr($good, 21)];
        yield 'non base64url' => [substr($good, 0, -1) . '+'];
        yield 'leading space' => [' ' . $good];
        yield 'trailing newline' => [$good . "\n"];
        yield 'two tokens' => [$good . $good];
    }
}
