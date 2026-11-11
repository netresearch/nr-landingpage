<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Fixtures;

/**
 * Stands in for nr-llm's GuardrailHintCollector, which the released nr-llm
 * the tests install does not have: it runs the call and reports fixed hints.
 */
final class FakeGuardrailHintCollector
{
    /** @var list<callable> */
    public array $captured = [];

    /**
     * @param list<mixed> $hints
     */
    public function __construct(private readonly array $hints) {}

    /**
     * @return array{result: mixed, hints: list<mixed>}
     */
    public function capture(callable $call): array
    {
        $this->captured[] = $call;

        return ['result' => $call(), 'hints' => $this->hints];
    }
}
