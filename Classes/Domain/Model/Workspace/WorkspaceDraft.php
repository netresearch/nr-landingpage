<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

/**
 * A landing page draft: the generated content, kept before the page exists
 * (ADR-0001). Every section carries a stable `id`, so a comment keeps its
 * place when sections are reordered.
 *
 * `version` rises with every change of a column that page creation reads, so
 * an approval can bind to exactly the content an editor was shown.
 */
final readonly class WorkspaceDraft
{
    /**
     * @param list<int> $beGroups groups the owner shared the draft with
     * @param array<array-key, mixed> $briefingData
     * @param array<string, string> $pageFields
     * @param list<array<string, mixed>> $sections
     */
    public function __construct(
        public int $uid,
        public int $owner,
        public array $beGroups,
        public string $title,
        public int $templateUid,
        public int $parentPageUid,
        public array $briefingData,
        public array $pageFields,
        public array $sections,
        public int $version,
        public DraftStatus $status,
        public int $approvedBy,
        public int $approvedAt,
        public int $approvedVersion,
        public int $createdPageUid,
        public int $crdate,
        public int $tstamp,
        public string $approvalAttempt = '',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int = static fn(string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string = static fn(string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

        $groups = array_values(array_filter(
            array_map(intval(...), explode(',', $string('be_groups'))),
            static fn(int $group): bool => $group > 0,
        ));

        $briefing = self::decode($string('briefing_data'));
        $pageFields = [];
        foreach (self::decode($string('page_fields')) as $field => $value) {
            if (is_string($value)) {
                $pageFields[(string) $field] = $value;
            }
        }
        $sections = [];
        foreach (self::decode($string('sections')) as $section) {
            if (is_array($section)) {
                /** @var array<string, mixed> $section */
                $sections[] = $section;
            }
        }

        return new self(
            uid: $int('uid'),
            owner: $int('owner'),
            beGroups: $groups,
            title: $string('title'),
            templateUid: $int('template_uid'),
            parentPageUid: $int('parent_page_uid'),
            briefingData: $briefing,
            pageFields: $pageFields,
            sections: $sections,
            version: max(1, $int('version')),
            status: DraftStatus::tryFrom($string('status')) ?? DraftStatus::Draft,
            approvedBy: $int('approved_by'),
            approvedAt: $int('approved_at'),
            approvedVersion: $int('approved_version'),
            createdPageUid: $int('created_page_uid'),
            crdate: $int('crdate'),
            tstamp: $int('tstamp'),
            approvalAttempt: $string('approval_attempt'),
        );
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function isDraft(): bool
    {
        return $this->status === DraftStatus::Draft;
    }
}
