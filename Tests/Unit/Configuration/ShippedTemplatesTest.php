<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ShippedTemplatesTest extends UnitTestCase
{
    /** @var list<array<string, int|string>> */
    private array $definitions;

    /** @var array<string, mixed> */
    private array $tcaColumns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->definitions = require dirname(__DIR__, 3) . '/Configuration/ShippedTemplates.php';
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_nrlandingpage_domain_model_template.php';
        $this->tcaColumns = $tca['columns'];
    }

    #[Test]
    public function shipsTheFourTemplatesOfTheProduct(): void
    {
        self::assertSame(
            ['Product Launch', 'Event', 'Creative One-Pager', 'SaaS Feature'],
            array_column($this->definitions, 'title'),
        );
    }

    #[Test]
    public function identifiersAreUniqueSlugs(): void
    {
        $identifiers = array_column($this->definitions, 'identifier');

        self::assertSame($identifiers, array_values(array_unique($identifiers)));
        foreach ($identifiers as $identifier) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $identifier);
        }
    }

    #[Test]
    public function everyKeyIsAColumnOfTheTemplateTable(): void
    {
        foreach ($this->definitions as $definition) {
            foreach (array_keys($definition) as $key) {
                self::assertArrayHasKey($key, $this->tcaColumns, $definition['identifier'] . ': ' . $key . ' is no TCA column');
            }
        }
    }

    #[Test]
    public function everyTemplateHasTitleDescriptionAndPrompt(): void
    {
        foreach ($this->definitions as $definition) {
            foreach (['title', 'description', 'system_prompt'] as $key) {
                self::assertNotSame('', trim((string) ($definition[$key] ?? '')), $definition['identifier'] . ' lacks ' . $key);
            }
        }
    }

    #[Test]
    public function modesAndContentTypesAreValid(): void
    {
        $modes = array_column($this->definitions, 'generation_mode', 'identifier');
        self::assertSame('creative', $modes['creative-one-pager']);
        self::assertSame('structured', $modes['product-launch']);

        foreach ($this->definitions as $definition) {
            self::assertContains($definition['generation_mode'], ['structured', 'creative']);
            self::assertContains($definition['briefing_mode'], ['none', 'optional', 'required']);
            self::assertSame('hidden', $definition['publish_mode'], 'a generated page starts hidden');
            $cTypes = array_filter(explode(',', (string) $definition['allowed_ctypes']));
            if ($definition['generation_mode'] === 'creative') {
                self::assertSame([], $cTypes, 'creative mode hides the content type field');
            } else {
                self::assertNotSame([], $cTypes);
                self::assertSame([], array_diff($cTypes, ['header', 'text', 'textmedia', 'bullets']));
            }
        }
    }
}
