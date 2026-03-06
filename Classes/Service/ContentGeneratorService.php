<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\Tag;
use TYPO3\HtmlSanitizer\Sanitizer;
use TYPO3\HtmlSanitizer\Visitor\CommonVisitor;

final class ContentGeneratorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ?Sanitizer $sanitizer = null;

    private function sanitizeHtml(string $html): string
    {
        return $this->getSanitizer()->sanitize($html);
    }

    private function getSanitizer(): Sanitizer
    {
        if ($this->sanitizer === null) {
            $hrefAttr = (new Attr('href'))
                ->addValues(new Behavior\RegExpAttrValue('#^(https?://|/|mailto:|tel:)#'));

            $behavior = (new Behavior())
                ->withFlags(Behavior::ENCODE_INVALID_TAG)
                ->withTags(
                    new Tag('p'),
                    new Tag('br'),
                    new Tag('ul'),
                    new Tag('ol'),
                    new Tag('li'),
                    new Tag('strong'),
                    new Tag('em'),
                    (new Tag('a'))->addAttrs($hrefAttr),
                    new Tag('h2'),
                    new Tag('h3'),
                    new Tag('h4'),
                );
            $visitor = new CommonVisitor($behavior);
            $this->sanitizer = new Sanitizer($behavior, $visitor);
        }
        return $this->sanitizer;
    }

    public function __construct(
        private readonly CompletionService $completionService,
    ) {}

    /**
     * Generate content sections for a landing page via LLM.
     *
     * @param array<string, string> $briefingAnswers
     * @return list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}>
     */
    public function generateContent(Template $template, array $briefingAnswers): array
    {
        try {
            // TODO: Use $template->llmConfiguration to load the LlmConfiguration record
            // and route to the correct provider/model via LlmServiceManager::completeWithConfiguration()
            $response = $this->completionService->completeJson(
                $this->buildContentPrompt($template, $briefingAnswers),
                ChatOptions::json(),
            );
        } catch (Throwable $e) {
            $this->logger?->error('Content generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->validateSections($response, $template->allowedCTypes);
    }

    /**
     * Generate page field values (title, seo_title, description, etc.) via LLM.
     *
     * @param array<string, string> $briefingAnswers
     * @return array<string, string>
     */
    public function generatePageFields(Template $template, array $briefingAnswers): array
    {
        try {
            // TODO: Use $template->llmConfiguration to load the LlmConfiguration record
            // and route to the correct provider/model via LlmServiceManager::completeWithConfiguration()
            $response = $this->completionService->completeJson(
                $this->buildPageFieldsPrompt($template, $briefingAnswers),
                ChatOptions::json(),
            );
        } catch (Throwable $e) {
            $this->logger?->error('Page field generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->validatePageFields($response, $template->pageFields);
    }

    /**
     * @param array<string, string> $answers
     */
    private function formatBriefing(array $answers): string
    {
        $lines = [];
        foreach ($answers as $key => $value) {
            $lines[] = '- ' . $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $briefingAnswers
     */
    private function buildContentPrompt(Template $template, array $briefingAnswers): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $cTypes = implode(', ', $template->allowedCTypes);

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}

            --- ANWEISUNGEN ZUR AUSGABE ---

            Erstelle Inhalte fuer eine Landing Page basierend auf dem obigen Kontext.
            Verwende ausschliesslich folgende Content-Typen: {$cTypes}

            Antworte ausschliesslich als JSON-Array:
            [
              {"section": "string", "ctype": "one of [{$cTypes}]",
               "header": "string", "subheader": "string", "bodytext": "HTML string"}
            ]
            PROMPT;
    }

    /**
     * @param array<string, string> $briefingAnswers
     */
    private function buildPageFieldsPrompt(Template $template, array $briefingAnswers): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $fields = implode(', ', $template->pageFields);

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}

            --- ANWEISUNGEN ZUR AUSGABE ---

            Generiere Werte fuer folgende Seitenfelder: {$fields}

            Hinweis: seo_title maximal 60 Zeichen, description maximal 160 Zeichen.

            Antworte ausschliesslich als JSON-Objekt:
            {"field_name": "value", ...}
            PROMPT;
    }

    /**
     * @param list<string> $allowedCTypes
     * @return list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}>
     */
    private function validateSections(mixed $response, array $allowedCTypes): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['section'], $item['ctype'])) {
                continue;
            }

            if (!is_string($item['section']) || !is_string($item['ctype'])) {
                continue;
            }

            if (!in_array($item['ctype'], $allowedCTypes, true)) {
                continue;
            }

            $bodytext = is_string($item['bodytext'] ?? null) ? $item['bodytext'] : '';

            $validated[] = [
                'section' => $item['section'],
                'ctype' => $item['ctype'],
                'header' => is_string($item['header'] ?? null) ? $item['header'] : '',
                'subheader' => is_string($item['subheader'] ?? null) ? $item['subheader'] : '',
                'bodytext' => $this->sanitizeHtml($bodytext),
            ];
        }

        return $validated;
    }

    /**
     * @param list<string> $allowedFields
     * @return array<string, string>
     */
    private function validatePageFields(mixed $response, array $allowedFields): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowedFields, true)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $validated[$key] = $value;
        }

        return $validated;
    }
}
