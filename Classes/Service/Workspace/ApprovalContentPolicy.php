<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\PageCreatorService;

/**
 * What an approval may create from a draft (ADR-0001, section 9).
 *
 * The sections and page fields of a draft come from its owner, but the page is
 * created with the rights of whoever approves, who may be an administrator.
 * Without a limit the approval would launder the owner's content: a content
 * type or page field the owner may not use is created anyway. The limit is the
 * template's own list where it has one, and otherwise a fixed set: the content
 * types the wizard's structured mode produces and the metadata fields of a
 * page. A draft outside the limit is refused as a whole, before it is approved.
 */
final class ApprovalContentPolicy
{
    /** The content types the structured wizard produces when its template names none. */
    public const DEFAULT_CTYPES = ['text', 'textmedia', 'textpic'];

    /** The content type of a creative template's sections, which are sanitised HTML. */
    private const CREATIVE_CTYPES = ['html'];

    /** The page fields a draft may set when its template names none: titles, descriptions and SEO metadata. */
    public const DEFAULT_PAGE_FIELDS = [
        'title',
        'subtitle',
        'nav_title',
        'seo_title',
        'description',
        'abstract',
        'keywords',
        'author',
        'no_index',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    /**
     * Whether the page creation applies this page field: not a reserved one,
     * not empty, and in the template's list when it has one.
     */
    public function isPageFieldOutsideLimit(Template $template, string $field): bool
    {
        if (in_array($field, PageCreatorService::RESERVED_PAGE_FIELDS, true)) {
            return false;
        }

        return $template->pageFields === [] && !in_array($field, self::DEFAULT_PAGE_FIELDS, true);
    }

    /**
     * @param list<array{section: string, ctype: string}> $sections the sections as the page creation takes them
     * @throws WorkspaceRefusedException naming the first section whose content type is not allowed
     */
    public function assertSections(Template $template, array $sections): void
    {
        if (!$template->isCreativeMode() && $template->allowedCTypes !== []) {
            // The template's own list applies: the section normalisation turned
            // every other content type into text before this point.
            return;
        }
        $allowed = $template->isCreativeMode() ? self::CREATIVE_CTYPES : self::DEFAULT_CTYPES;
        foreach ($sections as $index => $section) {
            if (in_array($section['ctype'], $allowed, true)) {
                continue;
            }
            $name = $section['section'] !== '' ? ' (' . $this->plain($section['section']) . ')' : '';

            throw new WorkspaceRefusedException(
                sprintf(
                    'Section %d%s uses the content type "%s", which an approval does not create. Allowed: %s.',
                    $index + 1,
                    $name,
                    $this->plain($section['ctype']),
                    implode(', ', $allowed),
                ),
                1799600113,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $pageFields the draft's stored page fields
     * @throws WorkspaceRefusedException naming the first page field that is not allowed
     */
    public function assertPageFields(Template $template, array $pageFields): void
    {
        foreach ($pageFields as $field => $value) {
            if (!is_string($field) || !is_string($value) || $value === '') {
                continue;
            }
            if ($this->isPageFieldOutsideLimit($template, $field)) {
                throw new WorkspaceRefusedException(
                    sprintf('The page field "%s" is not one an approval sets. Allowed: %s.', $this->plain($field), implode(', ', self::DEFAULT_PAGE_FIELDS)),
                    1799600114,
                );
            }
        }
    }

    /**
     * A value the message shows: one line, no control characters, bounded.
     */
    private function plain(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '', 0, 60);
    }
}
