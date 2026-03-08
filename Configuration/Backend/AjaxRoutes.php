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
];
