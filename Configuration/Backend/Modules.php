<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;

return [
    'nr_landingpage' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'iconIdentifier' => 'nr-landingpage-module',
        'labels' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => LandingPageWizardController::class . '::indexAction',
            ],
        ],
    ],
];
