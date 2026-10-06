<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

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
            'form-control-lg (not in core CSS)' => ['/\bform-control-lg\b/', 'form-control'],
            'card-img-top (not in core CSS)' => ['/\bcard-img-top\b/', 'nothing'],
            'btn-lg (no effect in core at 13.4 or 14.3)' => ['/\bbtn-lg\b/', 'btn'],
            'table-sm (11px text next to core\'s 12px list tables)' => ['/\btable-sm\b/', 'table'],
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
    public function templateCardsAreARadioGroupMarkedBeyondColour(): void
    {
        $source = file_get_contents(self::EXTENSION_ROOT . '/Resources/Public/JavaScript/wizard.js');
        self::assertIsString($source);
        // Single choice: WAI-ARIA radio group with roving tabindex. The
        // behaviour (reset of the previous card, arrow keys) is pinned by the
        // E2E suite; this pins the roles.
        self::assertStringContainsString("grid.setAttribute('role', 'radiogroup');", $source);
        self::assertStringContainsString("grid.setAttribute('aria-labelledby', heading.id);", $source);
        self::assertStringContainsString("card.setAttribute('role', 'radio');", $source);
        self::assertStringContainsString("card.classList.add('border-2', 'shadow');", $source);
        self::assertStringContainsString("card.style.setProperty('border-color', 'var(--typo3-component-primary-color)');", $source);
        self::assertStringContainsString("card.setAttribute('aria-checked', 'true');", $source);
        self::assertStringNotContainsString("card.setAttribute('aria-pressed'", $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacyIconProvider(): array
    {
        return ['module' => ['module.legacy.svg'], 'template' => ['template.legacy.svg']];
    }

    #[Test]
    #[DataProvider('legacyIconProvider')]
    public function legacyTileKeepsBrandFillAndReadableGlyph(string $file): void
    {
        $svg = file_get_contents(self::EXTENSION_ROOT . '/Resources/Public/Icons/' . $file);
        self::assertIsString($svg);
        // Module icons use the brand colour #2F99A4 (netresearch-branding,
        // typo3-extension-branding.md). Under WCAG 1.4.11 the part needed to
        // understand the icon is the white glyph on the tile, not the tile's
        // edge against the menu row, so the glyph is what must reach 3:1.
        self::assertSame(1, preg_match('/<path fill="(#[0-9A-Fa-f]{6})" d="M0 0h64v64H0z"\/>/', $svg, $tile));
        self::assertSame('#2F99A4', strtoupper($tile[1]), $file);
        preg_match_all('/<path fill="(#[0-9A-Fa-f]{3,6})" d="(?!M0 0h64v64H0z)/', $svg, $glyphs);
        self::assertNotEmpty($glyphs[1], $file);
        foreach ($glyphs[1] as $glyph) {
            $hex = strlen($glyph) === 4 ? '#' . $glyph[1] . $glyph[1] . $glyph[2] . $glyph[2] . $glyph[3] . $glyph[3] : $glyph;
            self::assertGreaterThanOrEqual(3.0, self::contrast($hex, $tile[1]), $file . ' glyph ' . $glyph . ' on the tile');
        }
    }

    private static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static fn(string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
            $linear = array_map(static fn(float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $channels);
            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        $x = $luminance($a);
        $y = $luminance($b);
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    #[Test]
    public function previewLoadingStateIsAnnouncedAsStatus(): void
    {
        // The spinner is aria-hidden, so the wrapper must carry the live region
        // that announces the loading text.
        $source = file_get_contents(self::EXTENSION_ROOT . '/Resources/Public/JavaScript/form-engine/field-control/test-generate.js');
        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            "/this\\.el\\('div', \\{ className: '[^']*', attrs: \\{ role: 'status', 'aria-live': 'polite' \\} \\}, \\[\\s*this\\.el\\('typo3-backend-spinner', \\{ attrs: \\{ size: 'large', 'aria-hidden': 'true' \\} \\}\\)/",
            $source,
        );
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
