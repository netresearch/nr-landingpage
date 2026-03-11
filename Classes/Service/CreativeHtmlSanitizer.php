<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

/**
 * Sanitizer for creative mode HTML output.
 *
 * Allows <style>, <svg> (inline), and common semantic HTML tags.
 * Blocks <script>, event handlers, and CSS url() to prevent
 * loading external resources or executing JavaScript.
 *
 * Security note: Creative mode content is stored as CType "html" and
 * rendered unsandboxed on the public frontend. All sanitization rules
 * must be robust against entity-encoded bypass attempts.
 */
final class CreativeHtmlSanitizer
{
    /**
     * Sanitize creative HTML by removing dangerous elements while
     * preserving <style> blocks and inline SVG.
     */
    public function sanitize(string $html): string
    {
        // 1. Remove <script> tags and their content
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;

        // 2. Remove event handler attributes (onclick, onload, onerror, etc.)
        $html = preg_replace('#\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html) ?? $html;

        // 3. Neutralize javascript: and data: protocols in href/src/action attributes.
        //    Decode HTML entities in attribute values first to prevent bypass via
        //    &#106;avascript: or &#100;ata: encoded variants.
        $html = $this->neutralizeDangerousProtocols($html);

        // 4. Remove CSS url() and @import to prevent external resource loading
        $html = $this->removeCssUrls($html);
        $html = $this->removeCssImports($html);

        // 5. Remove <iframe>, <object>, <embed>, <form>, <input>, <textarea>, <button> tags
        $html = preg_replace('#<(iframe|object|embed|form|input|textarea|button)\b[^>]*(?:/>|>(?:.*?</\1>)?)#is', '', $html) ?? $html;

        // 6. Remove <img> tags that have a src attribute (external images).
        //    Allow <img data-image-slot="0"> placeholders (no src) for FAL image slots.
        $html = preg_replace('#<img\b[^>]*\bsrc\s*=[^>]*>#is', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * Neutralize javascript: and data: protocols in href/src/action attribute values.
     *
     * Decodes HTML entities within attribute values before checking, so that
     * entity-encoded bypass attempts like &#106;avascript: are caught.
     */
    private function neutralizeDangerousProtocols(string $html): string
    {
        return preg_replace_callback(
            '#(href|src|action)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is',
            static function (array $matches): string {
                $attr = $matches[1];
                $value = $matches[2];

                // Strip quotes for checking
                $quote = '';
                if (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'") {
                    $quote = $value[0];
                    $value = substr($value, 1, -1);
                }

                // Decode entities in the attribute value to catch encoded bypass attempts
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalized = preg_replace('#\s+#', '', $decoded) ?? $decoded;
                $normalized = strtolower($normalized);

                if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:') || str_starts_with($normalized, 'vbscript:')) {
                    return $attr . '=' . $quote . $quote;
                }

                return $matches[0];
            },
            $html,
        ) ?? $html;
    }

    /**
     * Remove @import rules from <style> blocks.
     *
     * CSS @import can load external stylesheets, same risk class as url().
     * Handles both @import url("...") and @import "..." syntax.
     */
    private function removeCssImports(string $html): string
    {
        return preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            static fn(array $matches): string => $matches[1] . preg_replace(
                '#@import\s+[^;]+;#i',
                '',
                $matches[2],
            ) . $matches[3],
            $html,
        ) ?? $html;
    }

    /**
     * Remove url() from CSS within <style> blocks and inline style attributes.
     */
    private function removeCssUrls(string $html): string
    {
        // Remove url() in <style> blocks
        $html = preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            static fn(array $matches): string => $matches[1] . preg_replace(
                '#url\s*\([^)]*\)#i',
                'none',
                $matches[2],
            ) . $matches[3],
            $html,
        ) ?? $html;

        // Remove url() in inline style attributes
        $html = preg_replace_callback(
            '#(style\s*=\s*["\'])([^"\']*?)(["\'])#is',
            static fn(array $matches): string => $matches[1] . preg_replace(
                '#url\s*\([^)]*\)#i',
                'none',
                $matches[2],
            ) . $matches[3],
            $html,
        ) ?? $html;

        return $html;
    }
}
