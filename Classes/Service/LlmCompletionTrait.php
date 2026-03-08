<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use RuntimeException;

/**
 * Shared LLM JSON completion logic for services that need to call the LLM
 * using a template's configured LlmConfiguration record.
 *
 * Requires the using class to have:
 * - $this->completionService (CompletionService)
 * - $this->llmServiceManager (LlmServiceManagerInterface)
 * - $this->configurationRepository (LlmConfigurationRepository)
 * - $this->logger (LoggerInterface|null)
 */
trait LlmCompletionTrait
{
    /**
     * Complete a JSON prompt using the template's LLM configuration if available,
     * falling back to the default CompletionService.
     *
     * @return array<string, mixed>
     */
    private function completeJsonWithTemplate(Template $template, string $prompt): array
    {
        if ($template->llmConfiguration > 0) {
            $llmConfig = $this->configurationRepository->findByUid($template->llmConfiguration);
            if ($llmConfig !== null) {
                $messages = [
                    ['role' => 'system', 'content' => 'You MUST respond with valid JSON only. No markdown, no explanation.'],
                    ['role' => 'user', 'content' => $prompt],
                ];
                $completionResponse = $this->llmServiceManager->chatWithConfiguration($messages, $llmConfig);
                $content = trim($completionResponse->content);
                // Strip markdown code fences if present
                if (str_starts_with($content, '```')) {
                    $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
                    $content = preg_replace('/\s*```\s*$/', '', $content) ?? $content;
                }
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    $this->logger?->warning('LLM returned non-JSON response', [
                        'template' => $template->identifier,
                        'content' => mb_substr($completionResponse->content, 0, 500),
                    ]);
                    throw new RuntimeException('LLM returned invalid JSON: ' . json_last_error_msg());
                }
                return $decoded;
            }
        }

        return $this->completionService->completeJson($prompt, ChatOptions::json());
    }
}
