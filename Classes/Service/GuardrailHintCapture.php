<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Collects the hints nr-llm's guardrails report while a generation runs.
 *
 * nr-llm's rule-set judge (ADR-288 there) can deliver a text and report each
 * rule it breaks, or that the check could not run. Every wizard call reaches
 * nr-llm through a method that returns a decoded array or a string, so the
 * hints come from nr-llm's GuardrailHintCollector, the shared service its
 * guardrail middleware records into. An nr-llm without that class runs the
 * call as before and reports no hints. The collector is taken from the
 * container, as a new instance would not be the one the middleware records
 * into.
 *
 * The hints are read by shape, so this works against an nr-llm release that
 * does not have the classes.
 */
final class GuardrailHintCapture
{
    public const COLLECTOR_CLASS = 'Netresearch\\NrLlm\\Service\\Guardrail\\GuardrailHintCollector';

    /**
     * @param ContainerInterface|null $container      null runs every call without collecting
     * @param string                  $collectorClass The collector to resolve; replaced in tests
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly string $collectorClass = self::COLLECTOR_CLASS,
    ) {}

    /**
     * Run a call and return its result with the hints reported while it ran.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{result: T, hints: list<array{kind: string, message: string, passage: string}>}
     */
    public function capture(callable $call): array
    {
        $collector = $this->container !== null
            && class_exists($this->collectorClass)
            && $this->container->has($this->collectorClass)
                ? $this->container->get($this->collectorClass)
                : null;
        if (!is_object($collector) || !method_exists($collector, 'capture')) {
            return ['result' => $call(), 'hints' => []];
        }

        $captured = $collector->capture($call);
        $captured = is_array($captured) ? $captured : [];
        /** @var T $result */
        $result = $captured['result'] ?? null;

        return ['result' => $result, 'hints' => self::readHints($captured['hints'] ?? null)];
    }

    /**
     * The hints that concern one section of a generated answer of several.
     *
     * A violation quotes the passage it found. A hint is left out only when
     * its passage is in another section and not in this one: the editor would
     * be warned about text that is not on the screen. A passage found in no
     * section stays, as the judge read the text before the sections were
     * sanitised, and a hint without a passage ("the text was not checked")
     * concerns every section.
     *
     * @param list<array{kind: string, message: string, passage: string}> $hints
     * @param array<array-key, array<mixed>> $sections
     *
     * @return list<array{kind: string, message: string, passage: string}>
     */
    public static function concerning(array $hints, array $sections, int|string $index): array
    {
        $texts = array_map(
            static fn(array $section): string => self::normalize(implode(' ', self::strings($section))),
            $sections,
        );

        return array_values(array_filter(
            $hints,
            static function (array $hint) use ($texts, $index): bool {
                $passage = self::normalize($hint['passage']);
                if ($passage === '' || str_contains($texts[$index] ?? '', $passage)) {
                    return true;
                }
                foreach ($texts as $other => $text) {
                    if ($other !== $index && str_contains($text, $passage)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * The hints as the wizard shows them: the sentence for the editor and its kind.
     *
     * @param list<array{kind: string, message: string, passage: string}> $hints
     *
     * @return list<array{kind: string, message: string}>
     */
    public static function forResponse(array $hints): array
    {
        return array_map(
            static fn(array $hint): array => ['kind' => $hint['kind'], 'message' => $hint['message']],
            $hints,
        );
    }

    /**
     * What a log line may keep of an error: nr-llm's refusal without the
     * passage of the generated text it quotes (storableMessage(), ADR-288),
     * otherwise the message itself.
     */
    public static function storableMessage(Throwable $e): string
    {
        if (method_exists($e, 'storableMessage')) {
            $message = $e->storableMessage();
            if (is_string($message)) {
                return $message;
            }
        }

        return $e->getMessage();
    }

    /**
     * @return list<array{kind: string, message: string, passage: string}>
     */
    private static function readHints(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $hints = [];
        foreach ($entries as $entry) {
            $fields = is_object($entry) ? get_object_vars($entry) : $entry;
            if (!is_array($fields) || !is_string($fields['message'] ?? null) || $fields['message'] === '') {
                continue;
            }

            $hints[] = [
                'kind' => is_string($fields['kind'] ?? null) ? $fields['kind'] : '',
                'message' => $fields['message'],
                'passage' => is_string($fields['passage'] ?? null) ? $fields['passage'] : '',
            ];
        }

        return $hints;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            } elseif (is_array($value)) {
                $strings = [...$strings, ...self::strings($value)];
            }
        }

        return $strings;
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
