<?php

declare(strict_types=1);

use Netresearch\NrLandingpage\Middleware\AiLabelMiddleware;
use Netresearch\NrLandingpage\Middleware\ReviewPageMiddleware;

// Inside everything that puts the page record on the request (tsfe on 13.4,
// prepare-tsfe-rendering on 14.3, both before the shortcut redirect), and inside
// content-length-headers: that one measures the body on the way back, so a notice
// added further out would be cut off with the end of the page.
return [
    'frontend' => [
        'netresearch/nr-landingpage/ai-label' => [
            'target' => AiLabelMiddleware::class,
            'after' => [
                'typo3/cms-frontend/shortcut-and-mountpoint-redirect',
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
        // ADR-0001: the review page for share links. Before the site is
        // resolved, so it never reaches page resolution or any page cache.
        'netresearch/nr-landingpage/review-page' => [
            'target' => ReviewPageMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
