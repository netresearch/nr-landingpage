<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\AnimationLoaderService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AnimationLoaderService::class)]
final class AnimationLoaderServiceTest extends UnitTestCase
{
    #[Test]
    public function loaderIsOneDeferredExternalScriptWithoutInlineCode(): void
    {
        $html = (new AnimationLoaderService())->buildLoaderHtml('/_assets/abc/JavaScript/frontend/animations.js');

        self::assertSame('<script src="/_assets/abc/JavaScript/frontend/animations.js" defer></script>', $html);
    }

    #[Test]
    public function loaderEscapesTheRuntimeUrl(): void
    {
        $html = (new AnimationLoaderService())->buildLoaderHtml('/a"b.js');

        self::assertSame('<script src="/a&quot;b.js" defer></script>', $html);
    }

    #[Test]
    public function runtimeFileIsShipped(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/' . AnimationLoaderService::RUNTIME_PATH);
    }
}
