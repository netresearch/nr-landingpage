<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

/**
 * Caller identity this extension reports to nr-llm.
 *
 * Every LLM call made here names the extension, so nr-llm's Analytics module
 * attributes usage and cost to it instead of listing it as "Unattributed".
 * The operation is supplied per call site, not from here — that is what makes
 * the per-operation breakdown useful.
 */
final class LlmCallerSource
{
    /**
     * Extension key as registered in composer.json (extra.typo3/cms.extension-key).
     */
    public const EXTENSION = 'nr_landingpage';
}
