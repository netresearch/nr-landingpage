<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Netresearch\NrLandingpage\Domain\Model\SeoFinding;

/**
 * Checks a generated landing page against a few SEO rules, before it is saved:
 *
 * - the title the search engines show: present, 30 to 60 characters
 *   (the SEO title, or the page title when there is none);
 * - the meta description: present, 120 to 160 characters;
 * - the order of the headings: the page title counts as the h1 and the header
 *   of a section as an h2; a heading may be at most one level deeper than the
 *   one before it, and the content holds no second h1;
 * - alternative texts: an <img> in the content has an alt attribute with text,
 *   and so does the file chosen as the image of a section.
 *
 * The check only reports. Nothing is changed and nothing is blocked.
 */
final class SeoCheckService
{
    public const TITLE_MIN = 30;

    public const TITLE_MAX = 60;

    public const DESCRIPTION_MIN = 120;

    public const DESCRIPTION_MAX = 160;

    /** Characters of a heading text that a finding quotes. */
    private const QUOTE_LENGTH = 40;

    /**
     * @param list<array{section?: mixed, header?: mixed, bodytext?: mixed, imageUid?: mixed}> $sections
     * @param array<int, string> $imageAlternatives alternative text of the image files by uid, for the files the editor sees
     * @return list<SeoFinding>
     */
    public function check(string $title, string $seoTitle, string $description, array $sections, array $imageAlternatives = []): array
    {
        $findings = [...$this->checkTitle($title, $seoTitle), ...$this->checkDescription($description)];

        $level = $title !== '' ? 1 : 0;
        foreach (array_values($sections) as $index => $section) {
            $header = is_string($section['header'] ?? null) ? trim($section['header']) : '';
            $body = is_string($section['bodytext'] ?? null) ? $section['bodytext'] : '';

            if ($header !== '') {
                $level = $this->checkHeading(2, $header, $level, $index, $findings);
            }
            $level = $this->checkBody($body, $level, $index, $findings);

            $imageUid = is_numeric($section['imageUid'] ?? null) ? (int) $section['imageUid'] : 0;
            if ($imageUid > 0 && isset($imageAlternatives[$imageUid]) && trim($imageAlternatives[$imageUid]) === '') {
                $findings[] = new SeoFinding('image-alt-missing', SeoFinding::WARNING, [], $index);
            }
        }

        return $findings;
    }

    /** @return list<SeoFinding> */
    private function checkTitle(string $title, string $seoTitle): array
    {
        $shown = trim($seoTitle) !== '' ? trim($seoTitle) : trim($title);
        if ($shown === '') {
            return [new SeoFinding('title-missing', SeoFinding::ERROR)];
        }
        $length = mb_strlen($shown);
        if ($length < self::TITLE_MIN) {
            return [new SeoFinding('title-short', SeoFinding::WARNING, [$length, self::TITLE_MIN])];
        }
        if ($length > self::TITLE_MAX) {
            return [new SeoFinding('title-long', SeoFinding::WARNING, [$length, self::TITLE_MAX])];
        }

        return [];
    }

    /** @return list<SeoFinding> */
    private function checkDescription(string $description): array
    {
        $length = mb_strlen(trim($description));
        if ($length === 0) {
            return [new SeoFinding('description-missing', SeoFinding::WARNING)];
        }
        if ($length < self::DESCRIPTION_MIN) {
            return [new SeoFinding('description-short', SeoFinding::WARNING, [$length, self::DESCRIPTION_MIN])];
        }
        if ($length > self::DESCRIPTION_MAX) {
            return [new SeoFinding('description-long', SeoFinding::WARNING, [$length, self::DESCRIPTION_MAX])];
        }

        return [];
    }

    /**
     * Check the headings and images of one section's HTML, in document order.
     *
     * @param list<SeoFinding> $findings
     * @return int the level of the last heading, for the next section
     */
    private function checkBody(string $html, int $level, int $section, array &$findings): int
    {
        if (trim($html) === '') {
            return $level;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new DOMXPath($document))->query('//h1|//h2|//h3|//h4|//h5|//h6|//img');
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->nodeName === 'img') {
                if (trim($node->getAttribute('alt')) === '') {
                    $findings[] = new SeoFinding('alt-missing', SeoFinding::WARNING, [], $section);
                }
                continue;
            }
            $level = $this->checkHeading((int) substr($node->nodeName, 1), $this->quote($node->textContent), $level, $section, $findings);
        }

        return $level;
    }

    /**
     * @param list<SeoFinding> $findings
     * @return int the level the next heading is compared with
     */
    private function checkHeading(int $heading, string $text, int $level, int $section, array &$findings): int
    {
        $text = $this->quote($text);
        if ($heading === 1) {
            $findings[] = new SeoFinding('heading-h1', SeoFinding::WARNING, [$text], $section);

            return $level;
        }
        if ($level > 0 && $heading > $level + 1) {
            $findings[] = new SeoFinding('heading-skip', SeoFinding::WARNING, [$level, $heading, $text], $section);
        }

        return $heading;
    }

    private function quote(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::QUOTE_LENGTH ? mb_substr($text, 0, self::QUOTE_LENGTH - 1) . '…' : $text;
    }
}
