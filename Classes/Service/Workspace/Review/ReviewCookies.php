<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use SensitiveParameter;

/**
 * The two cookies of the review page (ADR-0001, section 5), with the names
 * and attributes that make a browser refuse a cookie a sibling subdomain tries
 * to plant: over HTTPS `__Host-`, `Secure`, `Path=/`, no `Domain`, and always
 * `HttpOnly` and `SameSite=Strict`.
 */
final class ReviewCookies
{
    public const SESSION = 'nrws_session';

    public const ENTRY = 'nrws_entry';

    public static function name(string $base, bool $secure): string
    {
        return ($secure ? '__Host-' : '') . $base;
    }

    /**
     * The value of a cookie of the request, or '' when it carries none.
     *
     * @param array<array-key, mixed> $cookieParams
     */
    public static function read(array $cookieParams, string $base, bool $secure): string
    {
        $value = $cookieParams[self::name($base, $secure)] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The Set-Cookie header for a cookie that lives `$maxAge` seconds; 0
     * deletes it.
     */
    public static function header(string $base, #[SensitiveParameter] string $value, int $maxAge, bool $secure): string
    {
        return self::name($base, $secure) . '=' . $value
            . '; Max-Age=' . max(0, $maxAge)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . ($secure ? '; Secure' : '');
    }
}
