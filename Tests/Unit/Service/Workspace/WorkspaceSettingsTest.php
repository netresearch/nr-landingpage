<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Service\Workspace\WorkspaceSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceSettings::class)]
final class WorkspaceSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']);
        parent::tearDown();
    }

    #[Test]
    public function theDefaultsAreSafe(): void
    {
        $settings = new WorkspaceSettings([]);

        self::assertTrue($settings->isEnabled());
        self::assertFalse($settings->allowInsecureHttp());
        self::assertSame(7, $settings->defaultLifetimeDays());
        self::assertSame(30, $settings->maxLifetimeDays());
        self::assertSame('', $settings->publicBaseUrl());
    }

    #[Test]
    #[DataProvider('maxima')]
    public function theMaximumIsBoundedWhateverTheConfigurationSays(mixed $configured, int $expected): void
    {
        self::assertSame($expected, (new WorkspaceSettings(['maxLinkLifetimeDays' => $configured]))->maxLifetimeDays());
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function maxima(): iterable
    {
        yield 'as configured' => ['14', 14];
        yield 'at the ceiling' => ['90', 90];
        yield 'above the ceiling' => ['365', 90];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-5', 1];
        yield 'not a number' => ['soon', 30];
        yield 'missing' => [null, 30];
    }

    #[Test]
    public function theDefaultLifetimeNeverExceedsTheMaximum(): void
    {
        $settings = new WorkspaceSettings(['defaultLinkLifetimeDays' => '60', 'maxLinkLifetimeDays' => '14']);

        self::assertSame(14, $settings->defaultLifetimeDays());
    }

    #[Test]
    #[DataProvider('baseUrls')]
    public function thePublicBaseUrlIsAnOriginOrEmpty(string $configured, string $expected): void
    {
        self::assertSame($expected, (new WorkspaceSettings(['publicBaseUrl' => $configured]))->publicBaseUrl());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'origin' => ['https://demo.example.org', 'https://demo.example.org'];
        yield 'trailing slash' => ['https://demo.example.org/', 'https://demo.example.org'];
        yield 'with a port' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'with a path' => ['https://demo.example.org/sub', ''];
        yield 'other scheme' => ['javascript:alert(1)', ''];
        yield 'garbage' => ['not a url', ''];
        yield 'empty' => ['', ''];
    }

    #[Test]
    public function theFlagsAreReadAsBooleans(): void
    {
        $settings = new WorkspaceSettings(['enabled' => '0', 'allowInsecureHttp' => '1']);

        self::assertFalse($settings->isEnabled());
        self::assertTrue($settings->allowInsecureHttp());
    }

    #[Test]
    public function withoutAnInjectedArrayItReadsTheExtensionConfigurationWithoutWritingAnything(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']['workspace'] = ['maxLinkLifetimeDays' => '10', 'enabled' => '0'];

        $settings = new WorkspaceSettings();

        self::assertSame(10, $settings->maxLifetimeDays());
        self::assertFalse($settings->isEnabled());
    }

    #[Test]
    public function withoutAnyConfigurationTheDefaultsApply(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']);

        self::assertSame(30, (new WorkspaceSettings())->maxLifetimeDays());
    }
}
