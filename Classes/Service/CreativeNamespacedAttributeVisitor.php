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
use DOMXPath;
use TYPO3\HtmlSanitizer\Context;
use TYPO3\HtmlSanitizer\Visitor\VisitorInterface;

/**
 * html-sanitizer visitor that removes every namespaced attribute
 * (xlink:href, xml:lang, ...) and every namespace declaration (xmlns:...)
 * from creative output.
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

        $document = $domNode->ownerDocument;
        if ($document === null) {
            return $domNode;
        }
        $xpath = new DOMXPath($document);

        // Namespaced attributes of the whole subtree go first: removing a declaration
        // below can detach the namespace that a descendant's prefixed attribute uses,
        // which would turn it into a plain attribute of the same local name.
        $attributes = $xpath->query('descendant-or-self::*/@*[namespace-uri() != ""]', $domNode);
        foreach ($attributes === false ? [] : iterator_to_array($attributes) as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attribute->ownerElement?->removeAttributeNode($attribute);
            }
        }

        // Namespace declarations are not attributes in the DOM and do not show up in
        // the attribute list; they are removed through their namespace nodes.
        $declarations = $xpath->query('namespace::*', $domNode);
        foreach ($declarations === false ? [] : iterator_to_array($declarations) as $declaration) {
            if ($declaration->localName !== 'xml' && $declaration->parentNode === $domNode) {
                $domNode->removeAttributeNS((string) ($declaration->nodeValue ?? ''), (string) $declaration->localName);
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
