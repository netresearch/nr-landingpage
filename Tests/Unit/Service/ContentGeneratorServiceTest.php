<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ContentGeneratorService::class)]
final class ContentGeneratorServiceTest extends UnitTestCase
{
    private function createService(CompletionServiceInterface $completionService): ContentGeneratorService
    {
        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main']);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('');

        return new ContentGeneratorService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $cTypeMetadataService,
            $backendLayoutService,
            new CreativeHtmlSanitizer(),
        );
    }

    private function createTemplate(
        string $systemPrompt = 'Test prompt',
        array $allowedCTypes = ['text', 'textmedia'],
        array $pageFields = ['title', 'seo_title', 'description'],
        array $contentColumns = [],
        string $generationMode = 'structured',
    ): Template {
        return new Template(
            uid: 1,
            title: 'T',
            identifier: 't',
            systemPrompt: $systemPrompt,
            allowedCTypes: $allowedCTypes,
            pageFields: $pageFields,
            contentColumns: $contentColumns,
            generationMode: $generationMode,
        );
    }

    private function createServiceWithColumnMap(CompletionServiceInterface $completionService, array $columnMap): ContentGeneratorService
    {
        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn($columnMap);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('');

        return new ContentGeneratorService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $cTypeMetadataService,
            $backendLayoutService,
            new CreativeHtmlSanitizer(),
        );
    }

    #[Test]
    public function generateContentReturnsValidatedSections(): void
    {
        $llmResponse = [
            [
                'section' => 'Hero',
                'ctype' => 'text',
                'header' => 'Welcome',
                'subheader' => 'Subtitle',
                'bodytext' => '<p>Hello world</p>',
            ],
            [
                'section' => 'Features',
                'ctype' => 'textmedia',
                'header' => 'Our Features',
                'subheader' => '',
                'bodytext' => '<ul><li>Fast</li></ul>',
            ],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), ['topic' => 'Testing']);

        self::assertCount(2, $result);
        self::assertSame('Hero', $result[0]['section']);
        self::assertSame('text', $result[0]['ctype']);
        self::assertSame('Welcome', $result[0]['header']);
        self::assertSame('Subtitle', $result[0]['subheader']);
        self::assertSame('<p>Hello world</p>', $result[0]['bodytext']);
        self::assertSame('Features', $result[1]['section']);
    }

    #[Test]
    public function promptContainsBriefingAndCTypes(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, '- audience: developers')
                    && str_contains($p, '- tone: formal')
                    && str_contains($p, 'text, textmedia'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generateContent(
            $this->createTemplate(),
            ['audience' => 'developers', 'tone' => 'formal'],
        );
    }

    #[Test]
    public function promptContainsSystemPrompt(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'My landing page system prompt'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generateContent(
            $this->createTemplate('My landing page system prompt'),
            ['topic' => 'Test'],
        );
    }

    #[Test]
    public function generateContentSanitizesHtml(): void
    {
        $llmResponse = [
            [
                'section' => 'Main',
                'ctype' => 'text',
                'header' => 'Title',
                'subheader' => '',
                'bodytext' => '<p><strong>bold</strong><script>alert(1)</script></p>',
            ],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        // TYPO3 HtmlSanitizer encodes disallowed tags rather than stripping
        self::assertSame('<p><strong>bold</strong>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $result[0]['bodytext']);
    }

    #[Test]
    public function generateContentFallsBackInvalidCTypeToText(): void
    {
        $llmResponse = [
            ['section' => 'Valid', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'Invalid', 'ctype' => 'html', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(2, $result);
        self::assertSame('text', $result[0]['ctype']);
        self::assertSame('text', $result[1]['ctype']);
    }

    #[Test]
    public function generateContentSkipsSectionsWithoutRequiredFields(): void
    {
        $llmResponse = [
            ['section' => 'Valid', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['ctype' => 'text', 'header' => 'H'],
            ['section' => 'NoType', 'header' => 'H'],
            'not-an-array',
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Valid', $result[0]['section']);
    }

    #[Test]
    public function generateContentThrowsOnLlmException(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM failed'));

        $service = $this->createService($completionService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM failed');
        $service->generateContent($this->createTemplate(), []);
    }

    #[Test]
    public function generatePageFieldsReturnsValidatedFields(): void
    {
        $llmResponse = [
            'title' => 'My Page Title',
            'seo_title' => 'SEO Title',
            'description' => 'A short description',
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generatePageFields($this->createTemplate(), ['topic' => 'Test']);

        self::assertSame('My Page Title', $result['title']);
        self::assertSame('SEO Title', $result['seo_title']);
        self::assertSame('A short description', $result['description']);
    }

    #[Test]
    public function generatePageFieldsIgnoresUnknownFields(): void
    {
        $llmResponse = [
            'title' => 'My Page',
            'seo_title' => 'SEO',
            'description' => 'Desc',
            'unknown_field' => 'Should be ignored',
            'another_extra' => 'Also ignored',
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generatePageFields($this->createTemplate(), []);

        self::assertCount(3, $result);
        self::assertArrayNotHasKey('unknown_field', $result);
        self::assertArrayNotHasKey('another_extra', $result);
    }

    #[Test]
    public function generatePageFieldsAcceptsAllFieldsWhenAllowedFieldsEmpty(): void
    {
        $llmResponse = [
            'title' => 'My Title',
            'seo_title' => 'SEO Title',
            'description' => 'A description',
            'custom_field' => 'Custom Value',
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generatePageFields($this->createTemplate(pageFields: []), []);

        self::assertCount(4, $result);
        self::assertSame('My Title', $result['title']);
        self::assertSame('Custom Value', $result['custom_field']);
    }

    #[Test]
    public function pageFieldsPromptUsesDefaultFieldsWhenEmpty(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'title, seo_title, description, og_title, og_description'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generatePageFields($this->createTemplate(pageFields: []), ['topic' => 'Test']);
    }

    #[Test]
    public function generatePageFieldsReturnsEmptyOnException(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM failed'));

        $service = $this->createService($completionService);
        self::assertSame([], $service->generatePageFields($this->createTemplate(), []));
    }

    #[Test]
    public function sanitizesEventHandlerAttributes(): void
    {
        $llmResponse = [
            [
                'section' => 'Main',
                'ctype' => 'text',
                'header' => 'Title',
                'subheader' => '',
                'bodytext' => '<p onclick="alert(1)">text</p>',
            ],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertStringNotContainsString('onclick', $result[0]['bodytext']);
        self::assertSame('<p>text</p>', $result[0]['bodytext']);
    }

    #[Test]
    public function sanitizesJavascriptUrls(): void
    {
        $llmResponse = [
            [
                'section' => 'Main',
                'ctype' => 'text',
                'header' => 'Title',
                'subheader' => '',
                'bodytext' => '<a href="javascript:alert(1)">link</a>',
            ],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertStringNotContainsString('javascript:', $result[0]['bodytext']);
        // HtmlSanitizer removes invalid href entirely rather than replacing with #
        self::assertSame('<a>link</a>', $result[0]['bodytext']);
    }

    #[Test]
    public function generatePageFieldsLogsOnError(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Page field generation failed', self::callback(
                fn(array $context): bool => $context['template'] === 't'
                    && $context['error'] === 'LLM exploded',
            ));

        $service = $this->createService($completionService);
        $service->setLogger($logger);
        $service->generatePageFields($this->createTemplate(), []);
    }

    #[Test]
    public function generatePageFieldsHandlesNonStringValues(): void
    {
        $llmResponse = [
            'title' => 'Valid Title',
            'seo_title' => 123,
            'description' => ['not', 'a', 'string'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generatePageFields($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Valid Title', $result['title']);
        self::assertArrayNotHasKey('seo_title', $result);
        self::assertArrayNotHasKey('description', $result);
    }

    #[Test]
    public function validateSectionsSkipsNonStringSectionOrCtype(): void
    {
        $llmResponse = [
            ['section' => 123, 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'Valid', 'ctype' => ['array'], 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'Good', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>ok</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Good', $result[0]['section']);
    }

    #[Test]
    public function validateSectionsHandlesNonStringHeaderAndSubheader(): void
    {
        $llmResponse = [
            ['section' => 'S', 'ctype' => 'text', 'header' => 123, 'subheader' => ['array'], 'bodytext' => null],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('', $result[0]['header']);
        self::assertSame('', $result[0]['subheader']);
        self::assertSame('', $result[0]['bodytext']);
    }

    #[Test]
    public function pageFieldsPromptContainsFieldNames(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'title, seo_title, description')
                    && str_contains($p, 'seo_title')
                    && str_contains($p, 'max. 60 Zeichen'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generatePageFields($this->createTemplate(), ['topic' => 'Test']);
    }

    #[Test]
    public function generateContentAllowsAnyCTypeWhenAllowedCTypesEmpty(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
            ['section' => 'Media', 'ctype' => 'textmedia', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>b</p>'],
            ['section' => 'Custom', 'ctype' => 'html', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>c</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(allowedCTypes: []), []);

        self::assertCount(3, $result);
        self::assertSame('text', $result[0]['ctype']);
        self::assertSame('textmedia', $result[1]['ctype']);
        self::assertSame('html', $result[2]['ctype']);
    }

    #[Test]
    public function generateContentFallsBackToTextForInvalidCType(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(allowedCTypes: ['text', 'textmedia']), []);

        self::assertCount(1, $result);
        self::assertSame('text', $result[0]['ctype']);
    }

    #[Test]
    public function promptUsesDefaultCTypesWhenAllowedCTypesEmpty(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'text, textmedia, textpic')
                    && str_contains($p, 'gaengige Content-Typen'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generateContent($this->createTemplate(allowedCTypes: []), ['topic' => 'Test']);
    }

    #[Test]
    public function formatsBriefingAsKeyValueLines(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, '- company: Acme Corp')
                    && str_contains($p, '- product: Widget'),
            ))
            ->willReturn([]);

        $service = $this->createService($completionService);
        $service->generateContent(
            $this->createTemplate(),
            ['company' => 'Acme Corp', 'product' => 'Widget'],
        );
    }

    #[Test]
    public function generateContentIncludesColPosInValidatedSections(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
            ['section' => 'Sidebar', 'ctype' => 'text', 'colPos' => 1, 'header' => 'S', 'subheader' => '', 'bodytext' => '<p>b</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main', 1 => 'Sidebar']);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('colPos 0 = "Main", colPos 1 = "Sidebar"');

        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        $service = new ContentGeneratorService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $cTypeMetadataService,
            $backendLayoutService,
            new CreativeHtmlSanitizer(),
        );

        $result = $service->generateContent($this->createTemplate(), []);

        self::assertSame(0, $result[0]['colPos']);
        self::assertSame(1, $result[1]['colPos']);
    }

    #[Test]
    public function generateContentFallsBackToFirstValidColPosForInvalidValue(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 99, 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main', 1 => 'Sidebar']);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('');

        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        $service = new ContentGeneratorService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $cTypeMetadataService,
            $backendLayoutService,
            new CreativeHtmlSanitizer(),
        );

        $result = $service->generateContent($this->createTemplate(), []);

        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function generateContentDefaultsColPosToZeroWhenMissing(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function generateContentHandlesNumericStringColPos(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => '1', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>a</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main', 1 => 'Sidebar']);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('');

        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        $service = new ContentGeneratorService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $cTypeMetadataService,
            $backendLayoutService,
            new CreativeHtmlSanitizer(),
        );

        $result = $service->generateContent($this->createTemplate(), []);

        self::assertSame(1, $result[0]['colPos']);
    }

    #[Test]
    public function filterColumnMapPassesThroughAllColumnsWhenContentColumnsEmpty(): void
    {
        // columnMap has 3 columns, contentColumns is [] => all 3 pass through
        $llmResponse = [
            ['section' => 'A', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'B', 'ctype' => 'text', 'colPos' => 1, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'C', 'ctype' => 'text', 'colPos' => 2, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createServiceWithColumnMap($completionService, [0 => 'Main', 1 => 'Sidebar', 2 => 'Footer']);

        $result = $service->generateContent(
            $this->createTemplate(contentColumns: []),
            [],
        );

        self::assertCount(3, $result);
        self::assertSame(0, $result[0]['colPos']);
        self::assertSame(1, $result[1]['colPos']);
        self::assertSame(2, $result[2]['colPos']);
    }

    #[Test]
    public function filterColumnMapRestrictsToContentColumnsInStructuredMode(): void
    {
        // columnMap has 3 columns, contentColumns = [0, 2] => colPos 1 is excluded,
        // so LLM response with colPos 1 should be remapped to first valid (0)
        $llmResponse = [
            ['section' => 'A', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'B', 'ctype' => 'text', 'colPos' => 1, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'C', 'ctype' => 'text', 'colPos' => 2, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createServiceWithColumnMap($completionService, [0 => 'Main', 1 => 'Sidebar', 2 => 'Footer']);

        $result = $service->generateContent(
            $this->createTemplate(contentColumns: [0, 2]),
            [],
        );

        self::assertCount(3, $result);
        self::assertSame(0, $result[0]['colPos']);
        // colPos 1 is not in contentColumns [0, 2], so falls back to first valid = 0
        self::assertSame(0, $result[1]['colPos']);
        self::assertSame(2, $result[2]['colPos']);
    }

    #[Test]
    public function filterColumnMapFallsBackToFullMapOnMisconfiguration(): void
    {
        // contentColumns references colPos values not present in the actual columnMap
        // => filter result would be empty => fall back to full map
        $llmResponse = [
            ['section' => 'A', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createServiceWithColumnMap($completionService, [0 => 'Main']);

        $result = $service->generateContent(
            // contentColumns [5, 6] don't exist in [0 => 'Main'] => fallback to full map
            $this->createTemplate(contentColumns: [5, 6]),
            [],
        );

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function filterColumnMapRestrictsColumnsInCreativeMode(): void
    {
        // Creative mode: columnMap [0, 1, 2], contentColumns = [0] => only colPos 0 passes through,
        // response with colPos 1 should be remapped to 0
        $llmResponse = [
            ['section' => 'Hero', 'colPos' => 0, 'header' => 'Title', 'bodytext' => '<p>content</p>'],
            ['section' => 'Sidebar', 'colPos' => 1, 'header' => 'Side', 'bodytext' => '<p>side</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createServiceWithColumnMap($completionService, [0 => 'Main', 1 => 'Sidebar']);

        $result = $service->generateContent(
            $this->createTemplate(contentColumns: [0], generationMode: 'creative'),
            [],
        );

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]['colPos']);
        // colPos 1 not in filtered map [0 => 'Main'], falls back to 0
        self::assertSame(0, $result[1]['colPos']);
    }

    #[Test]
    public function filterColumnMapPassesThroughAllColumnsInCreativeModeWhenContentColumnsEmpty(): void
    {
        $llmResponse = [
            ['section' => 'Hero', 'colPos' => 0, 'header' => 'Title', 'bodytext' => '<p>content</p>'],
            ['section' => 'Sidebar', 'colPos' => 1, 'header' => 'Side', 'bodytext' => '<p>side</p>'],
        ];

        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = $this->createServiceWithColumnMap($completionService, [0 => 'Main', 1 => 'Sidebar']);

        $result = $service->generateContent(
            $this->createTemplate(contentColumns: [], generationMode: 'creative'),
            [],
        );

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]['colPos']);
        self::assertSame(1, $result[1]['colPos']);
    }
}
