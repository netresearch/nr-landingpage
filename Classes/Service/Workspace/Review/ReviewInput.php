<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

/**
 * Cleans what a reviewer types. Everything is plain text: nothing here
 * escapes it for HTML, that happens on output (ADR-0001, section 5).
 */
final class ReviewInput
{
    public const NAME_MAX = 80;

    public const COMMENT_MAX = 2000;

    public const SUGGESTION_MAX = 4000;

    public const DEFAULT_NAME = 'Reviewer';

    /**
     * A reviewer's name: no control characters, single spaces, at most 80
     * characters. Empty becomes the default; it is untrusted display text
     * and never identifies a backend user.
     */
    public static function name(string $raw): string
    {
        $clean = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = trim(mb_substr($clean, 0, self::NAME_MAX));

        return $clean === '' ? self::DEFAULT_NAME : $clean;
    }

    /**
     * Free text: line breaks normalised to \n, control characters other than
     * a line break and a tab removed, outer white space trimmed. The length is
     * not cut here: the caller refuses a text that is too long.
     */
    public static function text(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = preg_replace('/[\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';

        return trim($text);
    }
}
