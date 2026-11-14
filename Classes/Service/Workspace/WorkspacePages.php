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
     * The uids of the pages whose marker starts with the given text: the
     * whole marker `<draft uid>:<attempt>` for one attempt, `<draft uid>:` for
     * every attempt of a draft. Deleted pages are not listed.
     *
     * @return list<int>
     */
    public function findByRef(string $refOrPrefix): array
    {
        if ($refOrPrefix === '') {
            return [];
        }
        $builder = $this->connectionPool->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid')
            ->from('pages')
            ->where(
                $builder->expr()->like('tx_nrlandingpage_workspace_ref', $builder->createNamedParameter($builder->escapeLikeWildcards($refOrPrefix) . '%')),
                $builder->expr()->eq('deleted', $builder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $rows));
    }

    /**
     * Delete pages (with their content), as the current backend user. A page
     * DataHandler refuses stays and is logged.
     *
     * @param list<int> $pageUids
     */
    public function delete(array $pageUids): void
    {
        $errors = $this->pageCreator->deletePages($pageUids);
        if ($errors > 0) {
            $this->logger?->error('Deleting the pages of an undone workspace approval failed', ['pages' => $pageUids, 'errors' => $errors]);
        }
    }
}
