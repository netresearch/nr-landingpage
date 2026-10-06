<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

/**
 * Builds the animation map for the content elements of a generated page.
 *
 * The map is a JSON data block (<script type="application/json">), which
 * browsers do not execute. Resources/Public/JavaScript/frontend/animations.js
 * reads it and animates each #c{uid} element. Types come from a fixed list;
 * duration, delay and stagger are clamped.
 */
final class AnimationScriptBuilder
{
    public const DURATION_MIN = 0.1;
    public const DURATION_MAX = 3.0;
    public const DURATION_DEFAULT = 0.8;

    public const DELAY_MIN = 0.0;
    public const DELAY_MAX = 2.0;
    public const DELAY_DEFAULT = 0.0;

    public const STAGGER_MIN = 0.05;
    public const STAGGER_MAX = 0.5;
    public const STAGGER_DEFAULT = 0.15;

    /**
     * Animation types the frontend runtime implements.
     */
    public const TYPES = [
        'fade-up', 'fade-down', 'slide-left', 'slide-right', 'zoom-in', 'scale-up',
        'stagger-children', 'typewriter', 'parallax',
    ];

    /**
     * Build the animation map element from a UID-to-animation map.
     *
     * @param array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}> $animations
     * @return string JSON data block, or empty string if no valid animations
     */
    public function build(array $animations): string
    {
        $map = [];
        foreach ($animations as $uid => $config) {
            $type = $config['type'] ?? '';
            if ($uid <= 0 || !in_array($type, self::TYPES, true)) {
                continue;
            }

            $entry = ['type' => $type];
            if ($type !== 'parallax') {
                $entry['duration'] = $this->clamp((float) ($config['duration'] ?? self::DURATION_DEFAULT), self::DURATION_MIN, self::DURATION_MAX);
                $entry['delay'] = $this->clamp((float) ($config['delay'] ?? self::DELAY_DEFAULT), self::DELAY_MIN, self::DELAY_MAX);
            }
            if ($type === 'stagger-children') {
                $entry['stagger'] = $this->clamp((float) ($config['stagger'] ?? self::STAGGER_DEFAULT), self::STAGGER_MIN, self::STAGGER_MAX);
            }
            $map[(string) $uid] = $entry;
        }

        if ($map === []) {
            return '';
        }

        $json = json_encode(
            $map,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION,
        );

        return '<script type="application/json" data-nr-landingpage-animations>' . $json . '</script>';
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
