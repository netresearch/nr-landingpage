<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\LlmCompletionTrait;
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

#[CoversClass(LlmCompletionTrait::class)]
final class LlmCompletionTraitTest extends UnitTestCase
{
    private CompletionService&MockObject $completionService;
    private LlmServiceManagerInterface&MockObject $llmServiceManager;
    private LlmConfigurationRepository&MockObject $configurationRepository;
    private LoggerInterface&MockObject $logger;
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completionService = $this->createMock(CompletionService::class);
        $this->llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subject = new class (
            $this->completionService,
            $this->llmServiceManager,
            $this->configurationRepository,
            $this->logger,
        ) {
            use LlmCompletionTrait;

            public function __construct(
                private readonly CompletionService $completionService,
                private readonly LlmServiceManagerInterface $llmServiceManager,
                private readonly LlmConfigurationRepository $configurationRepository,
                private readonly ?LoggerInterface $logger,
            ) {}

            /** @return array<string, mixed> */
            public function callComplete(Template $template, string $prompt): array
            {
                return $this->completeJsonWithTemplate($template, $prompt);
            }
        };
    }

    private function createTemplate(int $llmConfiguration = 0): Template
    {
        return new Template(uid: 1, title: 'T', identifier: 't', llmConfiguration: $llmConfiguration);
    }

    #[Test]
    public function fallsBackToCompletionServiceWhenNoLlmConfiguration(): void
    {
        $template = $this->createTemplate(0);
        $this->completionService->method('completeJson')->willReturn(['key' => 'value']);

        $result = $this->subject->callComplete($template, 'test prompt');

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function fallsBackToCompletionServiceWhenConfigurationNotFound(): void
    {
        $template = $this->createTemplate(5);
        $this->configurationRepository->method('findByUid')->with(5)->willReturn(null);
        $this->completionService->method('completeJson')->willReturn(['fallback' => true]);

        $result = $this->subject->callComplete($template, 'test prompt');

        self::assertSame(['fallback' => true], $result);
    }

    #[Test]
    public function stripsMarkdownCodeFencesFromResponse(): void
    {
        $template = $this->createTemplate(5);
        $config = $this->createMock(LlmConfiguration::class);
        $this->configurationRepository->method('findByUid')->willReturn($config);

        $response = new CompletionResponse(
            content: "```json\n{\"sections\": [1, 2]}\n```",
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
        );
        $this->llmServiceManager->method('chatWithConfiguration')->willReturn($response);

        $result = $this->subject->callComplete($template, 'test');

        self::assertSame([1, 2], $result['sections']);
    }

    #[Test]
    public function throwsExceptionForInvalidJsonResponse(): void
    {
        $template = $this->createTemplate(5);
        $config = $this->createMock(LlmConfiguration::class);
        $this->configurationRepository->method('findByUid')->willReturn($config);

        $response = new CompletionResponse(
            content: 'This is not JSON at all',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
        );
        $this->llmServiceManager->method('chatWithConfiguration')->willReturn($response);

        $this->logger->expects(self::once())->method('warning');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/i');

        $this->subject->callComplete($template, 'test');
    }

    #[Test]
    public function parsesValidJsonResponseWithConfiguration(): void
    {
        $template = $this->createTemplate(5);
        $config = $this->createMock(LlmConfiguration::class);
        $this->configurationRepository->method('findByUid')->willReturn($config);

        $response = new CompletionResponse(
            content: '{"name": "test", "count": 3}',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
        );
        $this->llmServiceManager->method('chatWithConfiguration')->willReturn($response);

        $result = $this->subject->callComplete($template, 'test');

        self::assertSame('test', $result['name']);
        self::assertSame(3, $result['count']);
    }
}
