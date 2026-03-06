<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\ImageSearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ImageSearchService::class)]
final class ImageSearchServiceTest extends UnitTestCase
{
    #[Test]
    public function searchByKeywordsReturnsEmptyForEmptyKeywords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        self::assertSame([], $service->searchByKeywords([]));
    }

    #[Test]
    public function searchByKeywordsReturnsEmptyForBlankKeywords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        self::assertSame([], $service->searchByKeywords(['', '  ']));
    }

    #[Test]
    public function extractKeywordsFiltersStopWords(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
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
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
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
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        $result = $service->extractKeywords('Event Event Event Konferenz');

        self::assertCount(2, $result);
        self::assertContains('event', $result);
        self::assertContains('konferenz', $result);
    }

    #[Test]
    public function extractKeywordsStripsHtml(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        $result = $service->extractKeywords('<strong>Bold</strong> <em>italic</em> text');

        self::assertContains('bold', $result);
        self::assertContains('italic', $result);
        self::assertContains('text', $result);
        self::assertNotContains('strong', $result);
    }

    #[Test]
    public function extractKeywordsReturnsEmptyForEmptyInput(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        self::assertSame([], $service->extractKeywords(''));
    }

    #[Test]
    public function extractKeywordsSplitsOnMultipleDelimiters(): void
    {
        $service = new ImageSearchService($this->createMock(ConnectionPool::class));
        $result = $service->extractKeywords('word1,word2;word3.word4!word5?word6');

        self::assertCount(6, $result);
    }
}
