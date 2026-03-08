<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Form\FieldControl;

use Netresearch\NrLandingpage\Form\FieldControl\OptimizePromptControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(OptimizePromptControl::class)]
final class OptimizePromptControlTest extends UnitTestCase
{
    #[Test]
    public function renderReturnsEmptyArrayForNewRecord(): void
    {
        $subject = new OptimizePromptControl();
        $subject->setData([
            'parameterArray' => ['itemFormElName' => 'data[tx_nrlandingpage_domain_model_template][1][system_prompt]'],
            'databaseRow' => ['uid' => 0],
        ]);

        self::assertSame([], $subject->render());
    }

    #[Test]
    public function renderReturnsControlDataForExistingRecord(): void
    {
        $subject = new OptimizePromptControl();
        $subject->setData([
            'parameterArray' => ['itemFormElName' => 'data[tx_nrlandingpage_domain_model_template][1][system_prompt]'],
            'databaseRow' => ['uid' => 42],
        ]);

        $result = $subject->render();

        self::assertSame('actions-rocket', $result['iconIdentifier']);
        self::assertStringContainsString('optimizePrompt', $result['title']);
        self::assertSame('42', $result['linkAttributes']['data-template-uid']);
        self::assertNotEmpty($result['linkAttributes']['id']);
        self::assertCount(1, $result['javaScriptModules']);
    }

    #[Test]
    public function renderHandlesMissingDatabaseRow(): void
    {
        $subject = new OptimizePromptControl();
        $subject->setData([
            'parameterArray' => ['itemFormElName' => 'data[tx_nrlandingpage_domain_model_template][1][system_prompt]'],
        ]);

        self::assertSame([], $subject->render());
    }
}
