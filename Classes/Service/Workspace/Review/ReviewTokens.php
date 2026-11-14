<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use RuntimeException;
use SensitiveParameter;

/**
 * The form tokens and opaque values of the review page (ADR-0001, section 5).
 *
 * Both form tokens are HMACs under a key derived from the TYPO3 encryption
 * key, so they need no storage. The entry token is bound to the entry cookie
 * of one browser, the session token to one review session.
 */
final class ReviewTokens
{
    /** How long an entry form token is valid, in seconds. */
    public const ENTRY_LIFETIME = 7200;

    /** An opaque cookie value: 16 bytes in base64url. */
    private const ENTRY_VALUE_PATTERN = '/^[A-Za-z0-9_-]{22}$/D';

    /** A session id: 32 bytes in base64url. */
    private const SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $encryptionKey = null,
    ) {}

    public static function newEntryValue(): string
    {
        return self::base64Url(random_bytes(16));
    }

    public static function newSessionId(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function isEntryValue(string $value): bool
    {
        return preg_match(self::ENTRY_VALUE_PATTERN, $value) === 1;
    }

    public static function isSessionId(string $value): bool
    {
        return preg_match(self::SESSION_ID_PATTERN, $value) === 1;
    }

    public static function sessionHash(#[SensitiveParameter] string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function entryToken(string $entryValue, int $now): string
    {
        return $now . '.' . $this->entryMac($entryValue, $now);
    }

    public function isValidEntryToken(string $token, string $entryValue, int $now): bool
    {
        if (!self::isEntryValue($entryValue) || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $match) !== 1) {
            return false;
        }
        $issued = (int) $match[1];
        if ($issued > $now + 60 || $now - $issued > self::ENTRY_LIFETIME) {
            return false;
        }

        return hash_equals($this->entryMac($entryValue, $issued), $match[2]);
    }

    public function sessionToken(#[SensitiveParameter] string $sessionId): string
    {
        return hash_hmac('sha256', 'nrws-csrf|' . $sessionId, $this->key());
    }

    public function isValidSessionToken(#[SensitiveParameter] string $sessionId, string $token): bool
    {
        return self::isSessionId($sessionId) && hash_equals($this->sessionToken($sessionId), $token);
    }

    private function entryMac(string $entryValue, int $issued): string
    {
        return hash_hmac('sha256', 'nrws-entry|' . $entryValue . '|' . $issued, $this->key());
    }

    private function key(): string
    {
        $encryptionKey = $this->encryptionKey;
        if ($encryptionKey === null) {
            $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
            $sys = is_array($confVars) ? ($confVars['SYS'] ?? null) : null;
            $configured = is_array($sys) ? ($sys['encryptionKey'] ?? null) : null;
            $encryptionKey = is_string($configured) ? $configured : '';
        }
        if ($encryptionKey === '') {
            // Without a key every token would be forgeable: refuse to work.
            throw new RuntimeException('The TYPO3 encryption key is not set.', 1799500001);
        }

        return hash_hmac('sha256', 'nr_landingpage.workspace', $encryptionKey);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
