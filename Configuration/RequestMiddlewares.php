<?php

declare(strict_types=1);

use Netresearch\NrLandingpage\Middleware\AiLabelMiddleware;

// Inside the page resolver, because the page record is on the request only from
// there on, and outside everything that renders.
return [
    'frontend' => [
        'netresearch/nr-landingpage/ai-label' => [
            'target' => AiLabelMiddleware::class,
            'after'  => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/shortcut-and-mountpoint-redirect',
            ],
        ],
    ],
];
