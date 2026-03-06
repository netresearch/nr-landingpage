<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Event;

use Netresearch\NrLandingpage\Domain\Model\Template;

final class AfterContentGenerationEvent
{
    /**
     * @param list<int> $contentElementUids
     */
    public function __construct(
        public readonly Template $template,
        public readonly int $pageUid,
        public readonly array $contentElementUids,
    ) {}
}
