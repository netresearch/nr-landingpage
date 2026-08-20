<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\LlmCallerSource;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $service = new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        );
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
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'JSON-Objekt')
                    && str_contains($p, '"questions"')
                    && str_contains($p, '"id"')
                    && str_contains($p, '"type"'),
            ))
            ->willReturn([]);

        (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
    }

    /**
     * The heredoc used to carry {self::MAX_QUESTIONS}, which PHP does not
     * interpolate, so the model was told the literal text instead of a number.
     */
    #[Test]
    public function promptStatesTheQuestionLimitAsANumber(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'Maximal 5 Fragen')
                    && !str_contains($p, 'MAX_QUESTIONS'),
            ))
            ->willReturn([]);

        (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
    }

    /**
     * response_format json_object forbids a top-level array, so the fallback
     * path never receives the list the prompt asks for. Measured against the
     * live instance: a single question flattened to the top level, which the
     * old validator iterated over character by key and discarded entirely.
     */
    #[Test]
    public function generateQuestionsAcceptsASingleQuestionFlattenedToTheTopLevel(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            'id'          => 'zielgruppe_beduerfnisse',
            'label'       => 'Zielgruppe & Bedarf',
            'type'        => 'textarea',
            'required'    => true,
            'placeholder' => 'Wer soll die Seite lesen?',
        ]);

        $questions = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());

        self::assertCount(1, $questions);
        self::assertSame('zielgruppe_beduerfnisse', $questions[0]['id']);
        self::assertSame('textarea', $questions[0]['type']);
    }

    #[Test]
    public function generateQuestionsAcceptsAnEnvelope(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            'questions' => [
                ['id' => 'audience', 'label' => 'Zielgruppe', 'type' => 'text'],
                ['id' => 'usp', 'label' => 'USP', 'type' => 'textarea'],
            ],
        ]);

        $questions = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());

        self::assertCount(2, $questions);
        self::assertSame('audience', $questions[0]['id']);
        self::assertSame('usp', $questions[1]['id']);
    }

    #[Test]
    public function promptContainsTemplateSystemPrompt(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'My custom prompt for events'),
            ))
            ->willReturn([]);

        (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions(
            $this->createTemplate('My custom prompt for events'),
        );
    }

    #[Test]
    public function returnsEmptyArrayOnLlmException(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM failed'));

        $service = new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        );
        self::assertSame([], $service->generateQuestions($this->createTemplate()));
    }

    #[Test]
    public function validatesAndCapsQuestionsAtMaximum(): void
    {
        $questions = array_map(
            fn(int $i) => ['id' => "q$i", 'label' => "Q$i", 'type' => 'text', 'required' => false, 'placeholder' => ''],
            range(1, 15),
        );
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn($questions);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertCount(5, $result);
    }

    #[Test]
    public function skipsInvalidQuestionsInResponse(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'valid', 'label' => 'Valid', 'type' => 'text'],
            ['broken' => 'data'],
            'not-an-array',
        ]);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]['id']);
    }

    #[Test]
    public function normalizesInvalidTypeToText(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'q1', 'label' => 'Q1', 'type' => 'invalid_type'],
        ]);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertSame('text', $result[0]['type']);
    }

    #[Test]
    public function parsesSelectOptionsCorrectly(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'style', 'label' => 'Stil', 'type' => 'select', 'options' => ['formal', 'casual']],
        ]);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertSame('select', $result[0]['type']);
        self::assertSame(['formal', 'casual'], $result[0]['options']);
    }

    #[Test]
    public function returnsEmptyArrayWhenLlmReturnsUnexpectedStructure(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        // Return an array with no valid question items (all entries are scalar or missing required keys)
        $completionService->method('completeJson')->willReturn([
            'unexpected' => 'structure',
            42,
            null,
        ]);

        $service = new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        );
        self::assertSame([], $service->generateQuestions($this->createTemplate()));
    }

    #[Test]
    public function logsErrorOnException(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Briefing generation failed', self::callback(
                fn(array $context): bool => $context['template'] === 't'
                    && $context['error'] === 'LLM exploded',
            ));

        $service = new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        );
        $service->setLogger($logger);
        $service->generateQuestions($this->createTemplate());
    }

    #[Test]
    public function skipsItemsWithNonStringIdOrLabel(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 123, 'label' => 'Numeric ID', 'type' => 'text'],
            ['id' => 'valid', 'label' => ['array'], 'type' => 'text'],
            ['id' => 'ok', 'label' => 'OK', 'type' => 123],
            ['id' => 'good', 'label' => 'Good', 'type' => 'text', 'placeholder' => ['not-string']],
            ['id' => 'fine', 'label' => 'Fine', 'type' => 'text', 'placeholder' => 'hint'],
        ]);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertCount(1, $result);
        self::assertSame('fine', $result[0]['id']);
    }

    #[Test]
    public function optionsWithNonScalarValuesAreConvertedToEmptyStrings(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'q', 'label' => 'Q', 'type' => 'select', 'options' => ['valid', ['nested'], 42]],
        ]);

        $result = (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());
        self::assertSame(['valid', '', '42'], $result[0]['options']);
    }

    #[Test]
    public function handlesEmptySystemPrompt(): void
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(
                fn(string $p): bool => str_contains($p, 'JSON-Objekt')
                    && str_contains($p, 'ANWEISUNGEN ZUR AUSGABE'),
            ))
            ->willReturn([]);

        (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions(
            $this->createTemplate(''),
        );
    }

    #[Test]
    public function generateQuestionsNamesThisExtensionAndItsOperation(): void
    {
        $captured = null;
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->willReturnCallback(static function (mixed ...$args) use (&$captured): array {
                $captured = $args[1] ?? null;

                return [];
            });

        (new BriefingService(
            $completionService,
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
        ))->generateQuestions($this->createTemplate());

        self::assertInstanceOf(ChatOptions::class, $captured);
        self::assertSame(LlmCallerSource::EXTENSION, $captured->getCallerSourceExtension());
        self::assertSame('generateBriefingQuestions', $captured->getCallerSourceOperation());
    }
}
