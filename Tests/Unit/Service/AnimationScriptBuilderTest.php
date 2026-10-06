<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\AnimationScriptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AnimationScriptBuilder::class)]
final class AnimationScriptBuilderTest extends UnitTestCase
{
    private const PREFIX = '<script type="application/json" data-nr-landingpage-animations>';
    private const SUFFIX = '</script>';

    /**
     * @param array<int, array<string, mixed>> $animations
     * @return array<string, array<string, mixed>>
     */
    private function buildMap(array $animations): array
    {
        $html = (new AnimationScriptBuilder())->build($animations);
        self::assertStringStartsWith(self::PREFIX, $html);
        self::assertStringEndsWith(self::SUFFIX, $html);

        $json = substr($html, strlen(self::PREFIX), -strlen(self::SUFFIX));
        $map = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);

        /** @var array<string, array<string, mixed>> $map */
        return $map;
    }

    #[Test]
    public function buildReturnsEmptyStringForEmptyAnimations(): void
    {
        self::assertSame('', (new AnimationScriptBuilder())->build([]));
    }

    #[Test]
    public function buildReturnsADataBlockThatBrowsersDoNotExecute(): void
    {
        $html = (new AnimationScriptBuilder())->build([123 => ['type' => 'fade-up']]);

        self::assertStringStartsWith('<script type="application/json"', $html);
        self::assertStringNotContainsString('gsap', $html);
        self::assertStringNotContainsString('function', $html);
    }

    #[Test]
    public function buildMapsTheContentElementUidToItsAnimation(): void
    {
        self::assertSame(
            ['123' => ['type' => 'fade-up', 'duration' => 0.8, 'delay' => 0.0]],
            $this->buildMap([123 => ['type' => 'fade-up', 'duration' => 0.8]]),
        );
    }

    #[Test]
    public function buildSkipsUnknownAnimationType(): void
    {
        self::assertSame('', (new AnimationScriptBuilder())->build([123 => ['type' => 'nonexistent-animation']]));
    }

    #[Test]
    public function buildSkipsSectionsWithoutAnimation(): void
    {
        $map = $this->buildMap([
            123 => ['type' => 'fade-up'],
            456 => [],
            789 => ['type' => 'slide-left'],
        ]);

        self::assertSame(['123', '789'], array_map(strval(...), array_keys($map)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function animationTypeProvider(): array
    {
        return array_combine(
            AnimationScriptBuilder::TYPES,
            array_map(static fn(string $type): array => [$type], AnimationScriptBuilder::TYPES),
        );
    }

    #[Test]
    #[DataProvider('animationTypeProvider')]
    public function buildKeepsEveryTypeTheRuntimeImplements(string $type): void
    {
        self::assertSame($type, $this->buildMap([10 => ['type' => $type]])['10']['type']);
    }

    #[Test]
    public function everyTypeIsImplementedByTheRuntime(): void
    {
        $runtime = file_get_contents(dirname(__DIR__, 3) . '/Resources/Public/JavaScript/frontend/animations.js');
        self::assertIsString($runtime);

        foreach (AnimationScriptBuilder::TYPES as $type) {
            self::assertStringContainsString("'" . $type . "'", $runtime, $type);
        }
    }

    #[Test]
    public function buildClampsDurationDelayAndStagger(): void
    {
        $map = $this->buildMap([
            1 => ['type' => 'fade-up', 'duration' => 999.0, 'delay' => -5.0],
            2 => ['type' => 'stagger-children', 'duration' => 0.0, 'delay' => 999.0, 'stagger' => 9.0],
        ]);

        self::assertSame(['type' => 'fade-up', 'duration' => 3.0, 'delay' => 0.0], $map['1']);
        self::assertSame(['type' => 'stagger-children', 'duration' => 0.1, 'delay' => 2.0, 'stagger' => 0.5], $map['2']);
    }

    #[Test]
    public function buildParallaxIgnoresDurationAndDelay(): void
    {
        self::assertSame(['type' => 'parallax'], $this->buildMap([17 => ['type' => 'parallax', 'duration' => 2.0, 'delay' => 1.0]])['17']);
    }

    #[Test]
    public function buildKeepsMarkupOutOfTheDataBlock(): void
    {
        $html = (new AnimationScriptBuilder())->build([5 => ['type' => 'fade-up', 'delay' => 1.0]]);

        // The opening and the closing tag are the only places with "<".
        self::assertSame(2, substr_count($html, '<'));
        self::assertSame(1, substr_count($html, '</'));
    }
}
