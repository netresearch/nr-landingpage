<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Shared LLM JSON completion logic for services that need to call the LLM
 * using a template's configured LlmConfiguration record.
 *
 * Requires the using class to have:
 * - $this->completionService (CompletionServiceInterface)
 * - $this->llmServiceManager (LlmServiceManagerInterface)
 * - $this->configurationRepository (LlmConfigurationRepository)
 * - $this->logger (LoggerInterface|null)
 */
trait LlmCompletionTrait
{
    /**
     * Restore a list of items from a decoded JSON response.
     *
     * Templates without their own LlmConfiguration fall back to
     * CompletionService::completeJson(), which sets response_format json_object.
     * That mode forbids a top-level array, so a prompt asking for a list never
     * gets one back. Measured against the live instance, the model answers with
     * a single item flattened to the top level; wrapping it in an envelope is
     * the other shape it produces. Both are turned back into a list here.
     *
     * A response that already is a list passes through - the path with a
     * configured LlmConfiguration sends no response_format and can return one.
     *
     * @param array<mixed> $decoded
     * @param list<string> $itemKeys Keys that identify a single item
     *
     * @return list<array<mixed>>
     */
    private function normalizeToItemList(array $decoded, array $itemKeys): array
    {
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, is_array(...)));
        }

        // One item, flattened to the top level.
        if ($itemKeys !== [] && array_diff($itemKeys, array_keys($decoded)) === []) {
            return [$decoded];
        }

        // An envelope such as {"questions": [...]} - take the list it carries.
        foreach ($decoded as $value) {
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, is_array(...)));
            }
        }

        return [];
    }

    /**
     * Complete a JSON prompt using the template's LLM configuration if available,
     * falling back to the default CompletionService.
     *
     * @param string $operation Names what the caller is running, for nr-llm's
     *                          per-operation usage breakdown. Passed in rather
     *                          than fixed here: this trait serves several
     *                          callers, and one constant would collapse them
     *                          into a single row.
     *
     * @return array<string, mixed>
     */
    private function completeJsonWithTemplate(Template $template, string $prompt, string $operation): array
    {
        if ($template->llmConfiguration > 0) {
            $llmConfig = $this->configurationRepository->findByUid($template->llmConfiguration);
            if ($llmConfig !== null) {
                $messages = [
                    ['role' => 'system', 'content' => 'You MUST respond with valid JSON only. No markdown, no explanation, no code fences.'],
                    ['role' => 'user', 'content' => $prompt],
                ];
                $completionResponse = $this->llmServiceManager->chatWithConfiguration(
                    $messages,
                    $llmConfig,
                    [
                        TelemetryMiddleware::METADATA_SOURCE_EXTENSION => LlmCallerSource::EXTENSION,
                        TelemetryMiddleware::METADATA_SOURCE_OPERATION => $operation,
                    ],
                );
                $content = trim($completionResponse->content);
                // Strip markdown code fences if present
                if (str_starts_with($content, '```')) {
                    $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
                    $content = preg_replace('/\s*```\s*$/', '', $content) ?? $content;
                }
                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    $firstError = json_last_error_msg();
                    $content = $this->sanitizeJsonControlCharacters($content);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $this->logger?->warning('LLM response required control character sanitization', [
                            'template' => $template->identifier,
                            'originalError' => $firstError,
                        ]);
                    }
                }
                if (!is_array($decoded)) {
                    $jsonError = json_last_error_msg();
                    $this->dumpLlmResponse($template->identifier, $completionResponse->content);

                    // Detect truncation: finish_reason 'length' means max_tokens was hit
                    $truncated = $completionResponse->finishReason === 'length';
                    if ($truncated) {
                        $maxTokens = $llmConfig->getMaxTokens();
                        $this->logger?->warning('LLM response truncated at max_tokens limit', [
                            'template' => $template->identifier,
                            'maxTokens' => $maxTokens,
                            'contentLength' => mb_strlen($content),
                        ]);
                        throw new RuntimeException(sprintf(
                            'Die LLM-Antwort wurde bei %d Tokens abgeschnitten und ist unvollständig. '
                            . 'Bitte max_tokens in der LLM-Konfiguration erhöhen (empfohlen: 4096+).',
                            $maxTokens ?? 0,
                        ));
                    }

                    $this->logger?->warning('LLM returned non-JSON response', [
                        'template' => $template->identifier,
                        'jsonError' => $jsonError,
                        'contentLength' => mb_strlen($content),
                        'finishReason' => $completionResponse->finishReason,
                        'dumpFile' => 'var/log/llm_response_*.txt',
                    ]);
                    throw new RuntimeException('LLM returned invalid JSON: ' . $jsonError);
                }
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return $this->completionService->completeJson(
            $prompt,
            ChatOptions::json()->withCallerSource(LlmCallerSource::EXTENSION, $operation),
        );
    }

    /**
     * Dump the raw LLM response to a file for debugging.
     */
    private function dumpLlmResponse(string $templateIdentifier, string $content): void
    {
        try {
            $logDir = Environment::getVarPath() . '/log';
            if (!is_dir($logDir)) {
                return;
            }
            $filename = sprintf('llm_response_%s_%s.txt', $templateIdentifier, date('Ymd_His'));
            file_put_contents($logDir . '/' . $filename, $content);
        } catch (Throwable) {
            // Silently ignore dump failures
        }
    }

    /**
     * Escape unescaped control characters inside JSON string values.
     *
     * LLMs sometimes produce raw newlines, tabs, or other control characters
     * inside JSON strings instead of the required \n, \t escapes.
     * Walks the JSON character by character tracking string context to only
     * escape control characters that appear inside quoted strings.
     */
    private function sanitizeJsonControlCharacters(string $json): string
    {
        $result = '';
        $len = strlen($json);
        $inString = false;
        $i = 0;

        while ($i < $len) {
            $char = $json[$i];

            if ($inString) {
                if ($char === '\\' && $i + 1 < $len) {
                    // Already-escaped sequence — keep as-is
                    $result .= $char . $json[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                    $result .= $char;
                    $i++;
                    continue;
                }
                $ord = ord($char);
                if ($ord < 0x20) {
                    $result .= match ($char) {
                        "\n" => '\\n',
                        "\r" => '\\r',
                        "\t" => '\\t',
                        default => sprintf('\\u%04X', $ord),
                    };
                } else {
                    $result .= $char;
                }
            } else {
                if ($char === '"') {
                    $inString = true;
                }
                $result .= $char;
            }
            $i++;
        }

        return $result;
    }
}
