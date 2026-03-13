<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class GsapService
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
     * Get the public URL base path for the current GSAP major version.
     */
    public function getPublicBasePath(): string
    {
        $absolutePath = ExtensionManagementUtility::extPath(
            'nr_landingpage',
            'Resources/Public/JavaScript/vendor/gsap/' . self::MAJOR_VERSION . '/',
        );

        return rtrim(PathUtility::getAbsoluteWebPath($absolutePath), '/') . '/';
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
            <script src="{$base}gsap.min.js"></script>
            <script src="{$base}ScrollTrigger.min.js"></script>
            <script src="{$base}TextPlugin.min.js"></script>
            <script data-creative>
            gsap.registerPlugin(ScrollTrigger, TextPlugin);
            gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', function() {
              document.documentElement.classList.add('gsap-animations-active');
            });
            </script>
            HTML;
    }
}
