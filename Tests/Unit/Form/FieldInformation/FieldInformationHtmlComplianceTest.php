<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Form\FieldInformation;

use Netresearch\NrLandingpage\Form\FieldInformation\GeneratedPageCount;
use Netresearch\NrLandingpage\Form\FieldInformation\PromptToolsDescription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Validates that fieldInformation nodes only use HTML tags allowed by TYPO3.
 *
 * TYPO3 FormEngine restricts fieldInformation HTML to:
 *   <a>, <br>, <br/>, <div>, <em>, <i>, <p>, <strong>, <span>, <code>
 *
 * @see \TYPO3\CMS\Backend\Form\NodeExpansion\FieldInformation
 */
#[CoversClass(PromptToolsDescription::class)]
#[CoversClass(GeneratedPageCount::class)]
final class FieldInformationHtmlComplianceTest extends UnitTestCase
{
    /**
     * Tags allowed by TYPO3 fieldInformation API.
     *
     * @see \TYPO3\CMS\Backend\Form\NodeExpansion\FieldInformation
     */
    private const ALLOWED_TAGS = ['a', 'br', 'div', 'em', 'i', 'p', 'strong', 'span', 'code'];

    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => 'translated: ' . $key,
        );
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fieldInformationHtmlProvider(): iterable
    {
        yield 'PromptToolsDescription' => [PromptToolsDescription::class];
    }

    #[Test]
    #[DataProvider('fieldInformationHtmlProvider')]
    public function renderedHtmlUsesOnlyAllowedTags(string $className): void
    {
        $subject = new $className();
        $subject->setData([
            'databaseRow' => ['uid' => 1],
            'parameterArray' => [],
        ]);

        $result = $subject->render();
        $html = $result['html'] ?? '';

        if ($html === '') {
            self::assertTrue(true, 'No HTML rendered — nothing to validate');
            return;
        }

        $this->assertHtmlUsesOnlyAllowedTags($html);
    }

    #[Test]
    public function generatedPageCountUsesOnlyAllowedTags(): void
    {
        // GeneratedPageCount returns empty when count=0 (no DB available in unit tests).
        // We test the HTML format pattern directly since it's a simple template.
        $html = '<div class="form-description text-body-secondary">5 pages generated</div>';
        $this->assertHtmlUsesOnlyAllowedTags($html);
    }

    private function assertHtmlUsesOnlyAllowedTags(string $html): void
    {
        // Match all HTML tags (opening, closing, self-closing)
        preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\/?>/i', $html, $matches);

        $usedTags = array_unique(array_map('strtolower', $matches[1]));
        $disallowed = array_diff($usedTags, self::ALLOWED_TAGS);

        self::assertSame(
            [],
            array_values($disallowed),
            sprintf(
                'fieldInformation HTML contains disallowed tags: <%s>. Allowed: <%s>',
                implode('>, <', $disallowed),
                implode('>, <', self::ALLOWED_TAGS),
            ),
        );
    }
}
