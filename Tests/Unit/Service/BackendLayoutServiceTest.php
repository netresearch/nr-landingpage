<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\BackendLayoutService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(BackendLayoutService::class)]
final class BackendLayoutServiceTest extends UnitTestCase
{
    private DataProviderCollection $dataProviderCollection;
    private BackendLayoutService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => str_starts_with($key, 'LLL:') ? substr($key, strrpos($key, ':') + 1) : $key,
        );
        $GLOBALS['LANG'] = $languageService;

        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $backendLayoutView = $this->createMock(BackendLayoutView::class);
        $this->dataProviderCollection = $this->createMock(DataProviderCollection::class);
        $connectionPool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $this->subject = new BackendLayoutService($this->dataProviderCollection, $languageServiceFactory, $connectionPool, $backendLayoutView);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function getColumnMapReturnsDefaultForEmptyIdentifier(): void
    {
        $result = $this->subject->getColumnMap('');

        self::assertSame([0 => 'Main'], $result);
    }

    #[Test]
    public function getColumnMapReturnsDefaultWhenLayoutNotFound(): void
    {
        $this->dataProviderCollection->method('getBackendLayout')->willReturn(null);

        $result = $this->subject->getColumnMap('pagets__nonexistent');

        self::assertSame([0 => 'Main'], $result);
    }

    #[Test]
    public function getColumnMapReturnsDefaultWhenLayoutHasNoColumns(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([]);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $result = $this->subject->getColumnMap('pagets__empty');

        self::assertSame([0 => 'Main'], $result);
    }

    #[Test]
    public function getColumnMapReturnsResolvedColumns(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'Main Content',
            1 => 'Sidebar',
            2 => 'Footer',
        ]);
        $this->dataProviderCollection->method('getBackendLayout')
            ->with('pagets__3col', 0)
            ->willReturn($layout);

        $result = $this->subject->getColumnMap('pagets__3col');

        self::assertSame([
            0 => 'Main Content',
            1 => 'Sidebar',
            2 => 'Footer',
        ], $result);
    }

    #[Test]
    public function getColumnMapFallsBackToGenericLabelWhenEmpty(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'Main',
            1 => '',
        ]);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $result = $this->subject->getColumnMap('pagets__test');

        self::assertSame('Main', $result[0]);
        self::assertSame('Column 1', $result[1]);
    }

    #[Test]
    public function getColumnMapResolvesLllLabels(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'LLL:EXT:example/Resources/Private/Language/locallang.xlf:main',
            1 => 'Sidebar',
        ]);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $result = $this->subject->getColumnMap('pagets__lll');

        self::assertSame('main', $result[0]);
        self::assertSame('Sidebar', $result[1]);
    }

    #[Test]
    public function getColumnMapPassesPageIdToDataProvider(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'Main Content',
            1 => 'Sidebar',
        ]);
        $this->dataProviderCollection->expects(self::once())
            ->method('getBackendLayout')
            ->with('pagets__2col', 42)
            ->willReturn($layout);

        $result = $this->subject->getColumnMap('pagets__2col', 42);

        self::assertCount(2, $result);
        self::assertSame('Main Content', $result[0]);
        self::assertSame('Sidebar', $result[1]);
    }

    #[Test]
    public function formatColumnMapForPromptReturnsEmptyForSingleColumn(): void
    {
        $result = $this->subject->formatColumnMapForPrompt([0 => 'Main']);

        self::assertSame('', $result);
    }

    #[Test]
    public function formatColumnMapForPromptFormatsMultipleColumns(): void
    {
        $result = $this->subject->formatColumnMapForPrompt([
            0 => 'Main Content',
            1 => 'Sidebar',
        ]);

        self::assertSame("- colPos 0: \"Main Content\"\n- colPos 1: \"Sidebar\"", $result);
    }
}
