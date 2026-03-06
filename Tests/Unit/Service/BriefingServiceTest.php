<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(BriefingService::class)]
final class BriefingServiceTest extends UnitTestCase
{
    private function createTemplate(string $systemPrompt = 'Test prompt'): Template
    {
        return new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: $systemPrompt);
    }

    #[Test]
    public function generateQuestionsReturnsParsedFormFields(): void
    {
        $llmResponse = [
            ['id' => 'audience', 'label' => 'Zielgruppe', 'type' => 'text', 'required' => true, 'placeholder' => 'B2B'],
            ['id' => 'date', 'label' => 'Datum', 'type' => 'text', 'required' => false, 'placeholder' => ''],
        ];
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new BriefingService($completionService);
        $questions = $service->generateQuestions($this->createTemplate());

        self::assertCount(2, $questions);
        self::assertSame('audience', $questions[0]['id']);
        self::assertSame('Zielgruppe', $questions[0]['label']);
        self::assertTrue($questions[0]['required']);
        self::assertSame('date', $questions[1]['id']);
        self::assertFalse($questions[1]['required']);
    }

    #[Test]
    public function promptContainsJsonFormatInstruction(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(fn(string $p): bool => str_contains($p, 'JSON-Array') && str_contains($p, '"id"') && str_contains($p, '"type"')
            ))
            ->willReturn([]);

        (new BriefingService($completionService))->generateQuestions($this->createTemplate());
    }

    #[Test]
    public function promptContainsTemplateSystemPrompt(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(fn(string $p): bool => str_contains($p, 'My custom prompt for events')
            ))
            ->willReturn([]);

        (new BriefingService($completionService))->generateQuestions(
            $this->createTemplate('My custom prompt for events')
        );
    }

    #[Test]
    public function returnsEmptyArrayOnLlmException(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new \RuntimeException('LLM failed'));

        $service = new BriefingService($completionService);
        self::assertSame([], $service->generateQuestions($this->createTemplate()));
    }

    #[Test]
    public function validatesAndCapsQuestionsAtMaximum(): void
    {
        $questions = array_map(
            fn(int $i) => ['id' => "q$i", 'label' => "Q$i", 'type' => 'text', 'required' => false, 'placeholder' => ''],
            range(1, 15),
        );
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($questions);

        $result = (new BriefingService($completionService))->generateQuestions($this->createTemplate());
        self::assertCount(8, $result);
    }

    #[Test]
    public function skipsInvalidQuestionsInResponse(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'valid', 'label' => 'Valid', 'type' => 'text'],
            ['broken' => 'data'],
            'not-an-array',
        ]);

        $result = (new BriefingService($completionService))->generateQuestions($this->createTemplate());
        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]['id']);
    }

    #[Test]
    public function normalizesInvalidTypeToText(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'q1', 'label' => 'Q1', 'type' => 'invalid_type'],
        ]);

        $result = (new BriefingService($completionService))->generateQuestions($this->createTemplate());
        self::assertSame('text', $result[0]['type']);
    }

    #[Test]
    public function parsesSelectOptionsCorrectly(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'style', 'label' => 'Stil', 'type' => 'select', 'options' => ['formal', 'casual']],
        ]);

        $result = (new BriefingService($completionService))->generateQuestions($this->createTemplate());
        self::assertSame('select', $result[0]['type']);
        self::assertSame(['formal', 'casual'], $result[0]['options']);
    }
}
