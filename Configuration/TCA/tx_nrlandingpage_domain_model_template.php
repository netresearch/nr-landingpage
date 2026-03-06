<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:nr_landingpage/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,identifier,description',
    ],
    'types' => [
        '1' => [
            'showitem' => implode(',', [
                '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general',
                'title,identifier,description',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.llm',
                'llm_configuration,system_prompt',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.content',
                'allowed_ctypes,reference_pages',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.page_fields',
                'page_fields',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.wizard',
                'briefing_mode,publish_mode',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.access',
                'be_groups',
                '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access',
                'hidden',
            ]),
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.identifier',
            'config' => [
                'type' => 'slug',
                'generatorOptions' => [
                    'fields' => ['title'],
                    'replacements' => [
                        '/' => '-',
                    ],
                ],
                'fallbackCharacter' => '-',
                'eval' => 'uniqueInSite',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
        'llm_configuration' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_nrllm_domain_model_llmconfiguration',
                'maxitems' => 1,
                'size' => 1,
            ],
        ],
        'system_prompt' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.system_prompt',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 10,
                'enableRichtext' => false,
            ],
        ],
        'allowed_ctypes' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.allowed_ctypes',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailableCTypes',
            ],
        ],
        'page_fields' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.page_fields',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailablePageFields',
            ],
        ],
        'reference_pages' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.reference_pages',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'maxitems' => 99,
                'size' => 5,
            ],
        ],
        'briefing_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode.none', 'value' => 'none'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode.optional', 'value' => 'optional'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode.required', 'value' => 'required'],
                ],
                'default' => 'optional',
            ],
        ],
        'publish_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.publish_mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.publish_mode.hidden', 'value' => 'hidden'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.publish_mode.visible', 'value' => 'visible'],
                ],
                'default' => 'hidden',
            ],
        ],
        'be_groups' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.be_groups',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'size' => 5,
                'maxitems' => 99,
            ],
        ],
    ],
];
