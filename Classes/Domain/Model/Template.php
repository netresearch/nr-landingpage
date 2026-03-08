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
        public string $backendLayout = '',
        public string $promptOptimizerContext = '',
        public string $promptOptimizerMetaPrompt = '',
        public int $imageTask = 0,
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

    public function hasImageTask(): bool
    {
        return $this->imageTask > 0;
    }

    /**
     * SHA-256 hash over content-relevant template configuration.
     *
     * Used to detect whether a template has changed since a page was generated.
     * Only fields that influence the generated output are included.
     */
    public function getConfigHash(): string
    {
        $ctypes = $this->allowedCTypes;
        sort($ctypes);
        $fields = $this->pageFields;
        sort($fields);
        $refs = $this->referencePages;
        sort($refs);

        $data = implode('|', [
            $this->systemPrompt,
            implode(',', $ctypes),
            implode(',', $fields),
            implode(',', $refs),
            $this->backendLayout,
            $this->briefingMode,
            (string) $this->llmConfiguration,
            (string) $this->imageTask,
        ]);

        return hash('sha256', $data);
    }
}
