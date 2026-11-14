<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review\Fixtures;

use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;

/**
 * Bcrypt that counts its hash operations.
 */
final class CountingBcryptPasswordHash extends BcryptPasswordHash
{
    public static int $operations = 0;

    public function checkPassword(string $plainPW, string $saltedHashPW): bool
    {
        ++self::$operations;

        return parent::checkPassword($plainPW, $saltedHashPW);
    }

    public function getHashedPassword(string $password): ?string
    {
        ++self::$operations;

        return parent::getHashedPassword($password);
    }
}
