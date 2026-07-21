<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color icons that adapt via currentColor. v13 uses the colored
// (teal tile) variants that match the classic module menu.
$legacySuffix = (new Typo3Version())->getMajorVersion() >= 14 ? '' : '.legacy';

return [
    'nr-landingpage-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_landingpage/Resources/Public/Icons/module' . $legacySuffix . '.svg',
    ],
    'nr-landingpage-template' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_landingpage/Resources/Public/Icons/template' . $legacySuffix . '.svg',
    ],
];
