<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use Netresearch\NrLandingpage\Service\PageCreatorService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The pages made from workspace drafts, found by the marker the page's first
 * insert carries (`tx_nrlandingpage_workspace_ref`, ADR-0001, section 9).
 */
class WorkspacePages implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PageCreatorService $pageCreator,
    ) {}

    /**
     * The uids of the pages directly below the given parent whose marker is
     * exactly `<draft uid>:<approval attempt>`. Never a prefix, never another
     * parent: an approval removes only what that approval created, whatever a
     * page elsewhere carries in its marker field. Deleted pages are not listed.
     *
     * @return list<int>
     */
    public function findByRef(string $ref, int $parentPageUid): array
    {
        if ($parentPageUid <= 0 || preg_match('/^[1-9][0-9]*:[A-Za-z0-9]{1,32}$/D', $ref) !== 1) {
            return [];
        }
        $builder = $this->connectionPool->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid')
            ->from('pages')
            ->where(
                $builder->expr()->eq('tx_nrlandingpage_workspace_ref', $builder->createNamedParameter($ref)),
                $builder->expr()->eq('pid', $builder->createNamedParameter($parentPageUid, Connection::PARAM_INT)),
                $builder->expr()->eq('deleted', $builder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $rows));
    }

    /**
     * Delete pages (with their content), as the current backend user. A page
     * DataHandler refuses stays and is logged. DataHandler also reports no error
     * for a page it only discards (one that lives in a non-live workspace), so
     * every requested page is read back and counts as an error while it remains.
     *
     * @param list<int> $pageUids
     * @return int how many errors DataHandler reported plus how many pages remain, 0 when every page is gone
     */
    public function delete(array $pageUids): int
    {
        $errors = $this->pageCreator->deletePages($pageUids);
        $remaining = $this->stillThere($pageUids);
        if ($errors > 0 || $remaining !== []) {
            $this->logger?->error('Deleting the pages of an undone workspace approval failed', ['pages' => $pageUids, 'errors' => $errors, 'remaining' => $remaining]);
        }

        return $errors + count($remaining);
    }

    /**
     * @param list<int> $pageUids
     * @return list<int> the requested pages that exist and are not deleted
     */
    private function stillThere(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }
        $builder = $this->connectionPool->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid')
            ->from('pages')
            ->where(
                $builder->expr()->in('uid', $builder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $builder->expr()->eq('deleted', $builder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $rows));
    }
}
