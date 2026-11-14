<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

enum CommentKind: string
{
    case Comment = 'comment';
    case Suggestion = 'suggestion';
    case Edit = 'edit';
}
