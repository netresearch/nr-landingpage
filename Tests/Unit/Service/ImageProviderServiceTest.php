<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\ImageProviderService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Tests\Unit\Service\Fixtures\StorableMessageException;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ImageProviderService::class)]
final class ImageProviderServiceTest extends UnitTestCase
{
    private ImageSearchService&MockObject $imageSearchService;
    private ConnectionPool&MockObject $connectionPool;
    private StorageRepository&MockObject $storageRepository;
    private ResourceFactory&MockObject $resourceFactory;
    private ImageProviderService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imageSearchService = $this->createMock(ImageSearchService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->storageRepository = $this->createMock(StorageRepository::class);
        $this->resourceFactory = $this->createMock(ResourceFactory::class);

        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';

        $this->subject = new ImageProviderService(
            $this->imageSearchService,
            $this->connectionPool,
            $this->storageRepository,
            $this->resourceFactory,
        );
    }

    private function createTemplate(int $imageTask = 0): Template
    {
        return new Template(
            uid: 1,
            title: 'Test',
            identifier: 'test',
            imageTask: $imageTask,
        );
    }

    /**
     * Returns a minimal valid 1x1 PNG binary string.
     */
    private static function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQAB'
            . 'Nl7BcQAAAABJRU5ErkJggg==',
        );
    }

    private function registerFakeGenerator(): void
    {
        $this->subject->registerGenerator(
            'fake',
            fn(string $prompt, array $options) => new ImageGenerationResult(
                url: 'https://example.com/gen.png',
                base64: base64_encode(self::minimalPng()),
                prompt: $prompt,
                revisedPrompt: null,
                model: 'dall-e-3',
                size: '1024x1024',
                provider: 'dall-e',
            ),
            fn() => true,
        );
    }

    #[Test]
    public function resolveImagesForSectionsFalOnlyWhenNoImageTask(): void
    {
        $template = $this->createTemplate(0);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => ['welcome', 'hero'], 'imagePrompt' => ''],
        ];

        $falResults = [
            ['uid' => 1, 'name' => 'hero.jpg', 'title' => 'Hero', 'alternative' => '', 'publicUrl' => '/hero.jpg'],
        ];
        $this->imageSearchService->method('searchByKeywords')->willReturn($falResults);

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertCount(1, $result);
        self::assertCount(1, $result[0]);
        self::assertSame(1, $result[0][0]['uid']);
        self::assertTrue($result[0][0]['recommended']);
    }

    #[Test]
    public function resolveImagesForSectionsFallsBackToRecentImagesWhenKeywordSearchEmpty(): void
    {
        $template = $this->createTemplate(0);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => ['welcome'], 'imagePrompt' => ''],
        ];

        $this->imageSearchService->method('searchByKeywords')->willReturn([]);
        $recentImage = ['uid' => 99, 'name' => 'recent.jpg', 'title' => 'Recent', 'alternative' => '', 'publicUrl' => '/recent.jpg'];
        $this->imageSearchService->method('getRecentImages')->willReturn([$recentImage]);

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertCount(1, $result[0]);
        self::assertSame(99, $result[0][0]['uid']);
        self::assertTrue($result[0][0]['recommended']);
    }

    #[Test]
    public function resolveImagesForSectionsReturnsEmptyWhenNoFalAndNoRecentAndNoImageTask(): void
    {
        $template = $this->createTemplate(0);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => ['welcome'], 'imagePrompt' => ''],
        ];

        $this->imageSearchService->method('searchByKeywords')->willReturn([]);
        $this->imageSearchService->method('getRecentImages')->willReturn([]);

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertSame([[]], $result);
    }

    #[Test]
    public function resolveImagesForSectionsGeneratesAiWhenImageTaskSetAndFalEmpty(): void
    {
        $template = $this->createTemplate(5);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => ['welcome'], 'imagePrompt' => 'A hero image'],
        ];

        $this->imageSearchService->method('searchByKeywords')->willReturn([]);
        $this->imageSearchService->method('getRecentImages')->willReturn([]);
        $this->registerFakeGenerator();
        $this->mockStorageForGeneration();
        $this->mockTaskPromptTemplate(5, '');

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertCount(1, $result[0]);
        self::assertTrue($result[0][0]['generated']);
        self::assertTrue($result[0][0]['recommended']);
    }

    #[Test]
    public function resolveImagesForSectionsCombinesFalAndAiWhenImageTaskSet(): void
    {
        $template = $this->createTemplate(5);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => ['welcome'], 'imagePrompt' => 'A hero image'],
        ];

        $falResults = [
            ['uid' => 1, 'name' => 'hero.jpg', 'title' => 'Hero', 'alternative' => '', 'publicUrl' => '/hero.jpg'],
        ];
        $this->imageSearchService->method('searchByKeywords')->willReturn($falResults);
        $this->registerFakeGenerator();
        $this->mockStorageForGeneration();
        $this->mockTaskPromptTemplate(5, '');

        $result = $this->subject->resolveImagesForSections($template, $sections);

        // AI-generated image is first (recommended), FAL image follows
        self::assertCount(2, $result[0]);
        self::assertTrue($result[0][0]['generated']);
        self::assertTrue($result[0][0]['recommended']);
        self::assertSame(1, $result[0][1]['uid']);
    }

    #[Test]
    public function resolveImagesForSectionsReturnsEmptyWhenNoGeneratorAvailable(): void
    {
        $template = $this->createTemplate(5);
        $sections = [
            ['section' => 'Hero', 'header' => 'Welcome', 'imageKeywords' => [], 'imagePrompt' => 'A hero image'],
        ];

        $this->imageSearchService->method('extractKeywords')->willReturn([]);
        $this->imageSearchService->method('getRecentImages')->willReturn([]);

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertSame([[]], $result);
    }

    #[Test]
    public function resolveImagesForSectionsFallsBackToHeaderKeywords(): void
    {
        $template = $this->createTemplate(0);
        $sections = [
            ['section' => 'Hero', 'header' => 'Our Services', 'imageKeywords' => [], 'imagePrompt' => ''],
        ];

        $this->imageSearchService->method('extractKeywords')->willReturn(['services']);
        $this->imageSearchService->method('searchByKeywords')->with(['services'], 6)->willReturn([
            ['uid' => 5, 'name' => 'svc.jpg', 'title' => 'Services', 'alternative' => '', 'publicUrl' => '/svc.jpg'],
        ]);

        $result = $this->subject->resolveImagesForSections($template, $sections);

        self::assertCount(1, $result[0]);
        self::assertSame(5, $result[0][0]['uid']);
        self::assertTrue($result[0][0]['recommended']);
    }

    #[Test]
    public function isAiGenerationAvailableReturnsFalseByDefault(): void
    {
        self::assertFalse($this->subject->isAiGenerationAvailable());
    }

    #[Test]
    public function isAiGenerationAvailableReturnsTrueAfterRegister(): void
    {
        $this->registerFakeGenerator();
        self::assertTrue($this->subject->isAiGenerationAvailable());
    }

    #[Test]
    public function registerGeneratorSkipsUnavailableService(): void
    {
        $this->subject->registerGenerator(
            'unavailable',
            fn(string $prompt, array $options) => throw new RuntimeException('should not be called'),
            fn() => false,
        );
        self::assertFalse($this->subject->isAiGenerationAvailable());
    }

    #[Test]
    public function generateImageForSectionReturnsNullWhenNoGenerators(): void
    {
        $template = $this->createTemplate(5);
        $result = $this->subject->generateImageForSection($template, 'A hero', 'Welcome');
        self::assertNull($result);
    }

    #[Test]
    public function generateImageForSectionReturnsNullWhenNoDefaultStorage(): void
    {
        $template = $this->createTemplate(5);
        $this->registerFakeGenerator();
        $this->mockTaskPromptTemplate(5, '');
        $this->storageRepository->method('getDefaultStorage')->willReturn(null);

        $result = $this->subject->generateImageForSection($template, 'A hero image', 'Welcome');

        self::assertNull($result);
    }

    #[Test]
    public function generateImageForSectionReturnsNullWhenGeneratorThrows(): void
    {
        $template = $this->createTemplate(5);
        $this->subject->registerGenerator(
            'broken',
            fn(string $prompt, array $options) => throw new RuntimeException('API error'),
            fn() => true,
        );
        $this->mockTaskPromptTemplate(5, '');

        $result = $this->subject->generateImageForSection($template, 'A hero image', 'Welcome');

        self::assertNull($result);
    }

    #[Test]
    public function generateImageForSectionLogsARefusalWithoutThePassage(): void
    {
        $template = $this->createTemplate(5);
        $this->subject->registerGenerator(
            'guarded',
            fn(string $prompt, array $options) => throw new StorableMessageException('Rule 1 is broken by "the passage".', 'Rule 1 is broken.'),
            fn() => true,
        );
        $this->mockTaskPromptTemplate(5, '');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Image generation failed with guarded',
            self::callback(static fn(array $context): bool => $context['error'] === 'Rule 1 is broken.'),
        );
        $this->subject->setLogger($logger);

        self::assertNull($this->subject->generateImageForSection($template, 'A hero image', 'Welcome'));
    }

    #[Test]
    public function generateImageForSectionUsesTaskPromptTemplate(): void
    {
        $template = $this->createTemplate(5);
        $this->mockTaskPromptTemplate(5, 'Corporate photo: {{input}}. Clean style.');

        $capturedPrompt = '';
        $this->subject->registerGenerator(
            'capture',
            function (string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;
                return new ImageGenerationResult(
                    url: 'https://example.com/gen.png',
                    base64: base64_encode('fakepng'),
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: 'dall-e-3',
                    size: '1024x1024',
                    provider: 'dall-e',
                );
            },
            fn() => true,
        );

        $this->mockStorageForGeneration();

        // Empty imagePrompt → should use task's prompt template with {section} replaced
        $this->subject->generateImageForSection($template, '', 'Our Team');

        self::assertSame('Corporate photo: Our Team. Clean style.', $capturedPrompt);
    }

    #[Test]
    public function generateImageForSectionPrefersSectionPromptOverTaskTemplate(): void
    {
        $template = $this->createTemplate(5);

        $capturedPrompt = '';
        $this->subject->registerGenerator(
            'capture',
            function (string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;
                return new ImageGenerationResult(
                    url: 'https://example.com/gen.png',
                    base64: base64_encode('fakepng'),
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: 'dall-e-3',
                    size: '1024x1024',
                    provider: 'dall-e',
                );
            },
            fn() => true,
        );

        $this->mockStorageForGeneration();

        $this->subject->generateImageForSection($template, 'Aerial view of modern office building', 'Our Office');

        self::assertSame('Aerial view of modern office building', $capturedPrompt);
    }

    #[Test]
    public function generateImageForSectionUsesDefaultPromptWhenNoTask(): void
    {
        $template = $this->createTemplate(0);
        $this->mockTaskPromptTemplate(0, '');

        $capturedPrompt = '';
        $this->subject->registerGenerator(
            'capture',
            function (string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;
                return new ImageGenerationResult(
                    url: 'https://example.com/gen.png',
                    base64: base64_encode('fakepng'),
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: 'dall-e-3',
                    size: '1024x1024',
                    provider: 'dall-e',
                );
            },
            fn() => true,
        );

        $this->mockStorageForGeneration();

        $this->subject->generateImageForSection($template, '', 'About Us');

        self::assertStringContainsString('About Us', $capturedPrompt);
        self::assertStringContainsString('Professional high-resolution photograph', $capturedPrompt);
    }

    #[Test]
    public function loadTaskPromptTemplateCachesResultAcrossCalls(): void
    {
        $template = $this->createTemplate(5);
        $this->mockTaskPromptTemplate(5, 'Cached prompt: {{input}}.');

        $capturedPrompts = [];
        $this->subject->registerGenerator(
            'capture',
            function (string $prompt) use (&$capturedPrompts) {
                $capturedPrompts[] = $prompt;
                return new ImageGenerationResult(
                    url: 'https://example.com/gen.png',
                    base64: base64_encode('fakepng'),
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: 'dall-e-3',
                    size: '1024x1024',
                    provider: 'dall-e',
                );
            },
            fn() => true,
        );

        $this->mockStorageForGeneration();

        // Call twice with different headers — DB should only be queried once (mock would fail on second call otherwise)
        $this->subject->generateImageForSection($template, '', 'First');
        $this->subject->generateImageForSection($template, '', 'Second');

        self::assertCount(2, $capturedPrompts);
        self::assertSame('Cached prompt: First.', $capturedPrompts[0]);
        self::assertSame('Cached prompt: Second.', $capturedPrompts[1]);
    }

    #[Test]
    public function loadTaskPromptTemplateHandlesSpacesInPlaceholder(): void
    {
        $template = $this->createTemplate(5);
        // Task uses {{ input }} with spaces — should still be replaced
        $this->mockTaskPromptTemplate(5, 'Photo: {{ input }}.');

        $capturedPrompt = '';
        $this->subject->registerGenerator(
            'capture',
            function (string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;
                return new ImageGenerationResult(
                    url: 'https://example.com/gen.png',
                    base64: base64_encode('fakepng'),
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: 'dall-e-3',
                    size: '1024x1024',
                    provider: 'dall-e',
                );
            },
            fn() => true,
        );

        $this->mockStorageForGeneration();

        $this->subject->generateImageForSection($template, '', 'Team');

        self::assertSame('Photo: Team.', $capturedPrompt);
    }

    #[Test]
    public function generatesAtTheSizeOfTheTemplate(): void
    {
        $sizes = [];
        $this->subject->registerGenerator(
            'fake',
            function (string $prompt, array $options) use (&$sizes) {
                $sizes[] = [$options['size'] ?? null, $options['width'] ?? null, $options['height'] ?? null];

                return new ImageGenerationResult(url: '', base64: base64_encode(self::minimalPng()), prompt: $prompt, revisedPrompt: null, model: 'm', size: '1536x1024', provider: 'dall-e');
            },
            fn() => true,
        );
        $this->mockStorageForGeneration();

        $this->subject->generateImageForSection($this->createTemplate(5), 'A hero image', 'Welcome');
        $this->subject->generateImageForSection(new Template(uid: 1, title: 'T', identifier: 't', imageTask: 5, imageSize: '1536x1024'), 'A hero image', 'Welcome');
        // A value that is no size is the default.
        $this->subject->generateImageForSection(new Template(uid: 1, title: 'T', identifier: 't', imageTask: 5, imageSize: 'huge'), 'A hero image', 'Welcome');

        self::assertSame([['1024x1024', 1024, 1024], ['1536x1024', 1536, 1024], ['1024x1024', 1024, 1024]], $sizes);
    }

    #[Test]
    public function storesInGeneratedLandingpageOfTheDefaultStorageWhenTheTemplateNamesNoFolder(): void
    {
        $this->registerFakeGenerator();
        $storage = $this->storageWithFile();
        $storage->expects(self::once())->method('hasFolder')->with('generated-landingpage/')->willReturn(false);
        $storage->expects(self::once())->method('createFolder')->with('generated-landingpage/');
        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);
        $this->resourceFactory->expects(self::never())->method('getFolderObjectFromCombinedIdentifier');

        $image = $this->subject->generateImageForSection($this->createTemplate(5), 'A hero image', 'Welcome');

        self::assertSame(99, $image['uid'] ?? null);
    }

    #[Test]
    public function storesInTheFolderOfTheTemplateAndNotInTheDefaultStorage(): void
    {
        $this->registerFakeGenerator();
        $storage = $this->storageWithFile();
        $folder = $this->createMock(Folder::class);
        $folder->method('getStorage')->willReturn($storage);
        $storage->expects(self::once())->method('addFile')->with(self::anything(), $folder, self::anything())->willReturn($this->fileMock());
        $storage->expects(self::never())->method('createFolder');
        $this->resourceFactory->expects(self::once())->method('getFolderObjectFromCombinedIdentifier')->with('2:/campaigns/images/')->willReturn($folder);
        $this->storageRepository->expects(self::never())->method('getDefaultStorage');

        $image = $this->subject->generateImageForSection(
            new Template(uid: 1, title: 'T', identifier: 't', imageTask: 5, imageFolder: '2:/campaigns/images/'),
            'A hero image',
            'Welcome',
        );

        self::assertSame(99, $image['uid'] ?? null);
    }

    #[Test]
    public function storesNoImageWhenTheFolderOfTheTemplateCannotBeUsedAndLogsWhy(): void
    {
        $this->registerFakeGenerator();
        $this->resourceFactory->method('getFolderObjectFromCombinedIdentifier')->willThrowException(new RuntimeException('Folder does not exist'));
        $this->storageRepository->expects(self::never())->method('getDefaultStorage');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'The image folder of the template cannot be used',
            self::callback(static fn(array $context): bool => $context['folder'] === '2:/gone/' && $context['template'] === 't' && $context['error'] === 'Folder does not exist'),
        );
        $this->subject->setLogger($logger);

        $image = $this->subject->generateImageForSection(
            new Template(uid: 1, title: 'T', identifier: 't', imageTask: 5, imageFolder: '2:/gone/'),
            'A hero image',
            'Welcome',
        );

        self::assertNull($image);
    }

    private function fileMock(): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(99);
        $file->method('getName')->willReturn('generated.png');
        $file->method('getPublicUrl')->willReturn('/fileadmin/generated.png');
        $file->method('getMetaData')->willReturn($this->createMock(MetaDataAspect::class));

        return $file;
    }

    private function storageWithFile(): ResourceStorage&MockObject
    {
        $storage = $this->createMock(ResourceStorage::class);
        $folder = $this->createMock(Folder::class);
        $folder->method('getStorage')->willReturn($storage);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('addFile')->willReturn($this->fileMock());

        return $storage;
    }

    private function mockStorageForGeneration(): void
    {
        $storage = $this->storageWithFile();
        $storage->method('hasFolder')->willReturn(true);
        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);
    }

    private function mockTaskPromptTemplate(int $taskUid, string $promptTemplate): void
    {
        if ($taskUid <= 0) {
            return;
        }

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn(
            $promptTemplate !== '' ? ['prompt_template' => $promptTemplate] : false,
        );

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturn($queryBuilder);
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn((string) $taskUid);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->with('tx_nrllm_task')
            ->willReturn($queryBuilder);
    }
}
