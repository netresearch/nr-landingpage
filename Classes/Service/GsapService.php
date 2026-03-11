<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

final class GsapService
{
    /**
     * Current GSAP major version directory.
     */
    public const MAJOR_VERSION = '3';

    /**
     * Exact GSAP version string for reference.
     */
    public const VERSION = '3.14.2';

    /**
     * Extension key used to resolve the vendor path.
     */
    private const EXTENSION_KEY = 'nr_landingpage';

    /**
     * Get the public URL base path for the current GSAP major version.
     *
     * Resolves the absolute filesystem path of the extension's public vendor
     * directory and converts it to a web-accessible path.
     */
    public function getPublicBasePath(): string
    {
        $absoluteExtPath = $this->resolveExtensionPublicPath();
        $vendorSubPath = 'JavaScript/vendor/gsap/' . self::MAJOR_VERSION . '/';
        $absolutePath = $absoluteExtPath . $vendorSubPath;

        $publicPath = PathUtility::getAbsoluteWebPath($absolutePath);

        return rtrim($publicPath, '/') . '/';
    }

    /**
     * Resolve the absolute filesystem path to the extension's Public/ directory.
     */
    private function resolveExtensionPublicPath(): string
    {
        // Derive extension path from the known package location relative to the project root.
        // Environment::getPublicPath() gives the webroot; extensions live in typo3conf/ext/ (classic)
        // or packages/ (composer mode). We detect composer mode by checking if the extension
        // is located under vendor or packages, falling back to the classic location.
        $publicPath = Environment::getPublicPath();
        $extRelPath = '/typo3conf/ext/' . self::EXTENSION_KEY . '/Resources/Public/';

        // In composer mode the extension is outside the webroot; use the project path.
        $classicPath = $publicPath . $extRelPath;
        if (is_dir($classicPath)) {
            return $classicPath;
        }

        // Composer-installed: extension is under the project root's vendor or packages directory.
        $projectPath = Environment::getProjectPath();
        $composerPath = $projectPath . '/packages/' . self::EXTENSION_KEY . '/Resources/Public/';
        if (is_dir($composerPath)) {
            return $composerPath;
        }

        // Fallback to classic path even if not present (handles test environments).
        return $classicPath;
    }

    /**
     * Build the HTML script tags for GSAP loader element bodytext.
     *
     * @param string|null $basePath Override for testing (default: resolved from extension path)
     */
    public function buildLoaderHtml(?string $basePath = null): string
    {
        $base = $basePath ?? $this->getPublicBasePath();

        return <<<HTML
            <script src="{$base}gsap.min.js" defer></script>
            <script src="{$base}ScrollTrigger.min.js" defer></script>
            <script src="{$base}TextPlugin.min.js" defer></script>
            <script data-creative>
            gsap.registerPlugin(ScrollTrigger, TextPlugin);
            ScrollTrigger.matchMedia({
              '(prefers-reduced-motion: no-preference)': function() {
                document.documentElement.classList.add('gsap-animations-active');
              }
            });
            </script>
            HTML;
    }
}
