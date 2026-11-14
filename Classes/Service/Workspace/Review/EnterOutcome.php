<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use SensitiveParameter;

/**
 * What an attempt to open a review session came to. `Invalid` is the one
 * answer for every refusal, whatever its cause (ADR-0001, section 5).
 */
final readonly class EnterOutcome
{
    private function __construct(
        public EnterStatus $status,
        #[SensitiveParameter]
        public string $sessionId,
        public int $sessionLifetime,
        public int $retryAfter,
    ) {}

    public static function opened(#[SensitiveParameter] string $sessionId, int $lifetime): self
    {
        return new self(EnterStatus::Opened, $sessionId, $lifetime, 0);
    }

    public static function invalid(): self
    {
        return new self(EnterStatus::Invalid, '', 0, 0);
    }

    public static function limited(int $retryAfter): self
    {
        return new self(EnterStatus::Limited, '', 0, $retryAfter);
    }
}
