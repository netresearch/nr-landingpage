<?php

declare(strict_types=1);

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

    public function __construct(
        private ConnectionPool $connectionPool,
        private ResourceFactory $resourceFactory,
    ) {}

    /**
     * Search for images in FAL by keywords.
     * Searches sys_file_metadata: title, description, alternative
     * and sys_file: name (filename).
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
        $queryBuilder->setMaxResults($maxResults);

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $rawUid = $row['uid'] ?? 0;
            $uid = is_int($rawUid) ? $rawUid : (is_string($rawUid) ? (int) $rawUid : 0);
            if ($uid <= 0) {
                continue;
            }

            $publicUrl = '';
            try {
                $file = $this->resourceFactory->getFileObject($uid);
                $publicUrl = $file->getPublicUrl() ?? '';
            } catch (Throwable) {
                // skip files that cannot be resolved
            }

            $result[] = [
                'uid' => $uid,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'alternative' => is_string($row['alternative'] ?? null) ? $row['alternative'] : '',
                'publicUrl' => $publicUrl,
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
