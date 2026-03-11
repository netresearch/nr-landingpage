<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\AnimationScriptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AnimationScriptBuilder::class)]
final class AnimationScriptBuilderTest extends UnitTestCase
{
    #[Test]
    public function buildReturnsEmptyStringForEmptyAnimations(): void
    {
        $builder = new AnimationScriptBuilder();
        self::assertSame('', $builder->build([]));
    }

    #[Test]
    public function buildGeneratesGsapCallForFadeUp(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('#c123', $result);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('data-creative', $result);
    }

    #[Test]
    public function buildSkipsUnknownAnimationType(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'nonexistent-animation'],
        ]);
        self::assertSame('', $result);
    }

    #[Test]
    public function buildClampsDurationToValidRange(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up', 'duration' => 999.0],
        ]);
        self::assertStringContainsString('duration: 3', $result);
        self::assertStringNotContainsString('999', $result);
    }

    #[Test]
    public function buildSkipsSectionsWithoutAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up'],
            456 => [],
            789 => ['type' => 'slide-left'],
        ]);
        self::assertStringContainsString('#c123', $result);
        self::assertStringNotContainsString('#c456', $result);
        self::assertStringContainsString('#c789', $result);
    }

    #[Test]
    public function buildDoesNotIncludeReducedMotionCheck(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up'],
        ]);
        self::assertStringNotContainsString('prefers-reduced-motion', $result);
    }
}
