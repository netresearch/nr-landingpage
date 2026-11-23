<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_nrlpfixture_items' => [
        'label' => 'Items',
        'config' => ['type' => 'inline', 'foreign_table' => 'tx_nrlpfixture_item', 'foreign_field' => 'parent_content'],
    ],
]);
ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', ['label' => 'Fixture accordion', 'value' => 'nrlp_accordion']);

/** @var array{tt_content: array{types: array<string, mixed>}} $tca */
$tca = &$GLOBALS['TCA'];
$tca['tt_content']['types']['nrlp_accordion'] = ['showitem' => '--palette--;;headers, tx_nrlpfixture_items'];
unset($tca);
