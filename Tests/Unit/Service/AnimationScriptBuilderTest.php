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

    // -------------------------------------------------------------------------
    // Animation type tests
    // -------------------------------------------------------------------------

    #[Test]
    public function buildGeneratesSlideLeftAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            10 => ['type' => 'slide-left', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('x: -60', $result);
        self::assertStringContainsString('#c10', $result);
    }

    #[Test]
    public function buildGeneratesSlideRightAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            11 => ['type' => 'slide-right', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('x: 60', $result);
        self::assertStringContainsString('#c11', $result);
    }

    #[Test]
    public function buildGeneratesZoomInAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            12 => ['type' => 'zoom-in', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('scale: 0.8', $result);
        self::assertStringContainsString('#c12', $result);
    }

    #[Test]
    public function buildGeneratesScaleUpAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            13 => ['type' => 'scale-up', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('scale: 0.5', $result);
        self::assertStringContainsString('#c13', $result);
    }

    #[Test]
    public function buildGeneratesFadeDownAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            14 => ['type' => 'fade-down', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('y: -40', $result);
        self::assertStringContainsString('#c14', $result);
    }

    #[Test]
    public function buildGeneratesStaggerChildrenAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            15 => ['type' => 'stagger-children', 'duration' => 0.8, 'stagger' => 0.15],
        ]);
        self::assertStringContainsString("'#c15 > *'", $result);
        self::assertStringContainsString('stagger:', $result);
        self::assertStringContainsString('gsap.from', $result);
    }

    #[Test]
    public function buildGeneratesTypewriterAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            16 => ['type' => 'typewriter', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('querySelectorAll', $result);
        self::assertStringContainsString('text:', $result);
        self::assertStringContainsString("'#c16 h1, #c16 h2, #c16 p'", $result);
    }

    #[Test]
    public function buildGeneratesParallaxAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            17 => ['type' => 'parallax'],
        ]);
        self::assertStringContainsString('scrub: true', $result);
        self::assertStringContainsString('y: -30', $result);
        self::assertStringContainsString('#c17', $result);
    }

    #[Test]
    public function buildParallaxIgnoresDurationAndDelay(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            18 => ['type' => 'parallax', 'duration' => 2.5, 'delay' => 1.0],
        ]);
        self::assertStringNotContainsString('duration:', $result);
        self::assertStringNotContainsString('delay:', $result);
        self::assertStringContainsString('y: -30', $result);
    }

    // -------------------------------------------------------------------------
    // Clamping edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function buildClampsDelayToMaximum(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            20 => ['type' => 'fade-up', 'delay' => 999],
        ]);
        self::assertStringContainsString('delay: 2', $result);
        self::assertStringNotContainsString('delay: 999', $result);
    }

    #[Test]
    public function buildClampsDelayToMinimum(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            21 => ['type' => 'fade-up', 'delay' => -1],
        ]);
        self::assertStringContainsString('delay: 0', $result);
    }

    #[Test]
    public function buildClampsStaggerToMaximum(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            22 => ['type' => 'stagger-children', 'stagger' => 999],
        ]);
        self::assertStringContainsString('stagger: 0.5', $result);
        self::assertStringNotContainsString('stagger: 999', $result);
    }

    #[Test]
    public function buildClampsStaggerToMinimum(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            23 => ['type' => 'stagger-children', 'stagger' => 0.01],
        ]);
        self::assertStringContainsString('stagger: 0.05', $result);
        self::assertStringNotContainsString('stagger: 0.01', $result);
    }

    #[Test]
    public function buildClampsDurationToMinimum(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            24 => ['type' => 'fade-up', 'duration' => 0],
        ]);
        self::assertStringContainsString('duration: 0.1', $result);
        self::assertStringNotContainsString('duration: 0,', $result);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function buildHandlesLargeUid(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            999999 => ['type' => 'fade-up'],
        ]);
        self::assertStringContainsString('#c999999', $result);
    }

    #[Test]
    public function buildUsesDefaultDurationWhenNotProvided(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            30 => ['type' => 'fade-up'],
        ]);
        self::assertStringContainsString('duration: 0.8', $result);
    }

    #[Test]
    public function buildHandlesMultipleMixedAnimationTypes(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            40 => ['type' => 'fade-up', 'duration' => 0.8],
            41 => ['type' => 'typewriter', 'duration' => 1.0],
            42 => ['type' => 'parallax'],
        ]);
        // fade-up
        self::assertStringContainsString('#c40', $result);
        self::assertStringContainsString('y: 40', $result);
        // typewriter
        self::assertStringContainsString("'#c41 h1, #c41 h2, #c41 p'", $result);
        self::assertStringContainsString('text:', $result);
        // parallax
        self::assertStringContainsString('#c42', $result);
        self::assertStringContainsString('scrub: true', $result);
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Script wrapping
    // -------------------------------------------------------------------------

    #[Test]
    public function buildWrapsCallsInDomContentLoaded(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            50 => ['type' => 'fade-up'],
        ]);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $result);
        self::assertStringContainsString('});', $result);
        // Verify animation call is inside the wrapper (DOMContentLoaded before gsap.from)
        $domReadyPos = strpos($result, 'DOMContentLoaded');
        $gsapFromPos = strpos($result, 'gsap.from');
        self::assertNotFalse($domReadyPos);
        self::assertNotFalse($gsapFromPos);
        self::assertGreaterThan($domReadyPos, $gsapFromPos);
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    #[Test]
    public function clampingConstantsHaveExpectedValues(): void
    {
        self::assertSame(0.1, AnimationScriptBuilder::DURATION_MIN);
        self::assertSame(3.0, AnimationScriptBuilder::DURATION_MAX);
        self::assertSame(0.8, AnimationScriptBuilder::DURATION_DEFAULT);

        self::assertSame(0.0, AnimationScriptBuilder::DELAY_MIN);
        self::assertSame(2.0, AnimationScriptBuilder::DELAY_MAX);
        self::assertSame(0.0, AnimationScriptBuilder::DELAY_DEFAULT);

        self::assertSame(0.05, AnimationScriptBuilder::STAGGER_MIN);
        self::assertSame(0.5, AnimationScriptBuilder::STAGGER_MAX);
        self::assertSame(0.15, AnimationScriptBuilder::STAGGER_DEFAULT);
    }
}
