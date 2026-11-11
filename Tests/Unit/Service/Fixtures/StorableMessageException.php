<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Fixtures;

use RuntimeException;

/**
 * Stands in for nr-llm's GuardrailViolationException with storableMessage(),
 * which the released nr-llm the tests install does not have.
 */
final class StorableMessageException extends RuntimeException
{
    public function __construct(string $message, private readonly string $storable)
    {
        parent::__construct($message);
    }

    public function storableMessage(): string
    {
        return $this->storable;
    }
}
