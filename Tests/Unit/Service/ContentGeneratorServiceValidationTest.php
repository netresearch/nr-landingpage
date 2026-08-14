<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests for ContentGeneratorService's section validation and column handling,
 * exercised through the BackendLayoutService integration.
 */
#[CoversClass(ContentGeneratorService::class)]
final class ContentGeneratorServiceValidationTest extends UnitTestCase
{
    private ContentGeneratorService $subject;
    private BackendLayoutService $backendLayoutService;
    private DataProviderCollection $dataProviderCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => str_starts_with($key, 'LLL:') ? substr($key, strrpos($key, ':') + 1) : $key,
        );
        $GLOBALS['LANG'] = $languageService;

        $this->dataProviderCollection = $this->createMock(DataProviderCollection::class);
        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $backendLayoutView = $this->createMock(BackendLayoutView::class);

        $connectionPool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $this->backendLayoutService = new BackendLayoutService(
            $this->dataProviderCollection,
            $languageServiceFactory,
            $connectionPool,
            $backendLayoutView,
        );

        $this->subject = new ContentGeneratorService(
            $this->createMock(CompletionServiceInterface::class),
            $this->createMock(LlmServiceManagerInterface::class),
            $this->createMock(LlmConfigurationRepository::class),
            $this->createMock(CTypeMetadataService::class),
            $this->backendLayoutService,
            new \Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * response_format json_object forbids a top-level array, so the fallback
     * path receives either an envelope or a single section flattened to the top
     * level. Both used to be discarded, which is what produced "No content
     * sections were generated" instead of an error.
     */
    #[Test]
    public function validateSectionsAcceptsAnEnvelope(): void
    {
        $response = [
            'sections' => [
                ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'bodytext' => ''],
                ['section' => 'Body', 'ctype' => 'text', 'colPos' => 0, 'header' => 'B', 'bodytext' => ''],
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0]);

        self::assertCount(2, $result);
        self::assertSame('Hero', $result[0]['section']);
        self::assertSame('Body', $result[1]['section']);
    }

    #[Test]
    public function validateSectionsAcceptsASingleSectionFlattenedToTheTopLevel(): void
    {
        $response = ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'H', 'bodytext' => ''];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0]);

        self::assertCount(1, $result);
        self::assertSame('Hero', $result[0]['section']);
    }

    #[Test]
    public function validateCreativeSectionsAcceptsAnEnvelope(): void
    {
        $response = [
            'sections' => [
                ['section' => 'Hero', 'colPos' => 0, 'header' => 'H', 'bodytext' => '<p>x</p>'],
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertCount(1, $result);
        self::assertSame('Hero', $result[0]['section']);
        self::assertSame('html', $result[0]['ctype']);
    }

    #[Test]
    public function validateSectionsCoercesInvalidColPosToFirstValid(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 99, 'header' => 'H', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1]);

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function validateSectionsAcceptsValidColPosForMultiColumn(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'Main', 'bodytext' => ''],
            ['section' => 'Sidebar', 'ctype' => 'text', 'colPos' => 1, 'header' => 'Side', 'bodytext' => ''],
            ['section' => 'Footer CTA', 'ctype' => 'text', 'colPos' => 2, 'header' => 'CTA', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1, 2]);

        self::assertCount(3, $result);
        self::assertSame(0, $result[0]['colPos']);
        self::assertSame(1, $result[1]['colPos']);
        self::assertSame(2, $result[2]['colPos']);
    }

    #[Test]
    public function validateSectionsHandlesStringColPos(): void
    {
        $response = [
            ['section' => 'Hero', 'ctype' => 'text', 'colPos' => '1', 'header' => 'H', 'bodytext' => ''],
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0, 1]);

        self::assertSame(1, $result[0]['colPos']);
    }

    #[Test]
    public function buildColumnBlockReturnsEmptyForSingleColumn(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $result = $method->invoke($this->subject, [0 => 'Main']);

        self::assertSame('', $result);
    }

    #[Test]
    public function buildColumnBlockIncludesAllColumnsForMultiColumnLayout(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $result = $method->invoke($this->subject, [0 => 'Main Content', 1 => 'Sidebar']);

        self::assertStringContainsString('2 Inhaltsbereiche', $result);
        self::assertStringContainsString('colPos 0: "Main Content"', $result);
        self::assertStringContainsString('colPos 1: "Sidebar"', $result);
        self::assertStringContainsString('ALLE 2 Spalten verteilen', $result);
    }

    #[Test]
    public function buildColumnBlockPassesPageIdToBackendLayoutService(): void
    {
        // This test previously verified that the pageId was forwarded to BackendLayoutService.
        // After refactoring buildColumnBlock to accept a pre-resolved columnMap,
        // the resolution responsibility has moved to the caller (buildContentPrompt).
        // We verify the block is built correctly when given a two-column map.
        $method = new ReflectionMethod($this->subject, 'buildColumnBlock');
        $result = $method->invoke($this->subject, [0 => 'Main', 1 => 'Side']);

        self::assertStringContainsString('2 Inhaltsbereiche', $result);
    }

    #[Test]
    public function buildJsonExampleShowsMultipleColPosForMultiColumnLayout(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, [0 => 'Main Content', 1 => 'Sidebar'], 'text, textmedia');

        self::assertStringContainsString('"colPos": 0', $result);
        self::assertStringContainsString('"colPos": 1', $result);
        self::assertStringContainsString('Main Content', $result);
        self::assertStringContainsString('Sidebar', $result);
    }

    #[Test]
    public function buildJsonExampleReturnsSingleColPosFallback(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, [0 => 'Main'], 'text');

        self::assertStringContainsString('"colPos": 0', $result);
        self::assertStringNotContainsString('"colPos": 1', $result);
    }

    #[Test]
    public function validateCreativeSectionsSetsCTypeToHtml(): void
    {
        $response = [
            ['section' => 'Hero', 'colPos' => 0, 'header' => 'Welcome', 'bodytext' => '<style>.hero{}</style><section class="hero">Hi</section>'],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertCount(1, $result);
        self::assertSame('html', $result[0]['ctype']);
        self::assertSame('Hero', $result[0]['section']);
        self::assertSame('Welcome', $result[0]['header']);
        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function validateCreativeSectionsStripsScriptTags(): void
    {
        $response = [
            ['section' => 'Hero', 'colPos' => 0, 'bodytext' => '<style>.x{}</style><script>alert("xss")</script><div>Safe</div>'],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertCount(1, $result);
        self::assertStringNotContainsString('<script>', $result[0]['bodytext']);
        self::assertStringContainsString('<div>Safe</div>', $result[0]['bodytext']);
        self::assertStringContainsString('<style>', $result[0]['bodytext']);
    }

    #[Test]
    public function validateCreativeSectionsCoercesInvalidColPos(): void
    {
        $response = [
            ['section' => 'Sidebar', 'colPos' => 99, 'bodytext' => '<p>Content</p>'],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main', 1 => 'Sidebar']);

        self::assertSame(0, $result[0]['colPos']);
    }

    #[Test]
    public function validateCreativeSectionsReturnsEmptyForNonArray(): void
    {
        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, 'not an array', [0 => 'Main']);

        self::assertSame([], $result);
    }

    #[Test]
    public function validateCreativeSectionsSkipsItemsWithoutSection(): void
    {
        $response = [
            ['colPos' => 0, 'bodytext' => '<p>No section key</p>'],
            ['section' => 'Valid', 'colPos' => 0, 'bodytext' => '<p>OK</p>'],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertCount(1, $result);
        self::assertSame('Valid', $result[0]['section']);
    }

    #[Test]
    public function validateCreativeSectionsDefaultsEmptyImageFields(): void
    {
        $response = [
            ['section' => 'Hero', 'colPos' => 0, 'bodytext' => '<p>Test</p>'],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertSame([], $result[0]['imageKeywords']);
        self::assertSame('', $result[0]['imagePrompt']);
    }

    #[Test]
    public function validateCreativeSectionsReadsImageKeywords(): void
    {
        $response = [
            [
                'section' => 'Hero',
                'colPos' => 0,
                'bodytext' => '<p>Test</p>',
                'imageKeywords' => ['team', 'office', 'modern'],
                'imagePrompt' => 'A modern office team working together',
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertSame(['team', 'office', 'modern'], $result[0]['imageKeywords']);
        self::assertSame('A modern office team working together', $result[0]['imagePrompt']);
    }

    #[Test]
    public function validateCreativeSectionsFiltersInvalidKeywords(): void
    {
        $response = [
            [
                'section' => 'Hero',
                'colPos' => 0,
                'bodytext' => '<p>Test</p>',
                'imageKeywords' => ['valid', '', 42, '  trimmed  '],
                'imagePrompt' => 'Prompt text',
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertSame(['valid', 'trimmed'], $result[0]['imageKeywords']);
    }

    #[Test]
    public function validateCreativeSectionsDefaultsNonArrayImageKeywords(): void
    {
        $response = [
            [
                'section' => 'Hero',
                'colPos' => 0,
                'bodytext' => '<p>Test</p>',
                'imageKeywords' => 'not-an-array',
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertSame([], $result[0]['imageKeywords']);
    }

    #[Test]
    public function validateCreativeSectionsDefaultsNonStringImagePrompt(): void
    {
        $response = [
            [
                'section' => 'Hero',
                'colPos' => 0,
                'bodytext' => '<p>Test</p>',
                'imagePrompt' => ['not', 'a', 'string'],
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertSame('', $result[0]['imagePrompt']);
    }

    #[Test]
    public function validateAnimationReturnsEmptyForNull(): void
    {
        $method = new ReflectionMethod($this->subject, 'validateAnimation');
        $result = $method->invoke($this->subject, null);

        self::assertSame([], $result);
    }

    #[Test]
    public function validateAnimationReturnsEmptyForMissingType(): void
    {
        $method = new ReflectionMethod($this->subject, 'validateAnimation');
        $result = $method->invoke($this->subject, ['duration' => 1.0]);

        self::assertSame([], $result);
    }

    #[Test]
    public function validateAnimationClampsDurationToMax(): void
    {
        $method = new ReflectionMethod($this->subject, 'validateAnimation');
        $result = $method->invoke($this->subject, ['type' => 'fade-up', 'duration' => 10.0]);

        self::assertSame('fade-up', $result['type']);
        self::assertSame(3.0, $result['duration']);
    }

    #[Test]
    public function validateAnimationReturnsValidArrayForGoodInput(): void
    {
        $method = new ReflectionMethod($this->subject, 'validateAnimation');
        $result = $method->invoke($this->subject, [
            'type' => 'slide-left',
            'duration' => 0.8,
            'delay' => 0.2,
            'stagger' => 0.15,
        ]);

        self::assertSame('slide-left', $result['type']);
        self::assertSame(0.8, $result['duration']);
        self::assertSame(0.2, $result['delay']);
        self::assertSame(0.15, $result['stagger']);
    }

    #[Test]
    public function buildJsonExampleIncludesAnimationFieldWhenEnabled(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, [0 => 'Main'], 'text', true);

        self::assertStringContainsString('"animation":', $result);
        self::assertStringContainsString('"type": "fade-up"', $result);
    }

    #[Test]
    public function buildJsonExampleOmitsAnimationFieldWhenDisabled(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, [0 => 'Main'], 'text', false);

        self::assertStringNotContainsString('"animation":', $result);
    }

    #[Test]
    public function buildJsonExampleIncludesAnimationInMultiColumnLayout(): void
    {
        $method = new ReflectionMethod($this->subject, 'buildJsonExample');
        $result = $method->invoke($this->subject, [0 => 'Main', 1 => 'Sidebar'], 'text', true);

        self::assertStringContainsString('"animation":', $result);
        self::assertStringContainsString('"colPos": 0', $result);
        self::assertStringContainsString('"colPos": 1', $result);
    }

    #[Test]
    public function buildCreativePromptContainsImagePlaceholderInstructions(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([0 => 'Main']);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $template = new \Netresearch\NrLandingpage\Domain\Model\Template(
            uid: 1,
            title: 'Test',
            identifier: 'test',
            systemPrompt: 'Create a page',
            generationMode: 'creative',
        );

        $method = new ReflectionMethod($this->subject, 'buildCreativePrompt');
        $prompt = $method->invoke($this->subject, $template, [], 'de', [0 => 'Main']);

        self::assertStringContainsString('data-image-slot', $prompt);
        self::assertStringContainsString('imageKeywords', $prompt);
        self::assertStringContainsString('imagePrompt', $prompt);
    }

    #[Test]
    public function buildCreativePromptContainsColumnInfo(): void
    {
        $layout = $this->createMock(BackendLayout::class);
        $layout->method('getUsedColumns')->willReturn([0 => 'Main', 1 => 'Sidebar']);
        $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

        $template = new \Netresearch\NrLandingpage\Domain\Model\Template(
            uid: 1,
            title: 'Creative Test',
            identifier: 'creative-test',
            systemPrompt: 'Be creative',
            backendLayout: 'pagets__2col',
            generationMode: 'creative',
            animationEnabled: false,
        );

        $method = new ReflectionMethod($this->subject, 'buildCreativePrompt');
        $result = $method->invoke($this->subject, $template, ['topic' => 'Test'], 'Deutsch', [0 => 'Main', 1 => 'Sidebar']);

        self::assertStringContainsString('KREATIV-MODUS', $result);
        self::assertStringContainsString('colPos 0: "Main"', $result);
        self::assertStringContainsString('colPos 1: "Sidebar"', $result);
        self::assertStringContainsString('KEIN JavaScript', $result);
        self::assertStringContainsString('Deutsch', $result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cssRuleSetProvider(): array
    {
        return [
            'style element with its declarations' => [
                '<p>Vorher</p><style>:root { --primary: #2F99A4; }</style><p>Nachher</p>',
                '<p>Vorher</p><p>Nachher</p>',
            ],
            'bare custom property block' => [
                ":root {\n  --brand-primary: #2F99A4;\n  --brand-accent: #FF4D00;\n}",
                '',
            ],
            'class rule set between paragraphs' => [
                '<p>Eins</p>.hero { color: red; padding: 2rem; }<p>Zwei</p>',
                '<p>Eins</p><p>Zwei</p>',
            ],
            'at-rule with a nested rule set' => [
                '@media (min-width: 40em) { .hero { padding: 4rem; } }',
                '',
            ],
            'unpaired style tag keeps the surrounding text' => [
                '<style>.a { color: red; }<p>Text</p>',
                '<p>Text</p>',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCssBraceProvider(): array
    {
        return [
            'prose in braces' => ['<p>Wir liefern in drei Schritten { Analyse, Konzept, Umsetzung }.</p>'],
            'json fragment' => ['<p>Beispiel: { "name": "Wert", "zahl": 3 }</p>'],
            'sentence before an empty block' => ['<p>Der Preis {} gilt netto.</p>'],
            'plain html' => ['<p>Ein Absatz mit <strong>Auszeichnung</strong> und einer <a href="/x">Verlinkung</a>.</p>'],
            'long sentence ending in a declaration-shaped block' => [
                '<p>Diese sehr lange Aufzaehlung nennt jeden einzelnen Punkt der Reihe nach { hinweis: gilt nur montags; }</p>',
            ],
        ];
    }

    /**
     * A model can emit a rule set into a structured section without being asked
     * for one. The strict sanitizer has no `style` tag and keeps a rejected
     * tag's text content, so the declarations would be displayed as body text.
     */
    #[Test]
    #[DataProvider('cssRuleSetProvider')]
    public function stripCssRuleSetsRemovesCss(string $input, string $expected): void
    {
        $method = new ReflectionMethod($this->subject, 'stripCssRuleSets');

        self::assertSame($expected, $method->invoke($this->subject, $input));
    }

    /**
     * The brace body is the discriminator, not the selector. Anything whose
     * contents are not entirely CSS declarations keeps its braces.
     */
    #[Test]
    #[DataProvider('nonCssBraceProvider')]
    public function stripCssRuleSetsLeavesEverythingElseAlone(string $input): void
    {
        $method = new ReflectionMethod($this->subject, 'stripCssRuleSets');

        self::assertSame($input, $method->invoke($this->subject, $input));
    }

    #[Test]
    public function validateSectionsStripsCssFromBodytext(): void
    {
        $response = [
            'section' => 'Hero',
            'ctype' => 'text',
            'colPos' => 0,
            'header' => 'H',
            'bodytext' => '<style>:root { --primary: #2F99A4; }</style><p>Willkommen bei uns.</p>',
        ];

        $method = new ReflectionMethod($this->subject, 'validateSections');
        $result = $method->invoke($this->subject, $response, [], [0]);

        self::assertCount(1, $result);
        self::assertStringNotContainsString(':root', $result[0]['bodytext']);
        self::assertStringNotContainsString('--primary', $result[0]['bodytext']);
        self::assertStringContainsString('Willkommen bei uns.', $result[0]['bodytext']);
    }

    /**
     * Creative mode owns a <style> block. It is sanitized on its own path and
     * must not lose it.
     */
    #[Test]
    public function creativeSectionsKeepTheirStyleBlock(): void
    {
        $response = [
            'sections' => [
                [
                    'section' => 'Hero',
                    'colPos' => 0,
                    'bodytext' => '<style>.hero { color: #2F99A4; }</style><div class="hero">Hallo</div>',
                ],
            ],
        ];

        $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
        $result = $method->invoke($this->subject, $response, [0 => 'Main']);

        self::assertCount(1, $result);
        self::assertSame('html', $result[0]['ctype']);
        self::assertStringContainsString('<style>', $result[0]['bodytext']);
        self::assertStringContainsString('.hero', $result[0]['bodytext']);
    }
}
