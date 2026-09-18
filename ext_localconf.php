<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Every key below is a registration timestamp, and deliberately not a round
// number. These registries are plain arrays keyed by integer, so a key picked
// twice is not a collision anyone is told about - the ext_localconf.php that
// runs later simply overwrites the earlier entry and the loser is never
// registered at all. A field control that is not registered resolves to
// UnknownElement, whose result is non-empty and carries no iconIdentifier, and
// FormEngine then aborts the whole record with RuntimeException 1483890332
// instead of rendering the field. That is what the placeholder block
// 1700000001-1700000005 used here before did against netresearch/contexts,
// which registers 1700000001-1700000003.

$GLOBALS['TYPO3_CONF_VARS']['BE']['ContextMenu']['ItemProviders'][1789736090]
    = \Netresearch\NrLandingpage\ContextMenu\LandingPageItemProvider::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1789736086] = [
    'nodeName' => 'optimizePrompt',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldControl\OptimizePromptControl::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1789736087] = [
    'nodeName' => 'testGenerate',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldControl\TestGenerateControl::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1789736088] = [
    'nodeName' => 'generatedPageCount',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldInformation\GeneratedPageCount::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1789736089] = [
    'nodeName' => 'promptToolsDescription',
    'priority' => 40,
    'class' => \Netresearch\NrLandingpage\Form\FieldInformation\PromptToolsDescription::class,
];
