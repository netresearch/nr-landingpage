<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

/**
 * The child records of a stored section (NEXT-229) as plain text, one list of
 * values per record, for the reviewers of a draft: what an approval would
 * write besides the section's own header and text.
 */
final class SectionItemsText
{
    /**
     * @param array<array-key, mixed> $section
     * @return list<list<string>>
     */
    public static function of(array $section): array
    {
        $children = $section['children'] ?? null;
        if (!is_array($children)) {
            return [];
        }

        $items = [];
        foreach ($children as $records) {
            foreach (is_array($records) ? $records : [] as $record) {
                $values = [];
                foreach (is_array($record) ? $record : [] as $value) {
                    $text = is_string($value) ? trim(PlainText::fromHtml($value)) : '';
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }
                if ($values !== []) {
                    $items[] = $values;
                }
            }
        }

        return $items;
    }
}
