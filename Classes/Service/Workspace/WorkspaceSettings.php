<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

/**
 * The workspace's extension configuration (ext_conf_template.txt), with the
 * bounds ADR-0001 sets whatever the configuration says.
 */
final readonly class WorkspaceSettings
{
    /** No link lives longer than this, whatever the configuration says. */
    public const HARD_MAX_LIFETIME_DAYS = 90;

    private const DEFAULT_LIFETIME_DAYS = 7;

    private const DEFAULT_MAX_LIFETIME_DAYS = 30;

    /**
     * @param array<array-key, mixed>|null $config the `workspace` section of the
     *        extension configuration; null reads it from TYPO3_CONF_VARS, which
     *        has no side effect (ExtensionConfiguration::get() may write
     *        LocalConfiguration during a frontend request)
     */
    public function __construct(
        private ?array $config = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->flag('enabled', true);
    }

    public function allowInsecureHttp(): bool
    {
        return $this->flag('allowInsecureHttp', false);
    }

    /**
     * The longest lifetime a link may be given, in days (1 to 90).
     */
    public function maxLifetimeDays(): int
    {
        return $this->bounded($this->int('maxLinkLifetimeDays', self::DEFAULT_MAX_LIFETIME_DAYS), self::HARD_MAX_LIFETIME_DAYS);
    }

    /**
     * The lifetime the form offers, in days, never above the maximum.
     */
    public function defaultLifetimeDays(): int
    {
        return $this->bounded($this->int('defaultLinkLifetimeDays', self::DEFAULT_LIFETIME_DAYS), $this->maxLifetimeDays());
    }

    /**
     * The origin to build share links from, or '' for the backend request's.
     */
    public function publicBaseUrl(): string
    {
        $value = $this->raw('publicBaseUrl');
        $url = is_string($value) ? rtrim(trim($value), '/') : '';

        return preg_match('#^https?://[^\s/]+$#', $url) === 1 ? $url : '';
    }

    private function bounded(int $days, int $max): int
    {
        return max(1, min($days, $max));
    }

    private function int(string $key, int $default): int
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function flag(string $key, bool $default): bool
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function raw(string $key): mixed
    {
        $config = $this->config;
        if ($config === null) {
            $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
            $extensions = is_array($typo3ConfVars) ? ($typo3ConfVars['EXTENSIONS'] ?? null) : null;
            $own = is_array($extensions) ? ($extensions['nr_landingpage'] ?? null) : null;
            $config = is_array($own) && is_array($own['workspace'] ?? null) ? $own['workspace'] : [];
        }

        return $config[$key] ?? null;
    }
}
