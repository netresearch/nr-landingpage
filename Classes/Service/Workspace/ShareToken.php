<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use SensitiveParameter;

/**
 * The secret in a share link (ADR-0001, section 3).
 *
 * `nrws_<prefix>_<secret>`: the prefix is 8 random bytes in hex and finds the
 * row, the secret is 256 random bits in base64url. Only the prefix and the
 * SHA-256 of the whole token are stored. The shape and the lookup follow
 * nr-llm's A2A tokens.
 */
final class ShareToken
{
    public const PREFIX = 'nrws_';

    // `D`: without it `$` also matches before a trailing newline.
    private const PATTERN = '/^nrws_([0-9a-f]{16})_[A-Za-z0-9_-]{43}$/D';

    /** What a missing row is compared with, so the work is the same. */
    private const NO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @return array{token: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $prefix = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::PREFIX . $prefix . '_' . $secret;

        return ['token' => $token, 'prefix' => $prefix, 'hash' => self::hash($token)];
    }

    /**
     * The lookup prefix of a well-formed token, or null.
     */
    public static function prefixOf(#[SensitiveParameter] string $token): ?string
    {
        return preg_match(self::PATTERN, $token, $match) === 1 ? $match[1] : null;
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Compare a given token with the stored hash. A missing hash (no row)
     * is compared with a constant, so this takes the same time either way,
     * and never matches.
     */
    public static function matches(?string $storedHash, #[SensitiveParameter] string $token): bool
    {
        $given = self::hash($token);
        $expected = $storedHash === null || $storedHash === '' ? self::NO_HASH : $storedHash;
        $equal = hash_equals($expected, $given);

        return $expected !== self::NO_HASH && $equal;
    }
}
