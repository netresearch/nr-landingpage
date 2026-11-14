<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\DataHandling;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps `pages.tx_nrlandingpage_workspace_ref` an approval's own marker: no
 * DataHandler write sets it except the approval's (WorkspaceRefGate), and a
 * copied page does not carry it along.
 */
final class WorkspaceRefDataHandlerHook
{
    private const FIELD = 'tx_nrlandingpage_workspace_ref';

    /**
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table, string|int $id, DataHandler $dataHandler): void
    {
        if ($table === 'pages' && array_key_exists(self::FIELD, $incomingFieldArray) && !WorkspaceRefGate::allows($incomingFieldArray[self::FIELD])) {
            unset($incomingFieldArray[self::FIELD]);
        }
    }

    /**
     * @param int|string $id
     * @param mixed $value
     * @param array<string, mixed>|null $pasteDatamap
     */
    public function processCmdmap_postProcess(string $command, string $table, $id, $value, DataHandler $dataHandler, mixed $pasteUpdate, mixed $pasteDatamap): void
    {
        if ($command !== 'copy' || $table !== 'pages') {
            return;
        }
        $copies = $dataHandler->copyMappingArray_merged['pages'] ?? [];
        if (!is_array($copies)) {
            return;
        }
        $uids = array_values(array_filter(array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $copies), static fn(int $uid): bool => $uid > 0));
        if ($uids === []) {
            return;
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        foreach ($uids as $uid) {
            $connection->update('pages', [self::FIELD => ''], ['uid' => $uid]);
        }
    }
}
