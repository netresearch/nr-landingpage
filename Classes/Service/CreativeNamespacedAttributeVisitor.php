<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use DOMAttr;
use DOMElement;
use DOMNode;
use TYPO3\HtmlSanitizer\Context;
use TYPO3\HtmlSanitizer\Visitor\VisitorInterface;

/**
 * html-sanitizer visitor that removes every namespaced attribute
 * (xlink:href, xml:lang, ...) from creative output.
 *
 * Creative output needs none of them: SVG 2 uses a plain href, which the
 * allowlist checks.
 */
final class CreativeNamespacedAttributeVisitor implements VisitorInterface
{
    public function beforeTraverse(Context $context): void {}

    public function enterNode(DOMNode $domNode): DOMNode
    {
        if (!$domNode instanceof DOMElement) {
            return $domNode;
        }

        $namespaced = [];
        foreach ($domNode->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && $attribute->namespaceURI !== null) {
                $namespaced[] = $attribute;
            }
        }
        foreach ($namespaced as $attribute) {
            $domNode->removeAttributeNode($attribute);
        }

        return $domNode;
    }

    public function leaveNode(DOMNode $domNode): DOMNode
    {
        return $domNode;
    }

    public function afterTraverse(Context $context): void {}
}
