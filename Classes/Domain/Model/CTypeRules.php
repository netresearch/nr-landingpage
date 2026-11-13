<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model;

/**
 * The rules an editor wrote per content element type (CType) in a template.
 *
 * One rule per line, "ctype: rule". A CType may have several lines. Blank lines
 * and lines that start with # are comments. A line without a valid CType name
 * or without a rule, a CType the template does not allow, and everything
 * beyond MAX_RULES rules is left out and listed in $ignored, so the caller can
 * say what was not used.
 */
final readonly class CTypeRules
{
    public const MAX_RULES = 30;

    public const MAX_RULE_LENGTH = 500;

    /**
     * @param array<string, list<string>> $rules CType => rules, in the order written
     * @param list<string> $ignored the lines that were not used
     */
    private function __construct(
        public array $rules,
        public array $ignored,
    ) {}

    /**
     * @param list<string> $allowedCTypes empty: every CType is allowed
     */
    public static function parse(string $text, array $allowedCTypes = []): self
    {
        $rules = [];
        $ignored = [];
        $count = 0;

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*:\s*(\S.*)$/u', $line, $match) !== 1) {
                $ignored[] = $line;
                continue;
            }
            [, $cType, $rule] = $match;
            $cType = strtolower($cType);
            if (($allowedCTypes !== [] && !in_array($cType, $allowedCTypes, true)) || $count >= self::MAX_RULES) {
                $ignored[] = $line;
                continue;
            }
            $rules[$cType][] = mb_substr(trim($rule), 0, self::MAX_RULE_LENGTH);
            ++$count;
        }

        return new self($rules, $ignored);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * One line per CType: "- ctype: rule; rule".
     */
    public function toPromptLines(): string
    {
        $lines = [];
        foreach ($this->rules as $cType => $rules) {
            $lines[] = '- ' . $cType . ': ' . implode('; ', $rules);
        }

        return implode("\n", $lines);
    }
}
