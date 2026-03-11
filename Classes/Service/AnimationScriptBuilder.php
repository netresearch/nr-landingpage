<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

/**
 * Builds GSAP animation scripts for structured mode content elements.
 *
 * Maps animation type names to GSAP calls targeting #c{uid} selectors.
 */
final class AnimationScriptBuilder
{
    private const ANIMATION_MAP = [
        'fade-up' => ['prop' => 'opacity: 0, y: 40'],
        'fade-down' => ['prop' => 'opacity: 0, y: -40'],
        'slide-left' => ['prop' => 'opacity: 0, x: -60'],
        'slide-right' => ['prop' => 'opacity: 0, x: 60'],
        'zoom-in' => ['prop' => 'opacity: 0, scale: 0.8'],
        'scale-up' => ['prop' => 'opacity: 0, scale: 0.5'],
        'stagger-children' => ['prop' => 'opacity: 0, y: 20', 'children' => true],
        'typewriter' => ['special' => 'typewriter'],
        'parallax' => ['special' => 'parallax'],
    ];

    /**
     * Build animation script HTML from UID-to-animation map.
     *
     * @param array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}> $animations
     * @return string Complete <script data-creative> block, or empty string if no valid animations
     */
    public function build(array $animations): string
    {
        $calls = [];
        foreach ($animations as $uid => $config) {
            $type = $config['type'] ?? '';
            if ($type === '' || !isset(self::ANIMATION_MAP[$type])) {
                continue;
            }

            $duration = $this->clamp((float) ($config['duration'] ?? 0.8), 0.1, 3.0);
            $delay = $this->clamp((float) ($config['delay'] ?? 0.0), 0.0, 2.0);
            $stagger = $this->clamp((float) ($config['stagger'] ?? 0.15), 0.05, 0.5);
            $def = self::ANIMATION_MAP[$type];

            $selector = "'#c{$uid}'";

            if (($def['special'] ?? '') === 'typewriter') {
                $calls[] = "document.querySelectorAll({$selector} + ' h1, ' + {$selector} + ' h2, ' + {$selector} + ' p').forEach(function(el) { var t = el.textContent; el.textContent = ''; gsap.to(el, {scrollTrigger: {$selector}, text: t, duration: {$duration}, delay: {$delay}, ease: 'none'}); });";
                continue;
            }
            if (($def['special'] ?? '') === 'parallax') {
                $calls[] = "gsap.to({$selector}, {scrollTrigger: {trigger: {$selector}, scrub: true}, y: -30, ease: 'none'});";
                continue;
            }

            $prop = $def['prop'] ?? '';
            $target = ($def['children'] ?? false) ? "{$selector} + ' > *'" : $selector;
            $staggerProp = ($def['children'] ?? false) ? ", stagger: {$stagger}" : '';
            $calls[] = "gsap.from({$target}, {scrollTrigger: {$selector}, {$prop}, duration: {$duration}, delay: {$delay}{$staggerProp}});";
        }

        if ($calls === []) {
            return '';
        }

        $script = implode("\n", $calls);

        return <<<HTML
            <script data-creative>
            {$script}
            </script>
            HTML;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
