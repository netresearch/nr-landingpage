<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementComposer;
use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementDataMap;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The child records of a structured section: the items of an accordion, a
 * card group, an icon group or a timeline (NEXT-229).
 *
 * The shape comes from nr-llm's content-type rule (ADR-324 there), so the
 * Page Composer and the backend assistant fill the same content types the
 * same way: which inline columns a type has, which columns their records
 * take, and the datamap that writes them with the element. A section carries
 * its children as `{inline column: [{column: value}]}`.
 */
class SectionChildren
{
    public function __construct(
        private readonly ContentElementComposer $composer,
        private readonly StructuredHtmlSanitizer $sanitizer,
    ) {}

    /**
     * The child-record columns of each given type that has any, as a model
     * reads them. With a user, only the columns that user may set.
     *
     * @param list<string> $cTypes
     * @return array<string, array<string, array{table: string, maxItems: int, fields: array<string, array<string, mixed>>}>>
     */
    public function describe(array $cTypes, ?BackendUserAuthentication $user = null, ?LanguageService $languageService = null): array
    {
        $described = [];
        foreach ($cTypes as $cType) {
            $children = $this->composer->describe($cType, $languageService, $user)['children'] ?? [];
            if ($children !== []) {
                $described[$cType] = $children;
            }
        }

        return $described;
    }

    /**
     * The children of a section as they may be stored and written: only the
     * child-record columns and record columns the type describes (with a
     * user, those the user may set), scalar values as text, rich text
     * sanitised, at most `maxItems` records per column, records without any
     * value dropped. Anything else is left out without a word: the result is
     * what the editor sees and what is written.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function clean(string $cType, mixed $raw, ?BackendUserAuthentication $user = null): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $described = $this->describe([$cType], $user)[$cType] ?? [];
        $clean = [];
        foreach ($described as $column => $collection) {
            $records = $raw[$column] ?? null;
            if (!is_array($records) || !array_is_list($records)) {
                continue;
            }

            $rows = [];
            foreach (array_slice($records, 0, $collection['maxItems']) as $record) {
                $row = $this->cleanRecord($record, $collection['fields']);
                if ($row !== []) {
                    $rows[] = $row;
                }
            }

            if ($rows !== []) {
                $clean[$column] = $rows;
            }
        }

        return $clean;
    }

    /**
     * The content types among `$candidates` the user may create on the page:
     * its `TCEFORM.tt_content.CType` rules (not read for page 0) and the
     * user's grants, the child records included.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    public function offeredOnPage(int $pageUid, BackendUserAuthentication $user, array $candidates): array
    {
        return $this->composer->typesOfferedOnPage($pageUid, $user, $candidates);
    }

    /**
     * The datamap of one element's children, checked as nr-llm checks a
     * draft: values, grants and the page TSconfig of `$pageUid`.
     *
     * @param array<string, list<array<string, string>>> $children
     */
    public function dataMap(string $cType, string $placeholder, array $children, int|string $pid, int $pageUid, BackendUserAuthentication $user): ContentElementDataMap
    {
        return $this->composer->dataMap($cType, $placeholder, [], $children, $pid, $pageUid, 0, $user);
    }

    /**
     * @param array<string, array<string, mixed>> $fields the record columns the type describes
     * @return array<string, string>
     */
    private function cleanRecord(mixed $record, array $fields): array
    {
        if (!is_array($record)) {
            return [];
        }

        $row = [];
        foreach ($fields as $name => $field) {
            $value = $record[$name] ?? null;
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue;
            }

            $text = trim((string) $value);
            if (($field['richText'] ?? false) === true) {
                $text = $this->sanitizer->sanitize($text);
            }

            if ($text !== '') {
                $row[$name] = $text;
            }
        }

        // A record without a required value could not be written; it is left
        // out here rather than refused at the save.
        foreach ($fields as $name => $field) {
            if (($field['required'] ?? false) === true && !isset($row[$name])) {
                return [];
            }
        }

        return $row;
    }
}
