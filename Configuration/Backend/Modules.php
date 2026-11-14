<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Controller\Backend\WorkspaceController;

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
    // ADR-0001: drafts, share links and (later) approval. It has no page tree:
    // a draft belongs to no page until it is approved.
    'nr_landingpage_workspace' => [
        'parent' => 'web',
        'position' => ['after' => 'nr_landingpage'],
        'access' => 'user',
        'iconIdentifier' => 'nr-landingpage-module',
        'inheritNavigationComponentFromMainModule' => false,
        'labels' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_mod_workspace.xlf',
        'routes' => [
            '_default' => [
                'target' => WorkspaceController::class . '::indexAction',
            ],
            'show' => [
                'target' => WorkspaceController::class . '::showAction',
            ],
        ],
    ],
];
