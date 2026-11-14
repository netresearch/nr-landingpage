<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

/**
 * The state of a workspace draft. Only a draft takes writes.
 */
enum DraftStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Created = 'created';
}
