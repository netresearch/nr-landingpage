<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Locale;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Generates optimized system prompts for templates using a meta-prompt approach.
 *
 * The optimizer sends the template's structural context (layout, CTypes, colors,
 * generation mode) plus optional editor hints to an LLM, which produces
 * a reusable system prompt tailored to the template configuration.
 */

class PromptOptimizerService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_META_PROMPT = <<<'PROMPT'
        You are an expert prompt engineer. Your task is to generate a reusable system prompt
        for an AI that creates web page content within a TYPO3 CMS.

        CRITICAL: The system prompt you write must be TOPIC-NEUTRAL. It will be used as a
        template for many different topics — the actual topic comes later from the editor.
        Do NOT invent, assume, or embed any specific topic, industry, city, product, or theme.
        Use placeholders like "the given topic" or "the editor's briefing" where needed.

        IMPORTANT: There is already a BASE SYSTEM PROMPT in the LLM configuration that covers
        general quality rules, style guidelines, SEO basics, and language preferences.
        Do NOT repeat these — they are always active. Focus the template prompt on what is
        SPECIFIC to this template: its purpose, target audience, layout structure, content types,
        and generation mode.

        Based on the template structure below, write a system prompt that produces
        well-structured, high-quality content for ANY topic.

        The system prompt you write MUST:
        - Be topic-neutral — work equally well for technology, travel, food, B2B, events, etc.
        - Define the template's specific PURPOSE (landing page, blog post, product page, etc.)
        - Define tone of voice SPECIFIC to this template (derive from context if available)
        - Suggest a flexible content structure with 5-8 section types the AI can choose
          from based on the topic — do NOT prescribe a fixed order
        - Include a LAYOUT SECTION that maps EVERY colPos from the template structure
          to its purpose (e.g. "Border (colPos 3): Hero area", "Normal (colPos 0): Main content").
          This is MANDATORY when multiple columns exist — the AI needs to know WHERE to place content.
        - Reference the available content types and encourage variety
        - Give concrete guidance for each page field (SEO titles, descriptions, etc.)
        - If animation is enabled, mention that GSAP ScrollTrigger animations are added
          automatically — the AI should NOT write animation code itself
        - Be specific and actionable in HOW to write, not WHAT to write about
        - Write the prompt in the OUTPUT LANGUAGE specified below

        MODE-SPECIFIC additions (check the generation_mode in the template structure):
        - Creative mode: add CSS technique guidance (Grid, Flexbox, Gradients, SVG, clip-path),
          require :root CSS Custom Properties, emphasize visual variety, note that each section
          is a standalone HTML/CSS/SVG block
        - Structured mode: add content type selection guidance — explain when to use which CType

        Do NOT repeat general rules about quality, specificity, marketing style, or SEO basics
        — these are already covered by the base configuration.

        The system prompt will be reused across many different topics and editors,
        so it must be self-contained, topic-agnostic, and produce consistent,
        high-quality results regardless of the subject matter.

        Respond with ONLY the system prompt text in the OUTPUT LANGUAGE. No explanations, no markdown formatting.
        PROMPT;

    public function __construct(
        private readonly CompletionService $completionService,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly BackendLayoutService $backendLayoutService,
    ) {}

    public function generateOptimizedPrompt(Template $template, string $outputLanguage = ''): string
    {
        try {
            $structuralContext = $this->buildStructuralContext($template);

            $languageLabel = $outputLanguage !== '' ? $outputLanguage : $this->resolveBackendLanguage();
            $prompt = self::DEFAULT_META_PROMPT
                . "\n\n--- OUTPUT LANGUAGE ---\n"
                . 'Write the system prompt in: ' . $languageLabel
                . "\n\n--- TEMPLATE STRUCTURE ---\n" . $structuralContext;

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
        $lines[] = 'Generation Mode: ' . $template->generationMode
            . ($template->isCreativeMode()
                ? ' (each section is a standalone HTML/CSS/SVG block, CType "html")'
                : ' (standard TYPO3 content elements)');

        if ($template->allowedCTypes !== []) {
            $lines[] = 'Allowed Content Types: ' . implode(', ', $template->allowedCTypes);
        }

        if ($template->pageFields !== []) {
            $lines[] = 'Page Fields to fill: ' . implode(', ', $template->pageFields);
        }

        if ($template->backendLayout !== '') {
            $lines[] = 'Backend Layout: ' . $template->backendLayout;
            $columnMap = $this->backendLayoutService->getColumnMap($template->backendLayout);
            if (count($columnMap) > 1) {
                $lines[] = 'Layout Columns:';
                foreach ($columnMap as $colPos => $name) {
                    $lines[] = '  - colPos ' . $colPos . ': ' . $name;
                }
            }
        }

        $lines[] = 'Briefing Mode: ' . $template->briefingMode;
        $lines[] = 'Animation: ' . ($template->isAnimationEnabled() ? 'enabled (GSAP ScrollTrigger)' : 'disabled');
        $lines[] = 'Color Scheme: Primary=' . $template->colorPrimary
            . ', Secondary=' . $template->colorSecondary
            . ', Background=' . $template->colorBackground
            . ', Text=' . $template->colorText;

        if ($template->hasReferencePages()) {
            $lines[] = 'Reference Pages: ' . implode(', ', $template->referencePages);
        }

        // Include base LLM config system prompt summary so the optimizer knows what's already covered
        if ($template->llmConfiguration > 0) {
            $llmConfig = $this->configurationRepository->findByUid($template->llmConfiguration);
            $basePrompt = $llmConfig?->getSystemPrompt() ?? '';
            if ($basePrompt !== '') {
                $lines[] = '';
                $lines[] = 'Base LLM Configuration System Prompt (already active, do NOT repeat):';
                $lines[] = $basePrompt;
            }
        }

        if ($template->systemPrompt !== '') {
            $lines[] = '';
            $lines[] = 'Current System Prompt (for reference/improvement):';
            $lines[] = $template->systemPrompt;
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve a human-readable language name from the backend user's language setting.
     * Falls back to "English" if the locale cannot be determined.
     */
    private function resolveBackendLanguage(): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if (!$lang instanceof LanguageService) {
            return 'English';
        }

        $locale = $lang->getLocale();
        $localeString = (string) $locale;
        if ($localeString === '' || $localeString === 'default') {
            return 'English';
        }

        // Use intl extension to get the display name (e.g. "de" → "German", "fr" → "French")
        $displayName = Locale::getDisplayLanguage($localeString, 'en');

        return $displayName !== '' && $displayName !== $localeString ? $displayName : 'English';
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
