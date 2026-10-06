<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Generated content reaches the backend as data from the server. The
 * backend scripts that show it must never parse it as HTML in the backend
 * document: previews go into sandboxed frames without script execution.
 */
final class GeneratedContentRenderingTest extends UnitTestCase
{
    private const JS_DIR = __DIR__ . '/../../../Resources/Public/JavaScript';

    #[Test]
    public function testGenerateControlBuildsItsMarkupWithDomMethods(): void
    {
        $content = $this->read('form-engine/field-control/test-generate.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'DOMParser', 'createContextualFragment'] as $sink) {
            self::assertStringNotContainsString($sink, $content, 'test-generate.js must not parse markup: ' . $sink);
        }
        self::assertStringContainsString('.srcdoc = bodytext', $content);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function previewScriptProvider(): array
    {
        return [
            'wizard' => ['wizard.js'],
            'test generate control' => ['form-engine/field-control/test-generate.js'],
        ];
    }

    #[Test]
    #[DataProvider('previewScriptProvider')]
    public function previewFramesDoNotAllowScripts(string $file): void
    {
        $content = $this->read($file);

        self::assertMatchesRegularExpression('#sandbox#', $content, $file . ' renders previews in a sandboxed frame');
        preg_match_all('#sandbox[\'"]?\s*(?:=|,)\s*[\'"]([^\'"]*)[\'"]#', $content, $matches);
        self::assertNotSame([], $matches[1], $file . ': sandbox value not found');
        foreach ($matches[1] as $value) {
            self::assertStringNotContainsString('allow-scripts', $value, $file . ' must not allow scripts in previews');
        }
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::JS_DIR . '/' . $relativePath);
        self::assertIsString($content);

        return $content;
    }
}
