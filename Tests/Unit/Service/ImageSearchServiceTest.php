<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ImageSearchService::class)]
final class ImageSearchServiceTest extends UnitTestCase
{
    #[Test]
    public function searchByKeywordsReturnsEmptyForEmptyKeywords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        self::assertSame([], $service->searchByKeywords([]));
    }

    #[Test]
    public function searchByKeywordsReturnsEmptyForBlankKeywords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        self::assertSame([], $service->searchByKeywords(['', '  ']));
    }

    #[Test]
    public function extractKeywordsFiltersStopWords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        $result = $service->extractKeywords('Der große Event mit Networking');

        self::assertNotContains('der', $result);
        self::assertNotContains('mit', $result);
        self::assertContains('große', $result);
        self::assertContains('event', $result);
        self::assertContains('networking', $result);
    }

    #[Test]
    public function extractKeywordsFiltersShortWords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        $result = $service->extractKeywords('An AI tool for web dev');

        self::assertNotContains('an', $result);
        self::assertNotContains('ai', $result);
        self::assertContains('tool', $result);
        self::assertContains('web', $result);
        self::assertContains('dev', $result);
    }

    #[Test]
    public function extractKeywordsReturnsUniqueValues(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        $result = $service->extractKeywords('Event Event Event Konferenz');

        self::assertCount(2, $result);
        self::assertContains('event', $result);
        self::assertContains('konferenz', $result);
    }

    #[Test]
    public function extractKeywordsStripsHtml(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        $result = $service->extractKeywords('<strong>Bold</strong> <em>italic</em> text');

        self::assertContains('bold', $result);
        self::assertContains('italic', $result);
        self::assertContains('text', $result);
        self::assertNotContains('strong', $result);
    }

    #[Test]
    public function extractKeywordsReturnsEmptyForEmptyInput(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        self::assertSame([], $service->extractKeywords(''));
    }

    #[Test]
    public function extractKeywordsSplitsOnMultipleDelimiters(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class), $this->createResourceFactoryMock());
        $result = $service->extractKeywords('word1,word2;word3.word4!word5?word6');

        self::assertCount(6, $result);
    }

    /**
     * Creates a mocked ConnectionPool with a fully stubbed QueryBuilder chain.
     *
     * @param list<array<string, mixed>> $rows Rows returned by fetchAllAssociative
     */
    private function createConnectionPoolWithQueryBuilder(array $rows): ConnectionPool
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('like')->willReturn('field LIKE %keyword%');
        $compositeExpression = $this->createMock(CompositeExpression::class);
        $expressionBuilder->method('or')->willReturn($compositeExpression);

        $statement = $this->createMock(Result::class);
        $statement->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'%keyword%'");
        $queryBuilder->method('quoteIdentifier')->willReturnArgument(0);
        $queryBuilder->method('escapeLikeWildcards')->willReturnArgument(0);
        $queryBuilder->method('executeQuery')->willReturn($statement);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    /**
     * Returns the QueryBuilder mock from a ConnectionPool mock.
     */
    private function getQueryBuilderMock(ConnectionPool $connectionPool): QueryBuilder
    {
        /** @var QueryBuilder $qb */
        $qb = $connectionPool->getQueryBuilderForTable('sys_file_metadata');
        return $qb;
    }

    #[Test]
    public function searchByKeywordsBuildsQueryForValidKeywords(): void
    {
        $rows = [
            ['uid' => 1, 'name' => 'photo.jpg', 'title' => 'Photo', 'alternative' => 'Alt text'],
            ['uid' => 2, 'name' => 'banner.png', 'title' => 'Banner', 'alternative' => ''],
        ];

        $connectionPool = $this->createConnectionPoolWithQueryBuilder($rows);
        $service = new ImageSearchService($connectionPool, $this->createResourceFactoryMock());

        $result = $service->searchByKeywords(['landscape']);

        self::assertCount(2, $result);

        self::assertSame(1, $result[0]['uid']);
        self::assertSame('photo.jpg', $result[0]['name']);
        self::assertSame('Photo', $result[0]['title']);
        self::assertSame('Alt text', $result[0]['alternative']);
        self::assertSame('/fileadmin/test.jpg', $result[0]['publicUrl']);

        self::assertSame(2, $result[1]['uid']);
        self::assertSame('banner.png', $result[1]['name']);
        self::assertSame('Banner', $result[1]['title']);
        self::assertSame('', $result[1]['alternative']);
        self::assertSame('/fileadmin/test.jpg', $result[1]['publicUrl']);
    }

    #[Test]
    public function searchByKeywordsRespectsMaxResults(): void
    {
        $connectionPool = $this->createConnectionPoolWithQueryBuilder([]);
        $queryBuilder = $this->getQueryBuilderMock($connectionPool);

        $queryBuilder->expects(self::atLeastOnce())
            ->method('setMaxResults')
            ->with(10)
            ->willReturnSelf();

        $service = new ImageSearchService($connectionPool, $this->createResourceFactoryMock());
        $service->searchByKeywords(['keyword'], 10);
    }

    #[Test]
    public function searchByKeywordsHandlesStringUidInResult(): void
    {
        $rows = [
            ['uid' => '42', 'name' => 'image.jpg', 'title' => 'Image', 'alternative' => 'Alt'],
        ];

        $connectionPool = $this->createConnectionPoolWithQueryBuilder($rows);
        $service = new ImageSearchService($connectionPool, $this->createResourceFactoryMock());

        $result = $service->searchByKeywords(['test']);

        self::assertCount(1, $result);
        self::assertSame(42, $result[0]['uid']);
        self::assertIsInt($result[0]['uid']);
        self::assertSame('/fileadmin/test.jpg', $result[0]['publicUrl']);
    }

    #[Test]
    public function searchByKeywordsSkipsRowsWithInvalidUid(): void
    {
        $rows = [
            ['uid' => null, 'name' => null, 'title' => null, 'alternative' => null],
            [],
            ['uid' => 0, 'name' => 'zero.jpg', 'title' => 'Zero', 'alternative' => ''],
        ];

        $connectionPool = $this->createConnectionPoolWithQueryBuilder($rows);
        $service = new ImageSearchService($connectionPool, $this->createResourceFactoryMock());

        $result = $service->searchByKeywords(['test']);

        // All rows have uid <= 0, so all are skipped
        self::assertSame([], $result);
    }

    #[Test]
    public function searchByKeywordsSkipsBlankKeywordsInMixedArray(): void
    {
        $rows = [
            ['uid' => 1, 'name' => 'img.jpg', 'title' => 'Title', 'alternative' => 'Alt'],
        ];

        $connectionPool = $this->createConnectionPoolWithQueryBuilder($rows);
        $queryBuilder = $this->getQueryBuilderMock($connectionPool);
        $expressionBuilder = $queryBuilder->expr();

        // like() should be called 4 times per valid keyword (title, description, alternative, name).
        // With 2 valid keywords ('valid', 'also-valid') that means 8 calls total.
        // Blank keyword '' should be skipped.
        $expressionBuilder->expects(self::exactly(8))->method('like');

        $service = new ImageSearchService($connectionPool, $this->createResourceFactoryMock());
        $result = $service->searchByKeywords(['valid', '', 'also-valid']);

        self::assertCount(1, $result);
    }

    /**
     * Creates a ResourceFactory mock that returns a File stub with a public URL.
     */
    private function createResourceFactoryMock(): ResourceFactory
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/test.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->willReturn($file);

        return $resourceFactory;
    }
}
