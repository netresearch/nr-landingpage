<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\DataHandling;

/**
 * The one marker value DataHandler may write to `pages.tx_nrlandingpage_workspace_ref`
 * while the approval creates its page (ADR-0001, section 9). Everything else
 * written to that field is dropped by WorkspaceRefDataHandlerHook, so the
 * marker an approval deletes by can only come from an approval.
 */
final class WorkspaceRefGate
{
    private static string $permitted = '';

    public static function permit(string $ref): void
    {
        self::$permitted = $ref;
    }

    public static function release(): void
    {
        self::$permitted = '';
    }

    public static function allows(mixed $value): bool
    {
        return self::$permitted !== '' && $value === self::$permitted;
    }
}
