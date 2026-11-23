<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\SectionChildren;
use Netresearch\NrLandingpage\Service\SectionNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The child records of a section survive the save and the approval only
 * where the template authorises their type (NEXT-229, ADR-0001 section 9).
 */
#[CoversClass(SectionNormalizer::class)]
final class SectionNormalizerChildrenTest extends UnitTestCase
{
    private const RAW = ['tx_items' => [['header' => 'Q1']]];

    #[Test]
    public function aTemplateThatAllowsTheTypeKeepsItsCleanedChildren(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('clean')->with('accordion', self::RAW)->willReturn(['tx_items' => [['header' => 'Q1']]]);

        $sections = $this->normalizer($children)->normalize(
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'children' => self::RAW]],
            new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['accordion']),
        );

        self::assertSame(['tx_items' => [['header' => 'Q1']]], $sections[0]['children']);
    }

    #[Test]
    public function aTemplateWithoutAListDropsThem(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::never())->method('clean');

        $sections = $this->normalizer($children)->normalize(
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'children' => self::RAW]],
            new Template(uid: 1, title: 'T', identifier: 't'),
        );

        self::assertSame([], $sections[0]['children']);
    }

    #[Test]
    public function aTypeTheTemplateDoesNotAllowBecomesTextAndIsCleanedAsText(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('clean')->with('text', self::RAW)->willReturn([]);

        $sections = $this->normalizer($children)->normalize(
            [['section' => 'faq', 'ctype' => 'accordion', 'header' => 'FAQ', 'children' => self::RAW]],
            new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['text']),
        );

        self::assertSame('text', $sections[0]['ctype']);
        self::assertSame([], $sections[0]['children']);
    }

    private function normalizer(SectionChildren $children): SectionNormalizer
    {
        return new SectionNormalizer($this->createMock(ImageSearchService::class), new CreativeHtmlSanitizer(), $children);
    }
}
