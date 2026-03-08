<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['BE']['ContextMenu']['ItemProviders'][1700000001]
    = \Netresearch\NrLandingpage\ContextMenu\LandingPageItemProvider::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000002] = [
    'nodeName' => 'optimizePrompt',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldControl\OptimizePromptControl::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000003] = [
    'nodeName' => 'testGenerate',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldControl\TestGenerateControl::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000004] = [
    'nodeName' => 'generatedPageCount',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldInformation\GeneratedPageCount::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000005] = [
    'nodeName' => 'promptToolsDescription',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldInformation\PromptToolsDescription::class,
];
