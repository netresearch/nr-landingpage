<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests for ContentGeneratorService's section validation and column handling,
 * exercised through the BackendLayoutService integration.
 */
#[CoversClass(ContentGeneratorService::class)]
final class ContentGeneratorServiceValidationTest extends UnitTestCase
{
    private ContentGeneratorService $subject;
    private BackendLayoutService $backendLayoutService;
    private DataProviderCollection $dataProviderCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => str_starts_with($key, 'LLL:') ? substr($key, strrpos($key, ':') + 1) : $key,
        );
        $GLOBALS['LANG'] = $languageService;

        $this->dataProviderCollection = $this->createMock(DataProviderCollection::class);
        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $backendLayoutView = $this->createMock(BackendLayoutView::class);

        $this->backendLayoutService = new BackendLayoutService(
            $this->dataProviderCollection,
            $languageServiceFactory,
            $backendLayoutView,
        );

        $this->subject = new ContentGeneratorService(
            $this->createMock(CompletionService::class),
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $this->createMock(CTypeMetadataService::class),
            $this->backendLayoutService,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function validateSectionsCoercesInvalidColPosToFirstValid(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 99, 'header' => 'H', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1]);

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function validateSectionsAcceptsValidColPosForMultiColumn(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'Main', 'bodytext' => ''],
            ['section' => 'Sidebar', 'ctype' => 'text', 'colPos' => 1, 'header' => 'Side', 'bodytext' => ''],
            ['section' => 'Footer CTA', 'ctype' => 'text', 'colPos' => 2, 'header' => 'CTA', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1, 2]);

        self::assertCount(3, $result);
        self::assertSame(0, $result[0]['colPos']);
        self::assertSame(1, $result[1]['colPos']);
        self::assertSame(2, $result[2]['colPos']);
    }

    #[Test]
    public function validateSectionsHandlesStringColPos(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => '1', 'header' => 'H', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1]);

        self::assertSame(1, $result[0]['colPos']);
    }

    #[Test]
    public function buildColumnBlockReturnsEmptyForSingleColumn(): void
    {
        $this->dataProviderCollection->method('getBackendLayout')->willReturn(null);

        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $result = $method->invoke($this->subject, 'pagets__default', 0);

        self::assertSame('', $result);
    }

    #[Test]
    public function buildColumnBlockIncludesAllColumnsForMultiColumnLayout(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'Main Content',
            1 => 'Sidebar',
        ]);
        $this->dataProviderCollection->method('getBackendLayout')
            ->with('pagets__2col', 42)
            ->willReturn($layout);

        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $result = $method->invoke($this->subject, 'pagets__2col', 42);

        self::assertStringContainsString('2 Inhaltsbereiche', $result);
        self::assertStringContainsString('colPos 0: "Main Content"', $result);
        self::assertStringContainsString('colPos 1: "Sidebar"', $result);
        self::assertStringContainsString('ALLE 2 Spalten verteilen', $result);
    }

    #[Test]
    public function buildColumnBlockPassesPageIdToBackendLayoutService(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([0 => 'Main', 1 => 'Side']);

        $this->dataProviderCollection->expects(self::once())
            ->method('getBackendLayout')
            ->with('pagets__2col', 123)
            ->willReturn($layout);

        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $method->invoke($this->subject, 'pagets__2col', 123);
    }

    #[Test]
    public function buildJsonExampleShowsMultipleColPosForMultiColumnLayout(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([
            0 => 'Main Content',
            1 => 'Sidebar',
        ]);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, 'pagets__2col', 'text, textmedia', 0);

        self::assertStringContainsString('"colPos": 0', $result);
        self::assertStringContainsString('"colPos": 1', $result);
        self::assertStringContainsString('Main Content', $result);
        self::assertStringContainsString('Sidebar', $result);
    }

    #[Test]
    public function buildJsonExampleReturnsSingleColPosFallback(): void
    {
        $this->dataProviderCollection->method('getBackendLayout')->willReturn(null);

        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, '', 'text', 0);

        self::assertStringContainsString('"colPos": 0', $result);
        self::assertStringNotContainsString('"colPos": 1', $result);
    }
}
