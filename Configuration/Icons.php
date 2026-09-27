<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color icons that adapt via currentColor. v13 uses the colored
// (teal tile) variants that match the classic module menu.
if ((new Typo3Version())->getMajorVersion() >= 14) {
    // Registered like core's own icons: SvgSpriteIconProvider renders
    // <svg><use href="sprite.svg#id"/></svg>, so currentColor resolves against
    // the surrounding text colour and follows the light/dark scheme. The
    // SvgIconProvider renders an <img>, in which currentColor is always black
    // (module icon on the hub page, record icon in the FormEngine header).
    return [
        'nr-landingpage-module' => [
            'provider' => SvgSpriteIconProvider::class,
            'sprite' => 'EXT:nr_landingpage/Resources/Public/Icons/sprite.svg#nr-landingpage-module',
            'source' => 'EXT:nr_landingpage/Resources/Public/Icons/module.svg',
        ],
        'nr-landingpage-template' => [
            'provider' => SvgSpriteIconProvider::class,
            'sprite' => 'EXT:nr_landingpage/Resources/Public/Icons/sprite.svg#nr-landingpage-template',
            'source' => 'EXT:nr_landingpage/Resources/Public/Icons/template.svg',
        ],
    ];
}

return [
    'nr-landingpage-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_landingpage/Resources/Public/Icons/module.legacy.svg',
    ],
    'nr-landingpage-template' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_landingpage/Resources/Public/Icons/template.legacy.svg',
    ],
];
