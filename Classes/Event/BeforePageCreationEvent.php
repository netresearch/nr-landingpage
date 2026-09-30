<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

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
