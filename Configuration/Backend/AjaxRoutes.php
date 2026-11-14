<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Controller\Backend\WorkspaceAjaxController;

return [
    'nr_landingpage_templates' => [
        'path' => '/nr-landingpage/wizard/templates',
        'target' => LandingPageWizardController::class . '::templatesAction',
    ],
    'nr_landingpage_generate_briefing' => [
        'path' => '/nr-landingpage/wizard/generate-briefing',
        'target' => LandingPageWizardController::class . '::generateBriefingAction',
    ],
    'nr_landingpage_generate_page_fields' => [
        'path' => '/nr-landingpage/wizard/generate-page-fields',
        'target' => LandingPageWizardController::class . '::generatePageFieldsAction',
    ],
    'nr_landingpage_generate_content' => [
        'path' => '/nr-landingpage/wizard/generate-content',
        'target' => LandingPageWizardController::class . '::generateContentAction',
    ],
    'nr_landingpage_regenerate_section' => [
        'path' => '/nr-landingpage/wizard/regenerate-section',
        'target' => LandingPageWizardController::class . '::regenerateSectionAction',
    ],
    'nr_landingpage_generate_image' => [
        'path' => '/nr-landingpage/wizard/generate-image',
        'target' => LandingPageWizardController::class . '::generateImageAction',
    ],
    'nr_landingpage_search_images' => [
        'path' => '/nr-landingpage/wizard/search-images',
        'target' => LandingPageWizardController::class . '::searchImagesAction',
    ],
    'nr_landingpage_save' => [
        'path' => '/nr-landingpage/wizard/save',
        'target' => LandingPageWizardController::class . '::saveAction',
    ],
    'nr_landingpage_check_parent_page' => [
        'path' => '/nr-landingpage/wizard/check-parent-page',
        'target' => LandingPageWizardController::class . '::checkParentPageAction',
    ],
    'nr_landingpage_check_seo' => [
        'path' => '/nr-landingpage/wizard/check-seo',
        'target' => LandingPageWizardController::class . '::checkSeoAction',
    ],
    'nr_landingpage_optimize_prompt' => [
        'path' => '/nr-landingpage/wizard/optimize-prompt',
        'target' => LandingPageWizardController::class . '::optimizePromptAction',
    ],
    'nr_landingpage_test_generate' => [
        'path' => '/nr-landingpage/wizard/test-generate',
        'target' => LandingPageWizardController::class . '::testGenerateAction',
    ],
    'nr_landingpage_generation_info' => [
        'path' => '/nr-landingpage/wizard/generation-info',
        'target' => LandingPageWizardController::class . '::generationInfoAction',
    ],
    'nr_landingpage_workspace_save_draft' => [
        'path' => '/nr-landingpage/workspace/save-draft',
        'target' => WorkspaceAjaxController::class . '::saveDraftAction',
    ],
    'nr_landingpage_workspace_create_link' => [
        'path' => '/nr-landingpage/workspace/create-link',
        'target' => WorkspaceAjaxController::class . '::createLinkAction',
    ],
    'nr_landingpage_workspace_revoke_link' => [
        'path' => '/nr-landingpage/workspace/revoke-link',
        'target' => WorkspaceAjaxController::class . '::revokeLinkAction',
    ],
];
