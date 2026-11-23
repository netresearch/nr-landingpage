<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLandingpage\Service\NoContentTypeOfferedException;
use Netresearch\NrLandingpage\Service\SectionChildren;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The Page Composer asks for the items of the content types that have them,
 * keeps what the type describes, and offers only the types the page and the
 * editor allow (NEXT-228/229).
 */
#[CoversClass(ContentGeneratorService::class)]
final class ContentGeneratorServiceChildrenTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aGeneratedSectionKeepsTheChildrenItsTypeDescribes(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('clean')
            ->with('accordion', ['tx_items' => [['header' => 'Q1', 'bogus' => 'x']]])
            ->willReturn(['tx_items' => [['header' => 'Q1']]]);

        $sections = (new ReflectionMethod(ContentGeneratorService::class, 'validateSections'))->invoke(
            $this->service($children),
            ['sections' => [['section' => 'FAQ', 'ctype' => 'accordion', 'header' => 'FAQ', 'children' => ['tx_items' => [['header' => 'Q1', 'bogus' => 'x']]]]]],
            ['accordion'],
            [0],
        );

        self::assertSame(['tx_items' => [['header' => 'Q1']]], $sections[0]['children']);
    }

    #[Test]
    public function aTemplateWithoutAListGetsNoChildren(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::never())->method('clean');

        $sections = (new ReflectionMethod(ContentGeneratorService::class, 'validateSections'))->invoke(
            $this->service($children),
            ['sections' => [['section' => 'FAQ', 'ctype' => 'text', 'header' => 'FAQ', 'children' => ['tx_items' => [['header' => 'Q1']]]]]],
            [],
            [0],
        );

        self::assertSame([], $sections[0]['children']);
    }

    #[Test]
    public function theTemplateTypesAreNarrowedToThoseThePageAndTheEditorAllow(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::once())->method('offeredOnPage')
            ->with(12, $GLOBALS['BE_USER'], ['text', 'accordion', 'card_group'])
            ->willReturn(['text', 'accordion']);

        $offered = (new ReflectionMethod(ContentGeneratorService::class, 'offeredCTypes'))->invoke(
            $this->service($children),
            new Template(uid: 1, title: 'T', identifier: 't', allowedCTypes: ['text', 'accordion', 'card_group']),
            12,
        );

        self::assertSame(['text', 'accordion'], $offered);
    }

    #[Test]
    public function aTemplateListNarrowedToNothingRefusesTheGeneration(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->method('offeredOnPage')->willReturn([]);

        $this->expectException(NoContentTypeOfferedException::class);
        $this->expectExceptionMessage('below page 12');

        (new ReflectionMethod(ContentGeneratorService::class, 'offeredCTypes'))->invoke(
            $this->service($children),
            new Template(uid: 1, title: 'Falkenquell', identifier: 't', allowedCTypes: ['accordion']),
            12,
        );
    }

    #[Test]
    public function aTemplateWithoutAListKeepsTheDefaults(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->expects(self::never())->method('offeredOnPage');

        $offered = (new ReflectionMethod(ContentGeneratorService::class, 'offeredCTypes'))->invoke(
            $this->service($children),
            new Template(uid: 1, title: 'T', identifier: 't'),
            12,
        );

        self::assertSame([], $offered);
    }

    #[Test]
    public function thePromptDescribesTheItemsOnlyForTypesThatHaveThem(): void
    {
        $children = $this->createMock(SectionChildren::class);
        $children->method('describe')->willReturnCallback(
            static fn(array $cTypes): array => in_array('accordion', $cTypes, true)
                ? ['accordion' => ['tx_items' => ['table' => 'tx_item', 'maxItems' => 5, 'fields' => ['header' => ['type' => 'input', 'label' => 'Header', 'required' => true]]]]]
                : [],
        );
        $method = new ReflectionMethod(ContentGeneratorService::class, 'buildChildrenBlock');

        $block = $method->invoke($this->service($children), ['text', 'accordion']);
        self::assertStringContainsString('"children": {"tx_items": [{"header": "..."}', $block);
        self::assertStringContainsString('"maxItems": 5', $block);

        self::assertSame('', $method->invoke($this->service($children), ['text']), 'a template without such types keeps its prompt');
    }

    private function service(SectionChildren $children): ContentGeneratorService
    {
        return new ContentGeneratorService(
            $this->createMock(CompletionServiceInterface::class),
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $this->createMock(CTypeMetadataService::class),
            $this->createMock(BackendLayoutService::class),
            new CreativeHtmlSanitizer(),
            $children,
        );
    }
}
