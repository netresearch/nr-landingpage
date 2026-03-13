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

final class BriefingService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use LlmCompletionTrait;

    private const MAX_QUESTIONS = 5;

    public function __construct(
        private readonly CompletionService $completionService,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
    ) {}

    /**
     * Generate briefing questions for a template via LLM.
     *
     * @return list<array{id: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>
     */
    public function generateQuestions(Template $template): array
    {
        try {
            $response = $this->completeJsonWithTemplate($template, $this->buildPrompt($template));
        } catch (Throwable $e) {
            $this->logger?->error('Briefing generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->validateQuestions($response);
    }

    private function buildPrompt(Template $template): string
    {
        return <<<PROMPT
            {$template->systemPrompt}

            --- ANWEISUNGEN ZUR AUSGABE ---

            Stelle dem Redakteur die wichtigsten Fragen, um eine effektive Seite zu erstellen.
            Maximal {self::MAX_QUESTIONS} Fragen, fokussiert auf INHALTLICHE Informationen.

            WICHTIG:
            - Der Seitentitel / das Thema wird bereits in einem separaten Feld abgefragt.
              Frage NICHT nochmal nach dem Titel oder Thema der Seite.
            - Frage NUR nach Dingen, die der Redakteur wissen kann: Zielgruppe, Kernbotschaft,
              gewuenschte Handlung, spezifische Inhalte/Zahlen/Fakten.
            - Frage NICHT nach technischen oder gestalterischen Entscheidungen wie Inhaltstyp,
              Layout, Struktur oder Designstil — das entscheidet die KI selbst.

            Priorisierung:
            1. Zielgruppe und deren Beduerfnisse (required)
            2. Kernbotschaft oder Alleinstellungsmerkmal (USP)
            3. Gewuenschte Handlung (Call-to-Action)
            4-5. Kontextspezifische Fragen je nach Template (z.B. Produkte, Termine, Zahlen)

            Verwende kurze, verstaendliche Labels. Nutze type=textarea fuer offene Fragen.
            Nutze type=select nur wenn es wenige klare Alternativen gibt (z.B. Du/Sie Ansprache).

            Antworte ausschliesslich als JSON-Array:
            [
              {"id": "string", "label": "string", "type": "text|textarea|select",
               "required": true|false, "placeholder": "string",
               "options": ["nur bei type=select"]}
            ]
            PROMPT;
    }

    /**
     * @return list<array{id: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>
     */
    private function validateQuestions(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['id'], $item['label'], $item['type'])) {
                continue;
            }

            $id = $item['id'];
            $label = $item['label'];
            $type = $item['type'];
            $placeholder = $item['placeholder'] ?? '';

            if (!is_string($id) || !is_string($label) || !is_string($type) || !is_string($placeholder)) {
                continue;
            }

            $validated[] = [
                'id' => $id,
                'label' => $label,
                'type' => in_array($type, ['text', 'textarea', 'select'], true) ? $type : 'text',
                'required' => (bool) ($item['required'] ?? false),
                'placeholder' => $placeholder,
                'options' => isset($item['options']) && is_array($item['options'])
                    ? array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $item['options']))
                    : [],
            ];
        }

        return array_slice($validated, 0, self::MAX_QUESTIONS);
    }
}
