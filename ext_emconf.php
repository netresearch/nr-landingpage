<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Landing Page Generator',
    'description' => 'Generate Landing Pages via LLM using a step-by-step Backend Wizard',
    'category' => 'module',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'state' => 'beta',
    'version' => '0.3.8',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'nr_llm' => '0.32.0-0.99.99',
        ],
        'suggests' => [
            'workspaces' => '',
        ],
    ],
];
