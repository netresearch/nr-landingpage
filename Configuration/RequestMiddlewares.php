<?php

declare(strict_types=1);

use Netresearch\NrLandingpage\Middleware\AiLabelMiddleware;

// Inside everything that puts the page record on the request (tsfe on 13.4,
// prepare-tsfe-rendering on 14.3, both before the shortcut redirect), and outside
// content-length-headers' view of the body: the header is set after this ran.
return [
    'frontend' => [
        'netresearch/nr-landingpage/ai-label' => [
            'target' => AiLabelMiddleware::class,
            'after'  => [
                'typo3/cms-frontend/shortcut-and-mountpoint-redirect',
            ],
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
    ],
];
