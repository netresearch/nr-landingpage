<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model;

/**
 * One note of the SEO check: which rule, how serious, the numbers or the text
 * it names, and the section it concerns (index in the list of sections, null
 * for the page as a whole). The wizard turns the rule into a sentence in the
 * editor's language.
 */
final readonly class SeoFinding
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @param list<int|string> $params
     */
    public function __construct(
        public string $rule,
        public string $severity,
        public array $params = [],
        public ?int $section = null,
    ) {}

    /**
     * @return array{rule: string, severity: string, params: list<int|string>, section: int|null}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'severity' => $this->severity, 'params' => $this->params, 'section' => $this->section];
    }
}
