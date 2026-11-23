<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/*
 * The item of an accordion, shaped like bootstrap_package's accordion item:
 * a required header and a rich-text body.
 */
return [
    'ctrl' => [
        'title' => 'Fixture item',
        'label' => 'header',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'hideTable' => true,
        'versioningWS' => true,
        'security' => ['ignorePageTypeRestriction' => true],
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns' => ['disabled' => 'hidden'],
    ],
    'types' => [
        '1' => ['showitem' => 'parent_content, header, bodytext, sys_language_uid, l10n_parent, hidden'],
    ],
    'columns' => [
        'hidden' => ['exclude' => true, 'label' => 'Hidden', 'config' => ['type' => 'check']],
        'sys_language_uid' => ['exclude' => true, 'label' => 'Language', 'config' => ['type' => 'language']],
        'l10n_parent' => [
            'label' => 'Translation of',
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => '', 'value' => 0]], 'foreign_table' => 'tx_nrlpfixture_item', 'default' => 0],
        ],
        'l10n_diffsource' => ['config' => ['type' => 'passthrough', 'default' => '']],
        'parent_content' => [
            'label' => 'Content element',
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tt_content', 'default' => 0],
        ],
        'header' => ['exclude' => true, 'label' => 'Header', 'config' => ['type' => 'input', 'required' => true, 'max' => 255]],
        'bodytext' => ['label' => 'Text', 'config' => ['type' => 'text', 'enableRichtext' => true]],
    ],
];
