<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;

/**
 * Turns the sections a client sends (the wizard's save and the workspace's
 * draft save) into the typed list the page creation takes.
 *
 * Extracted from the wizard's save action so that both paths apply the same
 * rules: a content type the template does not allow becomes `text`, creative
 * HTML is sanitised again, and an image the user may not read is refused.
 */
class SectionNormalizer
{
    public function __construct(
        private readonly ImageSearchService $imageSearchService,
        private readonly CreativeHtmlSanitizer $creativeHtmlSanitizer,
    ) {}

    /**
     * @param array<array-key, mixed> $rawSections
     * @return list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string, imageUid: int, imageorient: int, colPos: int}>
     * @throws ImageNotReadableException when a section names an image the current user may not read
     */
    public function normalize(array $rawSections, Template $template): array
    {
        $typed = [];
        foreach ($rawSections as $section) {
            if (is_array($section)) {
                $typed[] = $this->normalizeSection($section, $template);
            }
        }

        return $typed;
    }

    /**
     * @param array<array-key, mixed> $section
     * @return array{section: string, ctype: string, header: string, subheader: string, bodytext: string, imageUid: int, imageorient: int, colPos: int}
     * @throws ImageNotReadableException
     */
    private function normalizeSection(array $section, Template $template): array
    {
        $imageUid = $this->intOf($section['imageUid'] ?? 0);
        if ($imageUid > 0 && !$this->imageSearchService->isReadable($imageUid)) {
            throw new ImageNotReadableException('The image ' . $imageUid . ' is not readable for the current user.', 1799400001);
        }

        $bodytext = $this->stringOf($section['bodytext'] ?? null);
        // Re-sanitize creative mode content at save time (editors can edit source in wizard)
        if ($template->isCreativeMode()) {
            $bodytext = $this->creativeHtmlSanitizer->sanitize($bodytext);
        }

        return [
            'section' => $this->stringOf($section['section'] ?? null),
            'ctype' => $this->allowedCType($this->stringOf($section['ctype'] ?? null), $template),
            'header' => $this->stringOf($section['header'] ?? null),
            'subheader' => $this->stringOf($section['subheader'] ?? null),
            'bodytext' => $bodytext,
            'imageUid' => $imageUid,
            'imageorient' => $this->intOf($section['imageorient'] ?? 0),
            'colPos' => $this->intOf($section['colPos'] ?? 0),
        ];
    }

    /**
     * A content type the template does not allow becomes `text`. Creative mode
     * always uses CType "html": the allowlist does not apply.
     */
    private function allowedCType(string $ctype, Template $template): string
    {
        $allowed = $template->allowedCTypes;
        if (!$template->isCreativeMode() && $allowed !== [] && $ctype !== '' && !in_array($ctype, $allowed, true)) {
            return 'text';
        }

        return $ctype;
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The animation of each section, in the order of the raw sections.
     *
     * @param array<array-key, mixed> $rawSections
     * @return array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}>
     */
    public function animations(array $rawSections): array
    {
        /** @var array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}> */
        return array_values(array_map(
            static fn(mixed $section): array => is_array($section) && is_array($section['animation'] ?? null)
                ? $section['animation']
                : [],
            $rawSections,
        ));
    }
}
