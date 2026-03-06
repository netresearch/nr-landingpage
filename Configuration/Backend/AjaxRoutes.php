<?php

declare(strict_types=1);

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;

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
    'nr_landingpage_save' => [
        'path' => '/nr-landingpage/wizard/save',
        'target' => LandingPageWizardController::class . '::saveAction',
    ],
];
