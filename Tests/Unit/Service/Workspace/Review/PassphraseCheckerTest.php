<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review;

use Netresearch\NrLandingpage\Service\Workspace\Review\PassphraseChecker;
use Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review\Fixtures\CountingBcryptPasswordHash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

#[CoversClass(PassphraseChecker::class)]
final class PassphraseCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms'] = [BcryptPasswordHash::class];
        $GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing'] = ['className' => BcryptPasswordHash::class, 'options' => ['cost' => 10]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing'], $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms']);
        parent::tearDown();
    }

    #[Test]
    public function aLinkWithAPassphraseNeedsTheRightOne(): void
    {
        $hash = (new PasswordHashFactory())->getDefaultHashInstance('FE')->getHashedPassword('correct horse');
        self::assertIsString($hash);
        $checker = new PassphraseChecker(new PasswordHashFactory());

        self::assertTrue($checker->matches($hash, 'correct horse'));
        self::assertFalse($checker->matches($hash, 'wrong horse'));
        self::assertFalse($checker->matches($hash, ''));
    }

    #[Test]
    public function aLinkWithoutAPassphraseAcceptsAnything(): void
    {
        $checker = new PassphraseChecker(new PasswordHashFactory());

        self::assertTrue($checker->matches('', ''));
        self::assertTrue($checker->matches('', 'something nobody needs'));
    }

    #[Test]
    public function anUnreadableStoredHashMatchesNothing(): void
    {
        $checker = new PassphraseChecker(new PasswordHashFactory());

        self::assertFalse($checker->matches('not a hash of any known algorithm', 'x'));
    }

    #[Test]
    public function everyCaseDoesExactlyOnePasswordHashOperation(): void
    {
        // The factory builds the configured class with makeInstance(), so a
        // counting subclass in the configuration sees every operation.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms'] = [CountingBcryptPasswordHash::class];
        $GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing'] = ['className' => CountingBcryptPasswordHash::class, 'options' => ['cost' => 10]];
        $factory = new PasswordHashFactory();
        $hash = $factory->getDefaultHashInstance('FE')->getHashedPassword('correct horse');
        self::assertIsString($hash);
        $checker = new PassphraseChecker($factory);

        $cases = [
            'unknown link (no hash)' => ['', 'x'],
            'link without passphrase' => ['', ''],
            'right passphrase' => [$hash, 'correct horse'],
            'wrong passphrase' => [$hash, 'wrong'],
        ];
        foreach ($cases as $name => [$stored, $given]) {
            CountingBcryptPasswordHash::$operations = 0;
            $checker->matches($stored, $given);
            self::assertSame(1, CountingBcryptPasswordHash::$operations, $name);
        }
    }
}
