<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use RuntimeException;

/**
 * A write through a share link the rules refuse. `reason` says which rule,
 * so the page can answer with the right status.
 */
final class ReviewRefusedException extends RuntimeException
{
    public const FORBIDDEN = 'forbidden';

    public const INVALID = 'invalid';

    /** A bound on how much a link can add to a draft is reached. */
    public const LIMIT = 'limit';

    /** Someone wrote first, or the draft is no longer open. */
    public const CONFLICT = 'conflict';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
