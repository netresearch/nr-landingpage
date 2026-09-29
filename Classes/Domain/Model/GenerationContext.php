<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model;

/**
 * Captures the inputs used to generate a landing page.
 *
 * Stored on the created page so re-generation can pre-fill the wizard.
 */
final readonly class GenerationContext
{
    /**
     * @param array<int|string, mixed> $briefingAnswers Editor's briefing responses
     * @param int $sourcePageUid Original page UID when re-generating (0 for first generation)
     */
    public function __construct(
        public array $briefingAnswers = [],
        public int $sourcePageUid = 0,
    ) {}
}
