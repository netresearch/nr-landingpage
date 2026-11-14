<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewSession;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ShareLink;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use SensitiveParameter;

/**
 * Who is calling the review page: the session, the one link behind it and the
 * one draft that link grants. Nothing else is reachable from here.
 */
final readonly class ReviewContext
{
    public function __construct(
        public ReviewSession $session,
        public ShareLink $link,
        public WorkspaceDraft $draft,
        #[SensitiveParameter]
        public string $sessionId,
    ) {}

    public function permission(): LinkPermission
    {
        return $this->link->permission;
    }

    public function may(LinkPermission $needed): bool
    {
        return $this->link->permission->allows($needed);
    }
}
