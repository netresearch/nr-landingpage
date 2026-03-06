<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Domain\Model;

final readonly class Template
{
    /**
     * @param list<string> $allowedCTypes
     * @param list<string> $pageFields
     * @param list<int> $referencePages
     * @param list<int> $beGroups
     */
    public function __construct(
        public int $uid,
        public string $title,
        public string $identifier,
        public string $description = '',
        public int $llmConfiguration = 0,
        public string $systemPrompt = '',
        public array $allowedCTypes = [],
        public array $pageFields = [],
        public array $referencePages = [],
        public string $briefingMode = 'optional',
        public string $publishMode = 'hidden',
        public array $beGroups = [],
    ) {}

    public function isBriefingRequired(): bool
    {
        return $this->briefingMode === 'required';
    }

    public function isBriefingSkippable(): bool
    {
        return $this->briefingMode === 'optional';
    }

    public function isBriefingDisabled(): bool
    {
        return $this->briefingMode === 'none';
    }

    public function hasReferencePages(): bool
    {
        return $this->referencePages !== [];
    }
}
