<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Security;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Ensures JavaScript files do not introduce CSP-violating patterns.
 *
 * TYPO3 v14 enforces strict Content-Security-Policy in the backend:
 *   script-src 'self' 'nonce-xxx'; worker-src 'self'; style-src 'self' 'unsafe-inline'
 *
 * These tests guard against accidental introduction of patterns that would be
 * blocked by the backend CSP and cause silent failures.
 */
final class CspComplianceTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cspViolatingPatternsProvider(): array
    {
        return [
            'eval()' => ['eval(', 'eval() is blocked by script-src CSP directive'],
            'new Function()' => ['new Function(', 'new Function() is blocked by script-src CSP directive'],
            'new Worker(blob:' => ["new Worker('blob:", 'blob: workers are blocked by worker-src CSP directive'],
            'new Worker("blob:' => ['new Worker("blob:', 'blob: workers are blocked by worker-src CSP directive'],
            'new Worker(URL.createObjectURL' => ['new Worker(URL.createObjectURL', 'blob: workers are blocked by worker-src CSP directive'],
            'createObjectURL' => ['URL.createObjectURL', 'blob: URLs for scripts/workers violate CSP'],
            'document.write' => ['document.write(', 'document.write() can inject scripts violating CSP'],
        ];
    }

    #[Test]
    #[DataProvider('cspViolatingPatternsProvider')]
    public function javaScriptFilesDoNotContainCspViolatingPatterns(string $pattern, string $reason): void
    {
        $jsDir = dirname(__DIR__, 3) . '/Resources/Public/JavaScript';
        self::assertDirectoryExists($jsDir);

        $files = glob($jsDir . '/*.js');
        self::assertNotEmpty($files, 'No JavaScript files found');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            self::assertIsString($content);
            self::assertStringNotContainsString(
                $pattern,
                $content,
                sprintf(
                    'File %s contains CSP-violating pattern "%s": %s',
                    basename($file),
                    $pattern,
                    $reason,
                ),
            );
        }
    }

    #[Test]
    public function fluidTemplatesDoNotContainInlineEventHandlers(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/Resources/Private/Templates';
        self::assertDirectoryExists($templateDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'html') {
                $files[] = $file->getPathname();
            }
        }
        self::assertNotEmpty($files, 'No Fluid templates found');

        $inlineHandlers = [
            'onclick', 'onload', 'onerror', 'onsubmit', 'onchange',
            'onkeyup', 'onkeydown', 'onmouseover', 'onfocus', 'onblur',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            self::assertIsString($content);

            foreach ($inlineHandlers as $handler) {
                self::assertStringNotContainsString(
                    $handler . '=',
                    strtolower($content),
                    sprintf(
                        'File %s contains inline event handler "%s=" which violates CSP script-src directive',
                        basename($file),
                        $handler,
                    ),
                );
            }
        }
    }
}
