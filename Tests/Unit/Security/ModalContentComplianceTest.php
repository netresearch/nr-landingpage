<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Security;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Ensures Modal.advanced() calls do not pass HTML strings as content.
 *
 * TYPO3's Modal component (Lit-based web component) auto-escapes string content
 * via Lit template literals: <p>${content}</p>. Passing an HTML string as
 * `content` to Modal.advanced() renders escaped markup instead of rendered HTML.
 *
 * The correct approach is to pass a DOM element (e.g. via htmlToElement()),
 * which triggers the "template" type path that renders content as-is.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/JavaScript/Modals/
 */
final class ModalContentComplianceTest extends UnitTestCase
{
    #[Test]
    public function modalAdvancedCallsDoNotPassHtmlStringAsContent(): void
    {
        $jsDir = dirname(__DIR__, 3) . '/Resources/Public/JavaScript';
        self::assertDirectoryExists($jsDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($jsDir, FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Find Modal.advanced({ ... content: '...' ... }) calls where
            // content is a string literal containing HTML tags.
            // This regex catches: content: '<...' or content: "<..."
            if (preg_match('/Modal\.advanced\s*\(\s*\{/s', $content)
                && preg_match('/\bcontent\s*:\s*[\'"]</', $content)
            ) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            array_map('basename', $violations),
            'Modal.advanced() must not receive HTML strings as content — '
            . 'Lit auto-escapes them. Pass a DOM element instead (e.g. this.htmlToElement(html)).',
        );
    }
}
