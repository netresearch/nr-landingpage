<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use RuntimeException;

/**
 * A workspace action the rules refuse. The message is safe to show to the
 * backend user who asked for it; `reason` says which kind of refusal it is.
 */
final class WorkspaceRefusedException extends RuntimeException
{
    /** The input is out of bounds or not allowed. */
    public const INVALID = 'invalid';

    /** The draft does not exist for this user. */
    public const NOT_FOUND = 'not_found';

    /** The draft changed, was approved or reset since the user looked. */
    public const CONFLICT = 'conflict';

    /** The action was tried and failed, and was undone. */
    public const FAILED = 'failed';

    public function __construct(string $message, int $code = 0, public readonly string $reason = self::INVALID)
    {
        parent::__construct($message, $code);
    }
}
