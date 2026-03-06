<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Event;

use Netresearch\NrLandingpage\Domain\Model\Template;

final class BeforePageCreationEvent
{
    /**
     * @param array<string, mixed> $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function __construct(
        public readonly Template $template,
        public readonly int $parentPageId,
        public array $pageData,
        public array $contentElements,
    ) {}
}
