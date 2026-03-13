<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\PromptOptimizerService;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(PromptOptimizerService::class)]
final class PromptOptimizerServiceTest extends UnitTestCase
{
    private function createTemplate(
        string $title = 'Product LP',
        array $allowedCTypes = ['text', 'textmedia'],
        array $pageFields = ['seo_title', 'description'],
        string $backendLayout = 'pagets__default',
        string $briefingMode = 'optional',
        string $systemPrompt = 'Write product descriptions',
        int $llmConfiguration = 0,
        string $promptOptimizerContext = '',
        string $promptOptimizerMetaPrompt = '',
        array $referencePages = [],
    ): Template {
        return new Template(
            uid: 1,
            title: $title,
            identifier: 'product-lp',
            systemPrompt: $systemPrompt,
            allowedCTypes: $allowedCTypes,
            pageFields: $pageFields,
            referencePages: $referencePages,
            briefingMode: $briefingMode,
            backendLayout: $backendLayout,
            llmConfiguration: $llmConfiguration,
            promptOptimizerContext: $promptOptimizerContext,
            promptOptimizerMetaPrompt: $promptOptimizerMetaPrompt,
        );
    }

    private function createCompletionResponse(string $content): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(100, 200, 300),
        );
    }

    private function createBackendLayoutService(): BackendLayoutService&MockObject
    {
        $service = $this->createMock(BackendLayoutService::class);
        $service->method('getColumnMap')->willReturn([0 => 'Main']);

        return $service;
    }

    private function createService(
        ?CompletionService $completionService = null,
        ?LlmServiceManagerInterface $llmManager = null,
        ?LlmConfigurationRepository $configRepo = null,
        ?BackendLayoutService $backendLayoutService = null,
    ): PromptOptimizerService {
        return new PromptOptimizerService(
            $completionService ?? $this->createMock(CompletionService::class),
            $llmManager ?? $this->createMock(LlmServiceManagerInterface::class),
            $configRepo ?? $this->createMock(LlmConfigurationRepository::class),
            $backendLayoutService ?? $this->createBackendLayoutService(),
        );
    }

    #[Test]
    public function buildStructuralContextIncludesTemplateTitle(): void
    {
        $template = $this->createTemplate(title: 'My Landing Page');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Template: My Landing Page', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesAllowedCTypes(): void
    {
        $template = $this->createTemplate(allowedCTypes: ['text', 'textmedia', 'header']);
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Allowed Content Types: text, textmedia, header', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesPageFields(): void
    {
        $template = $this->createTemplate(pageFields: ['seo_title', 'og_title']);
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Page Fields to fill: seo_title, og_title', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesBackendLayout(): void
    {
        $template = $this->createTemplate(backendLayout: 'pagets__two_column');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Backend Layout: pagets__two_column', $context);
    }

    #[Test]
    public function buildStructuralContextOmitsBackendLayoutWhenEmpty(): void
    {
        $template = $this->createTemplate(backendLayout: '');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringNotContainsString('Backend Layout', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesBriefingMode(): void
    {
        $template = $this->createTemplate(briefingMode: 'required');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Briefing Mode: required', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesReferencePages(): void
    {
        $template = $this->createTemplate(referencePages: [10, 20]);
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Reference Pages: 10, 20', $context);
    }

    #[Test]
    public function buildStructuralContextOmitsReferencePagesWhenEmpty(): void
    {
        $template = $this->createTemplate(referencePages: []);
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringNotContainsString('Reference Pages', $context);
    }

    #[Test]
    public function buildStructuralContextIncludesCurrentSystemPrompt(): void
    {
        $template = $this->createTemplate(systemPrompt: 'Write formal content');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringContainsString('Current System Prompt (BASELINE', $context);
        self::assertStringContainsString('Write formal content', $context);
    }

    #[Test]
    public function buildStructuralContextOmitsSystemPromptWhenEmpty(): void
    {
        $template = $this->createTemplate(systemPrompt: '');
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringNotContainsString('Current System Prompt', $context);
    }

    #[Test]
    public function buildStructuralContextOmitsEmptyCTypes(): void
    {
        $template = $this->createTemplate(allowedCTypes: []);
        $service = $this->createService();

        $context = $service->buildStructuralContext($template);

        self::assertStringNotContainsString('Allowed Content Types', $context);
    }

    #[Test]
    public function generateOptimizedPromptUsesDefaultCompletionService(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('complete')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'Template: Product LP')
                    && str_contains($p, 'expert prompt engineer'),
            ))
            ->willReturn($this->createCompletionResponse('Optimized prompt result'));

        $service = $this->createService(completionService: $completionService);

        $result = $service->generateOptimizedPrompt($this->createTemplate());

        self::assertSame('Optimized prompt result', $result);
    }

    #[Test]
    public function generateOptimizedPromptIncludesStyleHintsWithDefaultMetaPrompt(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('complete')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'Generate a German prompt')
                    && str_contains($p, 'expert prompt engineer')
                    && str_contains($p, 'EDITOR STYLE HINTS'),
            ))
            ->willReturn($this->createCompletionResponse('Custom result'));

        $service = $this->createService(completionService: $completionService);

        $template = $this->createTemplate(promptOptimizerMetaPrompt: 'Generate a German prompt');

        $result = $service->generateOptimizedPrompt($template);

        self::assertSame('Custom result', $result);
    }

    #[Test]
    public function generateOptimizedPromptIncludesAdditionalContext(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('complete')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'ADDITIONAL CONTEXT')
                    && str_contains($p, 'Brand: Acme Corp'),
            ))
            ->willReturn($this->createCompletionResponse('Result with context'));

        $service = $this->createService(completionService: $completionService);

        $template = $this->createTemplate(promptOptimizerContext: 'Brand: Acme Corp');

        $result = $service->generateOptimizedPrompt($template);

        self::assertSame('Result with context', $result);
    }

    #[Test]
    public function generateOptimizedPromptOmitsAdditionalContextWhenEmpty(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('complete')
            ->with(self::callback(
                fn(string $p): bool => !str_contains($p, 'ADDITIONAL CONTEXT'),
            ))
            ->willReturn($this->createCompletionResponse('Result'));

        $service = $this->createService(completionService: $completionService);

        $result = $service->generateOptimizedPrompt($this->createTemplate(promptOptimizerContext: ''));

        self::assertSame('Result', $result);
    }

    #[Test]
    public function generateOptimizedPromptUsesLlmConfigurationWhenSet(): void
    {
        $llmConfig = $this->createMock(LlmConfiguration::class);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findByUid')->with(5)->willReturn($llmConfig);

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())
            ->method('chatWithConfiguration')
            ->with(
                self::callback(fn(array $messages): bool => count($messages) === 1
                    && $messages[0]['role'] === 'user'),
                $llmConfig,
            )
            ->willReturn($this->createCompletionResponse('  LLM config result  '));

        $service = $this->createService(llmManager: $llmManager, configRepo: $configRepo);

        $result = $service->generateOptimizedPrompt($this->createTemplate(llmConfiguration: 5));

        self::assertSame('LLM config result', $result);
    }

    #[Test]
    public function generateOptimizedPromptFallsBackWhenLlmConfigNotFound(): void
    {
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findByUid')->with(99)->willReturn(null);

        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('complete')
            ->willReturn($this->createCompletionResponse('Fallback result'));

        $service = $this->createService(completionService: $completionService, configRepo: $configRepo);

        $result = $service->generateOptimizedPrompt($this->createTemplate(llmConfiguration: 99));

        self::assertSame('Fallback result', $result);
    }

    #[Test]
    public function generateOptimizedPromptLogsAndRethrowsOnException(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('complete')
            ->willThrowException(new RuntimeException('LLM failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Prompt optimization failed', self::callback(
                fn(array $context): bool => $context['template'] === 'product-lp'
                    && $context['error'] === 'LLM failed',
            ));

        $service = $this->createService(completionService: $completionService);
        $service->setLogger($logger);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM failed');

        $service->generateOptimizedPrompt($this->createTemplate());
    }

    #[Test]
    public function generateOptimizedPromptTrimsWhitespace(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('complete')
            ->willReturn($this->createCompletionResponse("  \n  Trimmed result  \n  "));

        $service = $this->createService(completionService: $completionService);

        $result = $service->generateOptimizedPrompt($this->createTemplate());

        self::assertSame('Trimmed result', $result);
    }
}
