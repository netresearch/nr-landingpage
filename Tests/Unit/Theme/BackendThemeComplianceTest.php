<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Theme;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Pins the backend markup to classes that follow TYPO3's light/dark scheme.
 *
 * TYPO3 switches the scheme with data-color-scheme and the --typo3-* custom
 * properties. Bootstrap's .bg-* / .text-bg-* utilities keep one colour in both
 * schemes, and several Bootstrap classes (.btn-outline-*, .text-body-secondary,
 * .table-borderless, .alert-sm, .text-dark, .text-white) are not part of the
 * core backend CSS at 13.4 or 14.3 and do nothing; .spinner-border and
 * .progress/.progress-bar are missing from 14.3 and draw nothing there.
 */
final class BackendThemeComplianceTest extends UnitTestCase
{
    private const EXTENSION_ROOT = __DIR__ . '/../../..';

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenPatternProvider(): array
    {
        return [
            'Bootstrap bg-* on a badge (fixed colour in both schemes)' => ['/badge[^"\']*\bbg-/', 'badge badge-default|info|success|warning|danger'],
            'text-bg-* (fixed colour in both schemes)' => ['/\btext-bg-[a-z]/', 'badge badge-* or a --typo3-* variable'],
            'bg-body-tertiary (switches only under data-bs-theme)' => ['/\bbg-body-tertiary\b/', 'background: var(--typo3-surface-container-*)'],
            'bg-secondary-subtle (switches only under data-bs-theme)' => ['/\bbg-secondary-subtle\b/', 'background: var(--typo3-surface-container-*)'],
            'bg-light (fixed near-white)' => ['/\bbg-light\b/', 'badge-default or a --typo3-* variable'],
            'btn-outline-* (not in core CSS)' => ['/\bbtn-outline-/', 'btn-default / btn-primary'],
            'text-body-secondary (not in core CSS)' => ['/\btext-body-secondary\b/', 'text-variant'],
            'text-dark (not in core CSS)' => ['/\btext-dark\b/', 'nothing, the badge variant sets the colour'],
            'text-white (not in core CSS)' => ['/\btext-white\b/', 'nothing, the badge variant sets the colour'],
            'table-borderless (not in core CSS)' => ['/\btable-borderless\b/', 'table'],
            'alert-sm (not in core CSS)' => ['/\balert-sm\b/', 'alert'],
            'hard-coded Bootstrap blue' => ['/#0d6efd/i', 'var(--typo3-surface-primary)'],
            'spinner-border (not in core CSS at 14.3, draws nothing)' => ['/\bspinner-border\b/', '<typo3-backend-spinner>'],
            'Bootstrap progress (not in core CSS at 14.3, draws nothing)' => ['/class=["\'][^"\']*\bprogress(-bar)?(?![\w-])/', 'an element with role="progressbar", aria-value* and aria-label, filled with var(--typo3-component-primary-color) on var(--typo3-surface-container-high)'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenPatternProvider')]
    public function backendMarkupDoesNotUseSchemeBlindClasses(string $pattern, string $replacement): void
    {
        $violations = [];
        foreach ($this->backendMarkupFiles() as $file) {
            $lines = file($file);
            self::assertIsArray($lines);
            foreach ($lines as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $violations[] = substr($file, strlen(realpath(self::EXTENSION_ROOT) ?: '') + 1) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $violations, 'Use ' . $replacement . ' instead.');
    }

    #[Test]
    public function hubTemplateHeadingsDoNotSkipLevels(): void
    {
        $template = file_get_contents(self::EXTENSION_ROOT . '/Resources/Private/Templates/Backend/LandingPageWizard/Index.html');
        self::assertIsString($template);

        preg_match_all('/<h([1-6])\b/', $template, $matches);
        $levels = array_map('intval', $matches[1]);

        // The empty-state callout sits in an f:else next to the h3 card titles,
        // so a source-order scan cannot see its level; core's infobox renders
        // the callout title as a div, not as a heading.
        self::assertDoesNotMatchRegularExpression('/<h[1-6][^>]*callout-title/', $template);
        self::assertSame(1, $levels[0] ?? null, 'The module view starts with its h1.');
        self::assertSame(1, count(array_keys($levels, 1, true)), 'The module view has exactly one h1.');
        $previous = 1;
        foreach ($levels as $level) {
            self::assertLessThanOrEqual($previous + 1, $level, 'Heading levels must not be skipped: ' . implode(',', $levels));
            $previous = $level;
        }
    }

    #[Test]
    public function wizardDialogHeadingsStartBelowTheModalTitle(): void
    {
        // The modal title is an h1 in 13.4 and a labelled div in 14.3, so
        // content headings inside a dialog start at h2.
        foreach (['wizard.js', 'form-engine/field-control/test-generate.js'] as $script) {
            $source = file_get_contents(self::EXTENSION_ROOT . '/Resources/Public/JavaScript/' . $script);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/createElement\(\'h[4-6]\'\)|<h[4-6][\s>]/', $source, $script);
        }
    }

    #[Test]
    public function iconsFollowTheColorSchemeOnVersion14(): void
    {
        /** @var array<string, array<string, string>> $icons */
        $icons = require self::EXTENSION_ROOT . '/Configuration/Icons.php';

        if ((new Typo3Version())->getMajorVersion() < 14) {
            // 13.4 keeps the self-contained teal tiles, which carry their own
            // background and read in either scheme.
            foreach ($icons as $identifier => $configuration) {
                self::assertSame(SvgIconProvider::class, $configuration['provider'], $identifier);
                self::assertStringEndsWith('.legacy.svg', $configuration['source'], $identifier);
            }
            return;
        }

        $sprite = file_get_contents(self::EXTENSION_ROOT . '/Resources/Public/Icons/sprite.svg');
        self::assertIsString($sprite);

        foreach (['nr-landingpage-module', 'nr-landingpage-template'] as $identifier) {
            self::assertArrayHasKey($identifier, $icons);
            // An <img> cannot inherit currentColor, so the icon must be
            // rendered as <svg><use> like core's own icons.
            self::assertSame(SvgSpriteIconProvider::class, $icons[$identifier]['provider'], $identifier);
            self::assertSame(
                'EXT:nr_landingpage/Resources/Public/Icons/sprite.svg#' . $identifier,
                $icons[$identifier]['sprite'],
                $identifier,
            );
            self::assertMatchesRegularExpression(
                '/<symbol id="' . preg_quote($identifier, '/') . '" viewBox="0 0 64 64">(?:(?!<\/symbol>).)*fill="currentColor"/s',
                $sprite,
                $identifier,
            );
        }

        self::assertDoesNotMatchRegularExpression('/fill="#0{3}(0{3})?"|fill="black"|<style/i', $sprite);
    }

    /**
     * @return list<string>
     */
    private function backendMarkupFiles(): array
    {
        $root = realpath(self::EXTENSION_ROOT);
        self::assertIsString($root);

        $files = [];
        foreach (['Resources/Private/Templates', 'Resources/Private/Layouts', 'Resources/Public/JavaScript', 'Classes/Form', 'Classes/EventListener'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (str_contains($path, '/vendor/') || !in_array($file->getExtension(), ['html', 'js', 'php'], true)) {
                    continue;
                }
                $files[] = $path;
            }
        }
        sort($files);
        self::assertNotEmpty($files);

        return $files;
    }
}
