<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use DOMElement;
use DOMNode;
use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\Attr\UriAttrValueBuilder;
use TYPO3\HtmlSanitizer\Behavior\ClosureAttrValue;
use TYPO3\HtmlSanitizer\Behavior\Handler\ClosureHandler;
use TYPO3\HtmlSanitizer\Behavior\NodeHandler;
use TYPO3\HtmlSanitizer\Behavior\RegExpAttrValue;
use TYPO3\HtmlSanitizer\Behavior\Tag;
use TYPO3\HtmlSanitizer\Sanitizer;
use TYPO3\HtmlSanitizer\Visitor\CommonVisitor;

/**
 * Sanitizer for creative mode HTML output.
 *
 * Creative mode content is stored as CType "html" and rendered as-is on the
 * public frontend, so everything here works on an allowlist built with
 * typo3/html-sanitizer:
 *
 * - Elements and attributes that are not listed are removed. Scripts, event
 *   handler attributes, embedded documents and form controls are therefore
 *   never part of the output, independent of how they are written.
 *   Namespaced attributes (xlink:href and the like) are removed by
 *   CreativeNamespacedAttributeVisitor.
 * - Links accept http(s), mailto, tel and local targets only.
 * - <img> is kept only as an image slot placeholder (data-image-slot, no src);
 *   the page creator fills in the FAL image URL.
 * - Inline SVG is limited to shape, text, gradient, clip/mask and filter
 *   elements; references (href, url()) may only point into the same document.
 * - CSS in <style> elements and style attributes passes CreativeCssFilter,
 *   which removes everything that loads an external resource.
 */
final class CreativeHtmlSanitizer
{
    /**
     * HTML elements that may appear in creative output (besides <a>, <img>, <style>).
     */
    private const HTML_TAGS = [
        'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'blockquote', 'q', 'cite',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'strong', 'em', 'b', 'i', 'u', 's', 'small',
        'mark', 'sub', 'sup', 'abbr', 'time', 'code', 'pre', 'figure', 'figcaption',
        'details', 'summary', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    /**
     * Inline SVG elements that may appear in creative output.
     */
    private const SVG_TAGS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'lineargradient',
        'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'marker', 'filter',
        'feblend', 'fecolormatrix', 'fecomposite', 'feflood', 'fegaussianblur', 'femerge',
        'femergenode', 'feoffset', 'feturbulence', 'fedisplacementmap', 'fedropshadow',
    ];

    /**
     * Attributes every allowed element may carry.
     */
    private const GLOBAL_ATTRS = ['class', 'id', 'title', 'lang', 'dir', 'role', 'hidden', 'style'];

    /**
     * Presentation and geometry attributes of the allowed SVG elements.
     */
    private const SVG_ATTRS = [
        'viewbox', 'xmlns', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy',
        'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'transform', 'preserveaspectratio',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-miterlimit', 'opacity', 'color', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'patternunits',
        'patterntransform', 'clip-path', 'clip-rule', 'clippathunits', 'mask', 'maskunits',
        'filter', 'filterunits', 'primitiveunits', 'markerwidth', 'markerheight', 'refx',
        'refy', 'orient', 'marker-start', 'marker-mid', 'marker-end', 'font-family',
        'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline',
        'letter-spacing', 'dx', 'dy', 'in', 'in2', 'result', 'mode', 'type', 'values',
        'stddeviation', 'operator', 'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity',
        'basefrequency', 'numoctaves', 'seed', 'scale', 'xchannelselector',
        'ychannelselector', 'focusable', 'aria-hidden', 'aria-label', 'vector-effect',
    ];

    /**
     * Attributes of HTML elements beyond GLOBAL_ATTRS.
     *
     * @var array<string, list<string>>
     */
    private const HTML_ELEMENT_ATTRS = [
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope'],
        'ol' => ['start', 'reversed', 'type'],
        'time' => ['datetime'],
        'abbr' => [],
        'details' => ['open'],
    ];

    private ?Sanitizer $sanitizer = null;

    public function __construct(
        private readonly CreativeCssFilter $cssFilter = new CreativeCssFilter(),
    ) {}

