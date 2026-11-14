<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace\Review;

use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The templates read labels as `{labels.<id>}`; a label missing from the
 * list or from a language file would show as its bare id to a reviewer.
 */
#[CoversClass(ReviewResponder::class)]
final class ReviewLabelsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../Resources/Private/';

    #[Test]
    public function everyLabelOfTheListIsInBothLanguageFiles(): void
    {
        foreach (['locallang_review.xlf', 'de.locallang_review.xlf'] as $file) {
            $ids = $this->idsOf(self::ROOT . 'Language/' . $file);
            self::assertEqualsCanonicalizing(ReviewResponder::LABELS, $ids, $file);
        }
    }

    #[Test]
    public function everyLabelTheTemplatesUseIsInTheList(): void
    {
        $used = [];
        foreach ([...glob(self::ROOT . 'Templates/Review/*.html') ?: [], self::ROOT . 'Layouts/Review.html'] as $file) {
            preg_match_all('/labels\.([a-z_]+)/', (string) file_get_contents($file), $matches);
            $used = [...$used, ...$matches[1]];
        }

        self::assertNotSame([], $used);
        self::assertSame([], array_values(array_diff(array_unique($used), ReviewResponder::LABELS)));
    }

    #[Test]
    public function theGermanFileTranslatesEveryLabel(): void
    {
        $xml = simplexml_load_file(self::ROOT . 'Language/de.locallang_review.xlf');
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $units = $xml->xpath('//x:trans-unit');
        self::assertIsArray($units);
        foreach ($units as $unit) {
            self::assertNotSame('', trim((string) $unit->target), 'untranslated: ' . $unit['id']);
        }
    }

    #[Test]
    public function noTemplateOutputsRawHtml(): void
    {
        foreach (glob(self::ROOT . 'Templates/Review/*.html') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('f:format.raw', $source, $file);
            self::assertStringNotContainsString('f:format.html', $source, $file);
            self::assertStringNotContainsString('f:format.nl2br', $source, $file);
            self::assertStringNotContainsString('<script>', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $file);
        }
    }

    /**
     * @return list<string>
     */
    private function idsOf(string $file): array
    {
        preg_match_all('/trans-unit id="([a-z_]+)"/', (string) file_get_contents($file), $matches);

        return $matches[1];
    }
}
