<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use DOMElement;
use DOMNode;
use TYPO3\HtmlSanitizer\Context;
use TYPO3\HtmlSanitizer\Visitor\VisitorInterface;

/**
 * html-sanitizer visitor that removes elements inside inline SVG which are not
 * SVG elements.
 *
 * A browser does not keep HTML elements such as <p> or <b> inside SVG; it
 * closes the SVG element and builds them in the surrounding HTML. The parser
 * of the sanitizer keeps them nested, so the sanitized tree and the tree a
 * browser builds from the output would differ. Removing them keeps both the
 * same.
 */
final class CreativeSvgContentVisitor implements VisitorInterface
{
    /**
     * @param list<string> $svgElementNames lowercase names of the allowed SVG elements
     */
    public function __construct(
        private readonly array $svgElementNames,
    ) {}

    public function beforeTraverse(Context $context): void {}

    public function enterNode(DOMNode $domNode): ?DOMNode
    {
        if (!$domNode instanceof DOMElement) {
            return $domNode;
        }

        $name = strtolower($domNode->localName ?? $domNode->nodeName);
        if ($name === 'svg' || in_array($name, $this->svgElementNames, true) || $name === 'a') {
            return $domNode;
        }

        for ($parent = $domNode->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (strtolower($parent->localName ?? $parent->nodeName) === 'svg') {
                return null;
            }
        }

        return $domNode;
    }

    public function leaveNode(DOMNode $domNode): DOMNode
    {
        return $domNode;
    }

    public function afterTraverse(Context $context): void {}
}
