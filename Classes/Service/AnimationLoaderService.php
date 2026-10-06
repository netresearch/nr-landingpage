<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Builds the loader element that adds the extension's animation runtime
 * (Resources/Public/JavaScript/frontend/animations.js) to a generated page.
 *
 * The loader is a single external script with `defer`; it contains no inline
 * code, so it runs under a Content Security Policy that allows scripts from
 * the site's own origin.
 */
class AnimationLoaderService
{
    public const RUNTIME_PATH = 'Resources/Public/JavaScript/frontend/animations.js';

    /**
     * Public URL of the animation runtime.
     */
    public function getRuntimeUrl(): string
    {
        return PathUtility::getAbsoluteWebPath(
            ExtensionManagementUtility::extPath('nr_landingpage', self::RUNTIME_PATH),
        );
    }

    /**
     * Build the HTML of the loader content element.
     *
     * @param string|null $runtimeUrl Override for testing (default: resolved from the extension path)
     */
    public function buildLoaderHtml(?string $runtimeUrl = null): string
    {
        $url = htmlspecialchars($runtimeUrl ?? $this->getRuntimeUrl(), ENT_QUOTES | ENT_HTML5);

        return '<script src="' . $url . '" defer></script>';
    }
}
