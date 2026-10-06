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
 * html-sanitizer visitor that runs after the allowlist visitor and passes
 * every piece of CSS in creative output through CreativeCssFilter.
 *
 * A <style> element inside SVG or MathML is removed: there its content is
 * parsed as markup, not as raw text.
 */
final class CreativeStyleVisitor implements VisitorInterface
{
    public function __construct(
        private readonly CreativeCssFilter $cssFilter,
    ) {}

    public function beforeTraverse(Context $context): void {}

    public function enterNode(DOMNode $domNode): ?DOMNode
    {
        if (!$domNode instanceof DOMElement) {
            return $domNode;
        }

        if ($domNode->hasAttribute('style')) {
            $css = trim($this->cssFilter->filter($domNode->getAttribute('style')));
            if ($css === '') {
                $domNode->removeAttribute('style');
            } else {
                $domNode->setAttribute('style', $css);
            }
        }

        if (strtolower($domNode->localName ?? '') !== 'style') {
            return $domNode;
        }

        for ($parent = $domNode->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->localName ?? ''), ['svg', 'math'], true)) {
                return null;
            }
        }

        $css = $this->cssFilter->filter($domNode->textContent ?? '');
        while ($domNode->firstChild !== null) {
            $domNode->removeChild($domNode->firstChild);
        }
        $document = $domNode->ownerDocument;
        if ($document === null) {
            return null;
        }
        $domNode->appendChild($document->createTextNode($css));

        return $domNode;
    }

    public function leaveNode(DOMNode $domNode): DOMNode
    {
        return $domNode;
    }

    public function afterTraverse(Context $context): void {}
}
