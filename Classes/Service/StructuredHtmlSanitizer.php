<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\Tag;
use TYPO3\HtmlSanitizer\Sanitizer;
use TYPO3\HtmlSanitizer\Visitor\CommonVisitor;

/**
 * The HTML structured mode allows in rich text: paragraphs, lists, emphasis,
 * links to http(s), mailto and tel, and the headings below a section's own.
 * Everything else is encoded as text.
 *
 * Used for a section's body text and for the rich text of its child records,
 * at generation and again when the wizard or a workspace draft is saved.
 */
class StructuredHtmlSanitizer
{
    private ?Sanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        return $this->sanitizer()->sanitize($html);
    }

    private function sanitizer(): Sanitizer
    {
        if ($this->sanitizer === null) {
            $hrefAttr = (new Attr('href'))
                ->addValues(new Behavior\RegExpAttrValue('#^(https?://|/|mailto:|tel:)#'));

            $behavior = (new Behavior())
                ->withFlags(Behavior::ENCODE_INVALID_TAG)
                ->withTags(
                    new Tag('p'),
                    new Tag('br'),
                    new Tag('ul'),
                    new Tag('ol'),
                    new Tag('li'),
                    new Tag('strong'),
                    new Tag('em'),
                    (new Tag('a'))->addAttrs($hrefAttr),
                    new Tag('h2'),
                    new Tag('h3'),
                    new Tag('h4'),
                );
            $this->sanitizer = new Sanitizer($behavior, new CommonVisitor($behavior));
        }

        return $this->sanitizer;
    }
}
