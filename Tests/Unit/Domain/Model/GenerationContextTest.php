<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Domain\Model;

use Netresearch\NrLandingpage\Domain\Model\GenerationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(GenerationContext::class)]
final class GenerationContextTest extends UnitTestCase
{
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $context = new GenerationContext();

        self::assertSame([], $context->briefingAnswers);
        self::assertSame(0, $context->sourcePageUid);
    }

    #[Test]
    public function constructorAcceptsValues(): void
    {
        $context = new GenerationContext(['title' => 'Test'], 42);

        self::assertSame(['title' => 'Test'], $context->briefingAnswers);
        self::assertSame(42, $context->sourcePageUid);
    }
}
