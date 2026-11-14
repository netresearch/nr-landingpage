<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\SectionNormalizer;

/**
 * Creates a workspace draft from what the wizard holds in its content step
 * (ADR-0001). The sections go through the same normalisation as the wizard's
 * save, and each gets a stable id.
 */
class DraftService
{
    public const MAX_SECTIONS = 60;

    /** The most a draft's sections may take as JSON, in bytes. */
    public const MAX_SECTIONS_BYTES = 1_000_000;

    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly SectionNormalizer $sectionNormalizer,
    ) {}

    /**
     * @param array<array-key, mixed> $briefingAnswers
     * @param array<string, string> $pageFields
     * @param array<array-key, mixed> $rawSections
     * @throws WorkspaceRefusedException for a creative template, an empty title or no section
     * @throws \Netresearch\NrLandingpage\Service\ImageNotReadableException
     */
    public function create(
        Template $template,
        string $title,
        int $parentPageUid,
        array $briefingAnswers,
        array $pageFields,
        array $rawSections,
        int $owner,
        int $now,
    ): int {
        if ($template->isCreativeMode()) {
            throw new WorkspaceRefusedException('A draft cannot be made from a creative template: its content is HTML and CSS, which cannot be reviewed as text.', 1799400101);
        }
        $title = trim($title);
        if ($title === '') {
            throw new WorkspaceRefusedException('A draft needs a title.', 1799400102);
        }

        $normalized = $this->sectionNormalizer->normalize($rawSections, $template);
        if ($normalized === []) {
            throw new WorkspaceRefusedException('A draft needs at least one section.', 1799400103);
        }
        if (count($normalized) > self::MAX_SECTIONS) {
            throw new WorkspaceRefusedException(sprintf('A draft has at most %d sections.', self::MAX_SECTIONS), 1799400104);
        }
        $animations = $this->sectionNormalizer->animations($rawSections);

        $sections = [];
        foreach ($normalized as $index => $section) {
            $sections[] = ['id' => bin2hex(random_bytes(8))] + $section + ['animation' => $animations[$index] ?? []];
        }

        if (strlen(WorkspaceDraft::encode($sections)) > self::MAX_SECTIONS_BYTES) {
            throw new WorkspaceRefusedException('The content of the draft is too large.', 1799400105);
        }

        // A pasted source text is not stored: it can be long and can hold what
        // the editor would not want kept (the wizard's save drops it too).
        unset($briefingAnswers[ContentGeneratorService::SOURCE_TEXT_KEY]);

        return $this->drafts->insert(
            $owner,
            $title,
            $template->uid,
            $parentPageUid,
            $briefingAnswers,
            $pageFields,
            $sections,
            [],
            $now,
        );
    }
}
