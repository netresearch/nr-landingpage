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
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.ai',
                'llm_configuration,image_task,system_prompt',
                '--palette--;;prompt_optimizer',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.content_layout',
                'generation_mode,allowed_ctypes,page_fields,reference_pages,backend_layout',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.wizard',
                'briefing_mode,publish_mode',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.access',
                'be_groups',
                '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access',
                'hidden',
            ]),
        ],
    ],
    'palettes' => [
        'prompt_optimizer' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:palette.prompt_optimizer',
            'showitem' => 'prompt_optimizer_context,--linebreak--,prompt_optimizer_meta_prompt',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.title',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.title.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
                'fieldInformation' => [
                    'generatedPageCount' => [
                        'renderType' => 'generatedPageCount',
                    ],
                ],
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.identifier',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.identifier.description',
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
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.description.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
        'llm_configuration' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_configuration',
                'foreign_table_where' => 'AND {#tx_nrllm_configuration}.{#deleted} = 0 ORDER BY tx_nrllm_configuration.name',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration.default', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'system_prompt' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.system_prompt',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.system_prompt.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 10,
                'enableRichtext' => false,
                'fieldInformation' => [
                    'promptToolsDescription' => [
                        'renderType' => 'promptToolsDescription',
                    ],
                ],
                'fieldControl' => [
                    'optimizePrompt' => [
                        'renderType' => 'optimizePrompt',
                    ],
                    'testGenerate' => [
                        'renderType' => 'testGenerate',
                    ],
                ],
            ],
        ],
        'allowed_ctypes' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.allowed_ctypes',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.allowed_ctypes.description',
            'displayCond' => 'FIELD:generation_mode:=:structured',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailableCTypes',
            ],
        ],
        'page_fields' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.page_fields',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.page_fields.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailablePageFields',
                'default' => 'seo_title,description,og_title,og_description',
            ],
        ],
        'reference_pages' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.reference_pages',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.reference_pages.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'maxitems' => 99,
                'size' => 5,
            ],
        ],
        'briefing_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode.description',
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
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.publish_mode.description',
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
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.be_groups.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'size' => 5,
                'maxitems' => 99,
            ],
        ],
        'prompt_optimizer_context' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.prompt_optimizer_context',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.prompt_optimizer_context.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
        'prompt_optimizer_meta_prompt' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.prompt_optimizer_meta_prompt',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.prompt_optimizer_meta_prompt.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 10,
            ],
        ],
        'image_task' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task.description',
            'displayCond' => 'FIELD:generation_mode:=:structured',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_task',
                'foreign_table_where' => 'AND {#tx_nrllm_task}.{#deleted} = 0 AND {#tx_nrllm_task}.{#hidden} = 0 ORDER BY tx_nrllm_task.category, tx_nrllm_task.name',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task.none', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'generation_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.generation_mode',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.generation_mode.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.generation_mode.structured', 'value' => 'structured'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.generation_mode.creative', 'value' => 'creative'],
                ],
                'default' => 'structured',
            ],
        ],
        'backend_layout' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.backend_layout',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.backend_layout.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailableBackendLayouts',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.backend_layout.none', 'value' => ''],
                ],
                'fieldWizard' => [
                    'selectIcons' => [
                        'disabled' => false,
                    ],
                ],
                'default' => '',
            ],
        ],
    ],
];
