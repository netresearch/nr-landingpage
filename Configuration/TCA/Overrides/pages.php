<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Register generation metadata columns on the pages table.
 *
 * These fields are written by PageCreatorService and read by
 * LandingPageDetectionService / the wizard's generationInfo endpoint.
 * They are not shown in the backend form (no showitem entry).
 */
$columns = [
    'tx_nrlandingpage_template_uid' => [
        'config' => [
            'type' => 'passthrough',
        ],
    ],
    'tx_nrlandingpage_briefing_data' => [
        'config' => [
            'type' => 'passthrough',
        ],
    ],
    'tx_nrlandingpage_config_hash' => [
        'config' => [
            'type' => 'passthrough',
        ],
    ],
    'tx_nrlandingpage_generated_at' => [
        'config' => [
            'type' => 'passthrough',
        ],
    ],
    'tx_nrlandingpage_source_page_uid' => [
        'config' => [
            'type' => 'passthrough',
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('pages', $columns);
