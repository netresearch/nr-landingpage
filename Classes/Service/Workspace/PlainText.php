<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

/**
 * The two directions between the HTML body text of a section and the plain
 * text that reviewers read and write (ADR-0001, section 6).
 */
final class PlainText
{
    /**
     * The text of generated HTML, with paragraphs and list items on lines of
     * their own.
     */
    public static function fromHtml(string $html): string
    {
        $withBreaks = preg_replace('#</(p|li|h[1-6]|div)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * Body text HTML from plain text: one paragraph per block of lines
     * separated by an empty line, everything escaped. This is the only way
     * text a reviewer wrote reaches a `bodytext` field.
     */
    public static function toBodytextHtml(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $normalized) ?? '';

        $paragraphs = [];
        foreach (preg_split("/\n{2,}/", trim($normalized)) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '') {
                $paragraphs[] = '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }

        return implode("\n", $paragraphs);
    }
}
