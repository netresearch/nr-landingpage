<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

/**
 * The choices of an item's columns as nr-llm describes them: `items`, or
 * `itemsBy` where they depend on another column of the item, such as the icons
 * of an icon set (nr-llm ADR-326).
 */
final readonly class ItemChoices
{
    /** A list longer than this is written once in the prompt and referenced. */
    private const MAX_INLINE = 30;

    /**
     * The item without the values that are none of their column's choices. A
     * choice is compared without case and kept as it is described. A column
     * the choices of another depend on is settled before that one.
     *
     * @param array<string, string> $row
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, string>
     */
    public static function keep(array $row, array $fields): array
    {
        $dependent = array_filter($fields, static fn(array $field): bool => is_array($field['itemsBy'] ?? null));
        foreach ([...array_diff_key($fields, $dependent), ...$dependent] as $name => $field) {
            $choices = self::choicesOf($field, $row);
            if ($choices === null || !isset($row[$name])) {
                continue;
            }

            $choice = self::match($row[$name], $choices);
            if ($choice === null) {
                unset($row[$name]);
            } else {
                $row[$name] = $choice;
            }
        }

        return $row;
    }

    /**
     * The described child columns with long lists taken out, each list once.
     *
     * @param array<string, array<string, array{table: string, maxItems: int, fields: array<string, array<string, mixed>>}>> $described
     * @return array{described: array<string, mixed>, lists: array<string, string>}
     */
    public static function compact(array $described): array
    {
        $lists = [];
        $compact = static function (mixed $names) use (&$lists): mixed {
            if (!is_array($names) || count($names) <= self::MAX_INLINE) {
                return $names;
            }

            $text = implode(', ', self::strings($names));
            $key = array_search($text, $lists, true);
            if ($key === false) {
                $key = 'Liste ' . (count($lists) + 1);
                $lists[$key] = $text;
            }

            return $key;
        };

        foreach ($described as $cType => $columns) {
            foreach ($columns as $column => $collection) {
                foreach ($collection['fields'] as $name => $field) {
                    if (isset($field['items'])) {
                        $field['items'] = $compact($field['items']);
                    }

                    if (is_array($field['itemsBy'] ?? null) && is_array($field['itemsBy']['items'] ?? null)) {
                        $field['itemsBy']['items'] = array_map($compact, $field['itemsBy']['items']);
                    }

                    $described[$cType][$column]['fields'][$name] = $field;
                }
            }
        }

        return ['described' => $described, 'lists' => $lists];
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $row
     * @return list<string>|null null for a column without choices
     */
    private static function choicesOf(array $field, array $row): ?array
    {
        // A list that may leave choices out decides nothing; nr-llm checks at the save.
        if (($field['itemsPartial'] ?? false) === true) {
            return null;
        }

        $by = $field['itemsBy'] ?? null;
        if (is_array($by)) {
            $column = $by['column'] ?? '';
            $sets = is_array($by['items'] ?? null) ? $by['items'] : [];
            $default = is_string($by['default'] ?? null) ? $by['default'] : '';
            $set = is_string($column) ? ($sets[$row[$column] ?? $default] ?? []) : [];

            return is_array($set) ? self::strings($set) : [];
        }

        $items = $field['items'] ?? null;

        return in_array($field['type'] ?? '', ['select', 'radio'], true) && is_array($items) ? self::strings($items) : null;
    }

    /**
     * @param list<string> $choices
     */
    private static function match(string $value, array $choices): ?string
    {
        if (in_array($value, $choices, true)) {
            return $value;
        }

        foreach ($choices as $choice) {
            if (mb_strtolower($choice) === mb_strtolower($value)) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }
}
