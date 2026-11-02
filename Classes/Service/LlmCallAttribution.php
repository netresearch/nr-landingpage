<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * What every LLM call of this extension tells nr-llm about itself: the caller
 * identity (LlmCallerSource) and the site the landing page is generated for.
 *
 * The site is the one of the page the landing page is placed below, by its
 * identifier; '' when no such page is known. nr-llm decides what the site
 * changes (ADR-244): a call on the installation default runs on the
 * configuration the site names in `nrllm.configuration`, and a configuration
 * chosen on the template still wins.
 */
final class LlmCallAttribution
{
    /**
     * Call metadata for a template's own configuration. The configuration stays
     * the template's; the site selects the term lists bound to it (nr-llm ADR-238).
     *
     * @return array<string, string>
     */
    public static function metadata(string $operation, string $site): array
    {
        $metadata = [
            TelemetryMiddleware::METADATA_SOURCE_EXTENSION => LlmCallerSource::EXTENSION,
            TelemetryMiddleware::METADATA_SOURCE_OPERATION => $operation,
        ];
        if ($site !== '') {
            $metadata[ProviderCallContext::METADATA_SITE] = $site;
        }

        return $metadata;
    }

    /**
     * Options for a template on "Use Default": with a site, nr-llm runs the call
     * on the configuration that site names instead of the installation default.
     */
    public static function options(ChatOptions $options, string $operation, string $site): ChatOptions
    {
        $options = $options->withCallerSource(LlmCallerSource::EXTENSION, $operation);

        return $site !== '' ? $options->withSite($site) : $options;
    }
}
