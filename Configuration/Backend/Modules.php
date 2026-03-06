<?php

declare(strict_types=1);

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;

return [
    'nr_landingpage' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'iconIdentifier' => 'nr-landingpage-module',
        'labels' => 'EXT:nr_landingpage/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => LandingPageWizardController::class . '::indexAction',
            ],
        ],
    ],
];
