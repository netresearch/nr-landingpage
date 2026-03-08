<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLandingpage\Service\LandingPageDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(LandingPageDetectionService::class)]
final class LandingPageDetectionServiceTest extends UnitTestCase
{
    #[Test]
    public function returnsFalseForZeroPageUid(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $service = new LandingPageDetectionService($connectionPool);

        self::assertFalse($service->isGeneratedLandingPage(0));
    }

    #[Test]
    public function returnsFalseForNegativePageUid(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $service = new LandingPageDetectionService($connectionPool);

        self::assertFalse($service->isGeneratedLandingPage(-1));
    }

    #[Test]
    public function returnsFalseWhenNoTemplateUid(): void
    {
        $service = new LandingPageDetectionService($this->createMockConnectionPool(false));

        self::assertFalse($service->isGeneratedLandingPage(42));
    }

    #[Test]
    public function returnsFalseWhenTemplateUidIsZero(): void
    {
        $service = new LandingPageDetectionService($this->createMockConnectionPool(0));

        self::assertFalse($service->isGeneratedLandingPage(42));
    }

    #[Test]
    public function returnsTrueWhenTemplateUidIsPositive(): void
    {
        $service = new LandingPageDetectionService($this->createMockConnectionPool(5));

        self::assertTrue($service->isGeneratedLandingPage(42));
    }

    #[Test]
    public function returnsTrueWhenTemplateUidIsNumericString(): void
    {
        $service = new LandingPageDetectionService($this->createMockConnectionPool('3'));

        self::assertTrue($service->isGeneratedLandingPage(42));
    }

    private function createMockConnectionPool(mixed $fetchOneResult): ConnectionPool
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($fetchOneResult);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
