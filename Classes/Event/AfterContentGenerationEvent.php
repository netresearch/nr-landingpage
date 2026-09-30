<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

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
