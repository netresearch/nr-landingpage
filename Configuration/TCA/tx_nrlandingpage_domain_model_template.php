<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

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
        'typeicon_classes' => [
            'default' => 'nr-landingpage-template',
        ],
        'searchFields' => 'title,identifier,description',
    ],
    'types' => [
        '1' => [
            'showitem' => implode(',', [
                '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general',
                'title,identifier,description',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.ai',
                'llm_configuration,image_task,image_size,image_folder,system_prompt',
                '--palette--;;prompt_optimizer',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.content_layout',
                'generation_mode,allowed_ctypes,ctype_rules,page_fields,reference_pages,backend_layout,content_columns',
                '--palette--;;color_scheme',
                'animation_enabled',
                '--div--;LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tabs.wizard',
                'briefing_mode,publish_mode',
                // One "Access" tab: who may use the template, and whether it is offered at all.
                '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access',
                'hidden,be_groups',
            ]),
        ],
    ],
    'palettes' => [
        'prompt_optimizer' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:palette.prompt_optimizer',
            'showitem' => 'prompt_optimizer_context,--linebreak--,prompt_optimizer_meta_prompt',
        ],
        'color_scheme' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:palette.color_scheme',
            'showitem' => 'color_primary,color_secondary,--linebreak--,color_background,color_text',
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
                // nr-llm's help under the select (the chosen configuration's
                // purpose and model) and its create / manage controls, shown
                // only to users who may use them (nr-llm ADR-255).
                'fieldWizard' => [
                    'nrllmChoiceHelp' => ['renderType' => 'nrllmChoiceHelp'],
                ],
                'fieldControl' => [
                    'addRecord' => ['renderType' => 'nrllmAddRecord', 'disabled' => false, 'options' => ['setValue' => 'set']],
                    'listModule' => ['renderType' => 'nrllmManageRecords', 'disabled' => false, 'options' => ['module' => 'nrllm_configurations', 'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.manage.configurations']],
                ],
                'items' => [
                    // The records come with an icon; without one here, the
                    // selected default would show an empty icon box.
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration.default', 'value' => 0, 'icon' => 'actions-cog'],
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
        'ctype_rules' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.ctype_rules',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.ctype_rules.description',
            'displayCond' => 'FIELD:generation_mode:=:structured',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 6,
                'enableRichtext' => false,
                'placeholder' => "header: at most 8 words, no full stop\ntext: 80 to 120 words, no more than 2 sentences per paragraph",
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
                'foreign_table_where' => 'AND {#tx_nrllm_task}.{#deleted} = 0 AND {#tx_nrllm_task}.{#hidden} = 0 ORDER BY tx_nrllm_task.name',
                // nr-llm's help under the select (what the chosen task does and
                // the configuration it runs on) and its manage control (nr-llm
                // ADR-255). No create control: an image prompt is built in the
                // tasks module, where it can be marked for this field.
                'fieldWizard' => [
                    'nrllmChoiceHelp' => ['renderType' => 'nrllmChoiceHelp'],
                ],
                'fieldControl' => [
                    'listModule' => ['renderType' => 'nrllmManageRecords', 'disabled' => false, 'options' => ['module' => 'nrllm_tasks', 'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.manage.tasks']],
                ],
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task.none', 'value' => 0, 'icon' => 'apps-filetree-folder-media'],
                ],
                // Only tasks an integrator marked as an image prompt for this
                // field are offered; nr-llm keeps the task a template already
                // holds and offers every task while none is marked (ADR-245).
                'itemsProcFunc' => \Netresearch\NrLlm\Form\Tca\TaskPlacementItems::class . '->groupByPlacement',
                'itemsProcConfig' => [
                    'placement' => 'landingpage_image',
                    'unmarked' => 'hide',
                ],
                'itemGroups' => [
                    'nrllm_placement' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task.group.marked',
                    'nrllm_unmarked' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_task.group.unmarked',
                ],
                'default' => 0,
            ],
        ],
        'image_size' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.description',
            'displayCond' => 'FIELD:generation_mode:=:structured',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.1024x1024', 'value' => '1024x1024'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.1536x1024', 'value' => '1536x1024'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.1024x1536', 'value' => '1024x1536'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.1792x1024', 'value' => '1792x1024'],
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_size.1024x1792', 'value' => '1024x1792'],
                ],
                'default' => '1024x1024',
            ],
        ],
        'image_folder' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_folder',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.image_folder.description',
            'displayCond' => 'FIELD:generation_mode:=:structured',
            'config' => [
                'type' => 'folder',
                'maxitems' => 1,
                'size' => 1,
                'default' => '',
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
        'color_primary' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_primary',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_primary.description',
            'config' => [
                'type' => 'color',
                'default' => '',
                'placeholder' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color.placeholder',
            ],
        ],
        'color_secondary' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_secondary',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_secondary.description',
            'config' => [
                'type' => 'color',
                'default' => '',
                'placeholder' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color.placeholder',
            ],
        ],
        'color_background' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_background',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_background.description',
            'config' => [
                'type' => 'color',
                'default' => '',
                'placeholder' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color.placeholder',
            ],
        ],
        'color_text' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_text',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color_text.description',
            'config' => [
                'type' => 'color',
                'default' => '',
                'placeholder' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.color.placeholder',
            ],
        ],
        'animation_enabled' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.animation_enabled',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.animation_enabled.description',
            'config' => [
                'type' => 'check',
                'default' => 1,
                'items' => [
                    ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.animation_enabled.label'],
                ],
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
        'content_columns' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.content_columns',
            'description' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.content_columns.description',
            'displayCond' => 'FIELD:backend_layout:REQ:true',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailableContentColumns',
                'default' => '',
            ],
        ],
    ],
];
