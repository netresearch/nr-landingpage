<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

/**
 * Removes everything from creative mode CSS that loads a resource from
 * outside the page: url() and the other resource functions, @import, and the
 * legacy behaviour/binding properties.
 *
 * The filter works on the CSS as the browser reads it: comments are removed
 * and CSS escape sequences are decoded first, so an escaped spelling of a
 * function name is caught like the plain one. The result contains no
 * backslash escapes except the one written for "<", which keeps a style
 * element from being closed by its own content.
 *
 * This is a resource-loading rule. It is not what keeps scripts out of the
 * page; that is the element and attribute allowlist in CreativeHtmlSanitizer.
 */
final class CreativeCssFilter
{
    /**
     * CSS functions that take a URL or an image source.
     */
    private const RESOURCE_FUNCTION = '#(?<![\w-])(?:-[a-z]+-)?(?:url|image-set|image|src|cross-fade|element|expression|paint)\s*\(#i';

    public function filter(string $css): string
    {
        $css = $this->normalize($css);

        // @import loads a stylesheet, with or without url(). Removing one rule can
        // join the text around it into a new one, so repeat until nothing changes.
        do {
            $before = $css;
            $css = preg_replace('#@import\b[^;{}]*;?#i', '', $css) ?? '';
        } while ($css !== $before);

        $css = $this->replaceResourceFunctions($css);

        // Legacy properties that attach behaviour (IE behavior, Gecko XBL binding).
        $css = preg_replace('#(?<![\w-])(?:behavior|-moz-binding)\s*:#i', 'x-removed:', $css) ?? '';

        return str_replace('<', '\\3c ', $css);
    }

    /**
     * Whether an SVG attribute value refers to nothing outside the document:
     * no resource function except url(#fragment), no escape sequences.
     */
    public function isLocalValue(string $value): bool
    {
        if (str_contains($value, '\\')) {
            return false;
        }
        $withoutLocalUrls = preg_replace('#url\(\s*([\'"]?)\#[A-Za-z][\w.-]*\1\s*\)#i', '', $value) ?? $value;

        return preg_match(self::RESOURCE_FUNCTION, $withoutLocalUrls) !== 1;
    }

    /**
     * Remove comments, decode escapes, then drop every backslash left over
     * (an escaped backslash must not start a new escape after decoding).
     */
    private function normalize(string $css): string
    {
        $css = preg_replace('#/\*.*?(?:\*/|$)#s', '', $css) ?? '';

        $css = preg_replace_callback(
            '#\\\\(?:([0-9a-fA-F]{1,6})[ \t\r\n\f]?|(\r\n|[\n\r\f])|(.))#su',
            static function (array $m): string {
                if ($m[1] !== '') {
                    $codePoint = (int) hexdec($m[1]);
                    if ($codePoint === 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                        return "\u{FFFD}";
                    }

                    return mb_chr($codePoint, 'UTF-8') ?: "\u{FFFD}";
                }
                if (($m[2] ?? '') !== '') {
                    return '';
                }

                return $m[3] ?? '';
            },
            $css,
        ) ?? '';

        return str_replace('\\', '', $css);
    }

    /**
     * Replace each resource function call, arguments included, with "none".
     */
    private function replaceResourceFunctions(string $css): string
    {
        $result = '';
        $offset = 0;
        while (preg_match(self::RESOURCE_FUNCTION, $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $end = $this->findClosingParenthesis($css, $start + strlen($match[0][0]));
            $result .= substr($css, $offset, $start - $offset) . 'none';
            $offset = $end;
        }

        return $result . substr($css, $offset);
    }

    /**
     * Offset just behind the parenthesis that closes the call opened before
     * $position, honouring quoted strings and nesting; end of input if none.
     */
    private function findClosingParenthesis(string $css, int $position): int
    {
        $depth = 1;
        $quote = '';
        $length = strlen($css);
        for ($i = $position; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $length;
    }
}