    public function sanitize(string $html): string
    {
        return trim($this->getSanitizer()->sanitize($html));
    }

    private function getSanitizer(): Sanitizer
    {
        if ($this->sanitizer instanceof Sanitizer) {
            return $this->sanitizer;
        }

        $tags = [];

        foreach (self::HTML_TAGS as $name) {
            $tags[] = (new Tag($name, Tag::ALLOW_CHILDREN))->addAttrs(
                ...$this->globalAttrs(),
                ...$this->plainAttrs(self::HTML_ELEMENT_ATTRS[$name] ?? []),
            );
        }

        // <a>: link targets restricted to http(s), mailto, tel and local URLs.
        $href = (new Attr('href'))->addValues(
            ...(new UriAttrValueBuilder())
                ->allowLocal(true)
                ->allowSchemes('http', 'https', 'mailto', 'tel')
                ->getValues(),
        );
        $tags[] = (new Tag('a', Tag::ALLOW_CHILDREN))->addAttrs(
            $href,
            (new Attr('target'))->addValues(new RegExpAttrValue('#^_(blank|self)$#')),
            new Attr('rel'),
            ...$this->globalAttrs(),
        );

        // <style>: kept as raw text so that CSS selectors and strings survive;
        // its content is rewritten by CreativeCssFilter in the visitor below.
        $tags[] = new Tag('style', Tag::ALLOW_CHILDREN | Tag::ALLOW_INSECURE_RAW_TEXT);

        // SVG: no attribute value may load anything from outside the document.
        $svgValue = new ClosureAttrValue(fn(string $value): bool => $this->cssFilter->isLocalValue($value));
        $svgAttrs = array_map(
            static fn(string $name): Attr => (new Attr($name))->addValues($svgValue),
            self::SVG_ATTRS,
        );
        $localReference = new RegExpAttrValue('#^\#[A-Za-z][\w.-]*$#');
        foreach (self::SVG_TAGS as $name) {
            $tag = (new Tag($name, Tag::ALLOW_CHILDREN))->addAttrs(...$svgAttrs, ...$this->globalAttrs());
            if ($name === 'use') {
                // <use> may only reference an element of the same document.
                $tag = $tag->addAttrs((new Attr('href'))->addValues($localReference));
            }
            $tags[] = $tag;
        }

        $behavior = (new Behavior())
            ->withName('nr-landingpage-creative')
            ->withFlags(Behavior::BLUNT)
            ->withTags(...$tags)
            ->withNodes(
                // <img> only as image slot placeholder: an element that names its
                // own source is removed together with its attributes.
                new NodeHandler(
                    (new Tag('img'))->addAttrs(
                        (new Attr('data-image-slot', Attr::MANDATORY))->addValues(new RegExpAttrValue('#^\d{1,2}$#')),
                        ...$this->plainAttrs(['alt', 'width', 'height', 'loading', 'decoding']),
                        ...$this->globalAttrs(),
                    ),
                    new ClosureHandler(
                        static fn(Tag $node, ?DOMNode $domNode): ?DOMNode => $domNode instanceof DOMElement
                            && ($domNode->hasAttribute('src') || $domNode->hasAttribute('srcset'))
                            ? null
                            : $domNode,
                    ),
                    NodeHandler::HANDLE_FIRST | NodeHandler::PROCESS_DEFAULTS,
                ),
            );

        $this->sanitizer = new Sanitizer(
            $behavior,
            new CreativeNamespacedAttributeVisitor(),
            new CommonVisitor($behavior),
            new CreativeStyleVisitor($this->cssFilter),
        );

        return $this->sanitizer;
    }

    /**
     * @return list<Attr>
     */
    private function globalAttrs(): array
    {
        return [
            ...$this->plainAttrs(self::GLOBAL_ATTRS),
            new Attr('aria-', Attr::NAME_PREFIX),
        ];
    }

    /**
     * @param list<string> $names
     * @return list<Attr>
     */
    private function plainAttrs(array $names): array
    {
        return array_map(static fn(string $name): Attr => new Attr($name), $names);
    }
}
