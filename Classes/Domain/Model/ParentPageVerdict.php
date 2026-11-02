<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Domain\Model;

/**
 * Whether the current user may create a landing page below a page, and if
 * not, why (see BackendAccessGuard::checkParentPage()).
 */
enum ParentPageVerdict: string
{
    case Allowed = 'allowed';

    /** The page does not exist, is deleted, or lies outside the user's page tree. */
    case NotFound = 'notFound';

    /** The user sees the page but may not create pages below it. */
    case NoPermission = 'noPermission';

    /** The page's type cannot hold subpages. */
    case NoSubpages = 'noSubpages';

    /**
     * The label key, in locallang.xlf, of the message for the editor.
     */
    public function messageKey(): string
    {
        return 'wizard.parentPage.error.' . $this->value;
    }
}
