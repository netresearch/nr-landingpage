<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'Landing Page Generator',
    'description' => 'Generate landing pages with an LLM in a step-by-step backend wizard.',
    'category' => 'module',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'state' => 'beta',
    'version' => '0.3.20',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'nr_llm' => '0.34.0-0.40.99',
        ],
        'suggests' => [
            'workspaces' => '',
        ],
    ],
];
