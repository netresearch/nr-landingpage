<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

/**
 * What a share link allows. Each level includes the one before it.
 */
enum LinkPermission: string
{
    case View = 'view';
    case Comment = 'comment';
    case Edit = 'edit';

    public function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Comment => 2,
            self::Edit => 3,
        };
    }

    /**
     * Whether a link of this level may do what the given level allows.
     */
    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }
}
