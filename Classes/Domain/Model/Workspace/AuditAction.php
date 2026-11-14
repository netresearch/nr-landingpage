<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model\Workspace;

/**
 * What the append-only audit table records (ADR-0001, section 2).
 */
enum AuditAction: string
{
    case LinkCreated = 'link_created';
    case LinkOpened = 'link_opened';
    case LinkRevoked = 'link_revoked';
    case SuggestionAccepted = 'suggestion_accepted';
    case SuggestionRejected = 'suggestion_rejected';
    case Approved = 'approved';
    case PageCreated = 'page_created';
    case ApprovalReverted = 'approval_reverted';
    case ApprovalReset = 'approval_reset';
}
