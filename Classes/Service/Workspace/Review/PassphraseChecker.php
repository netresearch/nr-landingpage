<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use SensitiveParameter;
use Throwable;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

/**
 * Checks the passphrase of a share link with exactly one password-hash
 * operation, whatever the case (ADR-0001, section 4): `checkPassword()` against
 * the link's hash when it has one, `getHashedPassword()` on the given text
 * otherwise. Entering an unknown link, a link without passphrase or a link with
 * one therefore costs the same.
 */
class PassphraseChecker
{
    public function __construct(
        private readonly PasswordHashFactory $passwordHashFactory,
    ) {}

    /**
     * @param string $storedHash the link's passphrase hash, '' when it has none or there is no link
     */
    public function matches(string $storedHash, #[SensitiveParameter] string $passphrase): bool
    {
        if ($storedHash === '') {
            // Same work, no result: the answer for "no passphrase needed" is yes
            // for a real link and does not matter for an unknown one.
            $this->passwordHashFactory->getDefaultHashInstance('FE')->getHashedPassword($passphrase);

            return true;
        }

        try {
            return $this->passwordHashFactory->get($storedHash, 'FE')->checkPassword($passphrase, $storedHash);
        } catch (Throwable) {
            // A hash the installation can no longer read matches nothing.
            return false;
        }
    }
}
