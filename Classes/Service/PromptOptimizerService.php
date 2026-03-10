<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;

class PromptOptimizerService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_META_PROMPT = <<<'PROMPT'
        You are an expert prompt engineer specializing in conversion-optimized landing pages.
        Your task is to generate a reusable system prompt for an AI that creates landing page
        content within a TYPO3 CMS.

        CRITICAL: The system prompt you write must be TOPIC-NEUTRAL. It will be used as a
        template for many different topics — the actual topic comes later from the editor.
        Do NOT invent, assume, or embed any specific topic, industry, city, product, or theme.
        Use placeholders like "the given topic" or "the editor's briefing" where needed.

        Based on the template structure below, write a system prompt that produces
        high-converting, well-structured landing page content for ANY topic.

        The system prompt you write MUST:
        - Be topic-neutral — work equally well for technology, travel, food, B2B, events, etc.
        - Define tone of voice and language style (derive from template context if available)
        - Specify a clear content structure: Hero > Benefits > Social Proof > CTA
        - Reference the available content types and explain when to use each
        - Give concrete guidance for each page field (SEO titles, descriptions, etc.)
        - Emphasize benefit-driven copy over feature lists
        - Include instructions for writing compelling headlines and subheadlines
        - Consider the backend layout structure when suggesting content sections
        - Be specific and actionable in HOW to write, not WHAT to write about

        The system prompt will be reused across many different topics and editors,
        so it must be self-contained, topic-agnostic, and produce consistent,
        high-quality results regardless of the subject matter.

        Respond with ONLY the system prompt text. No explanations, no markdown formatting.
        PROMPT;

    public function __construct(
        private readonly CompletionService $completionService,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
    ) {}

    public function generateOptimizedPrompt(Template $template): string
    {
        try {
            $structuralContext = $this->buildStructuralContext($template);

            $prompt = self::DEFAULT_META_PROMPT . "\n\n--- TEMPLATE STRUCTURE ---\n" . $structuralContext;

            if ($template->promptOptimizerMetaPrompt !== '') {
                $prompt .= "\n\n--- EDITOR STYLE HINTS ---\n"
                    . "The editor provided these style/tone preferences. "
                    . "Incorporate them into the system prompt you write, "
                    . "but do NOT generate actual content:\n"
                    . $template->promptOptimizerMetaPrompt;
            }

            if ($template->promptOptimizerContext !== '') {
                $prompt .= "\n\n--- ADDITIONAL CONTEXT ---\n"
                    . "Background context about the brand/company. "
                    . "Use this to inform the system prompt's tone and awareness, "
                    . "but keep the prompt topic-neutral:\n"
                    . $template->promptOptimizerContext;
            }

            return $this->completeTextWithTemplate($template, $prompt);
        } catch (Throwable $e) {
            $this->logger?->error('Prompt optimization failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function buildStructuralContext(Template $template): string
    {
        $lines = [];
        $lines[] = 'Template: ' . $template->title;

        if ($template->allowedCTypes !== []) {
            $lines[] = 'Allowed Content Types: ' . implode(', ', $template->allowedCTypes);
        }

        if ($template->pageFields !== []) {
            $lines[] = 'Page Fields to fill: ' . implode(', ', $template->pageFields);
        }

        if ($template->backendLayout !== '') {
            $lines[] = 'Backend Layout: ' . $template->backendLayout;
        }

        $lines[] = 'Briefing Mode: ' . $template->briefingMode;

        if ($template->hasReferencePages()) {
            $lines[] = 'Reference Pages: ' . implode(', ', $template->referencePages);
        }

        if ($template->systemPrompt !== '') {
            $lines[] = '';
            $lines[] = 'Current System Prompt (for reference):';
            $lines[] = $template->systemPrompt;
        }

        return implode("\n", $lines);
    }

    private function completeTextWithTemplate(Template $template, string $prompt): string
    {
        if ($template->llmConfiguration > 0) {
            $llmConfig = $this->configurationRepository->findByUid($template->llmConfiguration);
            if ($llmConfig !== null) {
                $messages = [
                    ['role' => 'user', 'content' => $prompt],
                ];
                $completionResponse = $this->llmServiceManager->chatWithConfiguration($messages, $llmConfig);

                return trim($completionResponse->content);
            }
        }

        $response = $this->completionService->complete($prompt);

        return trim($response->content);
    }
}
