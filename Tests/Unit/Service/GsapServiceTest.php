<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\GsapService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(GsapService::class)]
final class GsapServiceTest extends UnitTestCase
{
    #[Test]
    public function versionConstantsAreDefined(): void
    {
        self::assertSame('3', GsapService::MAJOR_VERSION);
        self::assertNotEmpty(GsapService::VERSION);
        self::assertStringStartsWith('3.', GsapService::VERSION);
    }

    #[Test]
    public function buildLoaderHtmlContainsAllScripts(): void
    {
        $service = new GsapService();
        $html = $service->buildLoaderHtml('/test/path/');

        self::assertStringContainsString('/test/path/gsap.min.js', $html);
        self::assertStringContainsString('/test/path/ScrollTrigger.min.js', $html);
        self::assertStringContainsString('/test/path/TextPlugin.min.js', $html);
        self::assertStringContainsString('gsap.registerPlugin(ScrollTrigger, TextPlugin)', $html);
        self::assertStringContainsString('defer', $html);
        self::assertStringContainsString('data-creative', $html);
    }

    #[Test]
    public function buildLoaderHtmlContainsReducedMotionCheck(): void
    {
        $service = new GsapService();
        $html = $service->buildLoaderHtml('/test/');

        self::assertStringContainsString('prefers-reduced-motion', $html);
        self::assertStringContainsString('ScrollTrigger.matchMedia', $html);
    }
}
