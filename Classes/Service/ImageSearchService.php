<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

readonly class ImageSearchService
{
    private const STOP_WORDS = [
        'der', 'die', 'das', 'ein', 'eine', 'und', 'oder', 'mit', 'von', 'fuer', 'auf',
        'the', 'a', 'an', 'and', 'or', 'with', 'for', 'to', 'in', 'on', 'at', 'is', 'are',
    ];

    /**
     * Rows fetched per requested result. Files the backend user may not read
     * are dropped after the query, so the query looks further ahead.
     */
    private const CANDIDATE_FACTOR = 5;

    public function __construct(
        private ConnectionPool $connectionPool,
        private ResourceFactory $resourceFactory,
    ) {}

    /**
     * Search for images in FAL by keywords.
     * Searches sys_file_metadata: title, description, alternative
     * and sys_file: name (filename).
     *
     * Only files the current backend user may read are returned: the
     * storage's file mounts and the user's file permissions apply.
     *
     * @param list<string> $keywords
     * @return list<array{uid: int, name: string, title: string, alternative: string, publicUrl: string}>
     */
    public function searchByKeywords(array $keywords, int $maxResults = 5): array
    {
        if ($keywords === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder
            ->select('f.uid', 'f.name', 'm.title', 'm.alternative')
            ->from('sys_file_metadata', 'm')
            ->join(
                'm',
                'sys_file',
                'f',
                $queryBuilder->expr()->eq('m.file', $queryBuilder->quoteIdentifier('f.uid')),
            )
            ->where(
                $queryBuilder->expr()->eq('f.type', $queryBuilder->createNamedParameter(2, Connection::PARAM_INT)),
            );

        $orConditions = [];
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $likeValue = '%' . $queryBuilder->escapeLikeWildcards($keyword) . '%';
            $orConditions[] = $queryBuilder->expr()->like(
                'm.title',
                $queryBuilder->createNamedParameter($likeValue),
            );
            $orConditions[] = $queryBuilder->expr()->like(
                'm.description',
                $queryBuilder->createNamedParameter($likeValue),
            );
            $orConditions[] = $queryBuilder->expr()->like(
                'm.alternative',
                $queryBuilder->createNamedParameter($likeValue),
            );
            $orConditions[] = $queryBuilder->expr()->like(
                'f.name',
                $queryBuilder->createNamedParameter($likeValue),
            );
        }

        if ($orConditions === []) {
            return [];
        }

        $queryBuilder->andWhere($queryBuilder->expr()->or(...$orConditions));
        $queryBuilder->setMaxResults($maxResults * self::CANDIDATE_FACTOR);

        return $this->toReadableImages($queryBuilder->executeQuery()->fetchAllAssociative(), $maxResults);
    }

    /**
     * Return the most recent images from FAL as a fallback when keyword search yields no results.
     *
     * @return list<array{uid: int, name: string, title: string, alternative: string, publicUrl: string}>
     */
    public function getRecentImages(int $maxResults = 6): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder
            ->select('f.uid', 'f.name', 'm.title', 'm.alternative')
            ->from('sys_file_metadata', 'm')
            ->join(
                'm',
                'sys_file',
                'f',
                $queryBuilder->expr()->eq('m.file', $queryBuilder->quoteIdentifier('f.uid')),
            )
            ->where(
                $queryBuilder->expr()->eq('f.type', $queryBuilder->createNamedParameter(2, Connection::PARAM_INT)),
            )
            ->orderBy('f.uid', 'DESC')
            ->setMaxResults($maxResults * self::CANDIDATE_FACTOR);

        return $this->toReadableImages($queryBuilder->executeQuery()->fetchAllAssociative(), $maxResults);
    }

    /**
     * Whether the current backend user may read the file with the given uid.
     */
    public function isReadable(int $fileUid): bool
    {
        if ($fileUid <= 0) {
            return false;
        }

        try {
            return $this->resourceFactory->getFileObject($fileUid)->checkActionPermission('read');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve rows to image entries, dropping files the user may not read.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{uid: int, name: string, title: string, alternative: string, publicUrl: string}>
     */
    private function toReadableImages(array $rows, int $maxResults): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (count($result) >= $maxResults) {
                break;
            }

            $rawUid = $row['uid'] ?? 0;
            $uid = is_int($rawUid) ? $rawUid : (is_string($rawUid) ? (int) $rawUid : 0);
            if ($uid <= 0) {
                continue;
            }

            try {
                $file = $this->resourceFactory->getFileObject($uid);
            } catch (Throwable) {
                continue;
            }
            if (!$file->checkActionPermission('read')) {
                continue;
            }

            $result[] = [
                'uid' => $uid,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'alternative' => is_string($row['alternative'] ?? null) ? $row['alternative'] : '',
                'publicUrl' => $file->getPublicUrl() ?? '',
            ];
        }

        return $result;
    }

    /**
     * Extract search keywords from descriptive text.
     * Filters short words (< 3 chars) and common stop words.
     *
     * @return list<string>
     */
    public function extractKeywords(string $text): array
    {
        $text = strip_tags($text);
        $words = preg_split('/[\s,;.!?]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $words,
            static fn(string $w): bool => strlen($w) >= 3 && !in_array($w, self::STOP_WORDS, true),
        )));
    }
}
