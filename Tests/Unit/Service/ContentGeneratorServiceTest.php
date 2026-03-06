<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ContentGeneratorService::class)]
final class ContentGeneratorServiceTest extends UnitTestCase
{
    private function createTemplate(
        string $systemPrompt = 'Test prompt',
        array $allowedCTypes = ['text', 'textmedia'],
        array $pageFields = ['title', 'seo_title', 'description'],
    ): Template {
        return new Template(
            uid: 1,
            title: 'T',
            identifier: 't',
            systemPrompt: $systemPrompt,
            allowedCTypes: $allowedCTypes,
            pageFields: $pageFields,
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
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
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, '- audience: developers')
                    && str_contains($p, '- tone: formal')
                    && str_contains($p, 'text, textmedia'),
            ))
            ->willReturn([]);

        $service = new ContentGeneratorService($completionService);
        $service->generateContent(
            $this->createTemplate(),
            ['audience' => 'developers', 'tone' => 'formal'],
        );
    }

    #[Test]
    public function promptContainsSystemPrompt(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'My landing page system prompt'),
            ))
            ->willReturn([]);

        $service = new ContentGeneratorService($completionService);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        // TYPO3 HtmlSanitizer encodes disallowed tags rather than stripping
        self::assertSame('<p><strong>bold</strong>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $result[0]['bodytext']);
    }

    #[Test]
    public function generateContentFiltersInvalidCTypes(): void
    {
        $llmResponse = [
            ['section' => 'Valid', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'Invalid', 'ctype' => 'html', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ];

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Valid', $result[0]['section']);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Valid', $result[0]['section']);
    }

    #[Test]
    public function generateContentReturnsEmptyOnException(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM failed'));

        $service = new ContentGeneratorService($completionService);
        self::assertSame([], $service->generateContent($this->createTemplate(), []));
    }

    #[Test]
    public function generateContentLogsOnError(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Content generation failed', self::callback(
                fn(array $context): bool => $context['template'] === 't'
                    && $context['error'] === 'LLM exploded',
            ));

        $service = new ContentGeneratorService($completionService);
        $service->setLogger($logger);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generatePageFields($this->createTemplate(), []);

        self::assertCount(3, $result);
        self::assertArrayNotHasKey('unknown_field', $result);
        self::assertArrayNotHasKey('another_extra', $result);
    }

    #[Test]
    public function generatePageFieldsReturnsEmptyOnException(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM failed'));

        $service = new ContentGeneratorService($completionService);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generateContent($this->createTemplate(), []);

        self::assertStringNotContainsString('javascript:', $result[0]['bodytext']);
        // HtmlSanitizer removes invalid href entirely rather than replacing with #
        self::assertSame('<a>link</a>', $result[0]['bodytext']);
    }

    #[Test]
    public function generatePageFieldsLogsOnError(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Page field generation failed', self::callback(
                fn(array $context): bool => $context['template'] === 't'
                    && $context['error'] === 'LLM exploded',
            ));

        $service = new ContentGeneratorService($completionService);
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

        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new ContentGeneratorService($completionService);
        $result = $service->generatePageFields($this->createTemplate(), []);

        self::assertCount(1, $result);
        self::assertSame('Valid Title', $result['title']);
        self::assertArrayNotHasKey('seo_title', $result);
        self::assertArrayNotHasKey('description', $result);
    }

    #[Test]
    public function formatsBriefingAsKeyValueLines(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, '- company: Acme Corp')
                    && str_contains($p, '- product: Widget'),
            ))
            ->willReturn([]);

        $service = new ContentGeneratorService($completionService);
        $service->generateContent(
            $this->createTemplate(),
            ['company' => 'Acme Corp', 'product' => 'Widget'],
        );
    }
}
