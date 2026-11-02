<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

defined('TYPO3') or die();

// The landing page template field "image_task" offers the tasks marked for
// this place first and hides the others (nr-llm ADR-245).
$GLOBALS['TCA']['tx_nrllm_task']['columns']['placements']['config']['items'][] = [
    'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrllm_task.placements.landingpage_image',
    'value' => 'landingpage_image',
];
