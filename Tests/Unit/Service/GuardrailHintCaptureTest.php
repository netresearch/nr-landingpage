<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\GuardrailHintCapture;
use Netresearch\NrLandingpage\Tests\Unit\Service\Fixtures\FakeGuardrailHintCollector;
use Netresearch\NrLandingpage\Tests\Unit\Service\Fixtures\StorableMessageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(GuardrailHintCapture::class)]
final class GuardrailHintCaptureTest extends UnitTestCase
{
    /**
     * @param list<mixed> $hints
     */
    private function captureWith(array $hints): GuardrailHintCapture
    {
        $collector = new FakeGuardrailHintCollector($hints);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === FakeGuardrailHintCollector::class);
        $container->method('get')->with(FakeGuardrailHintCollector::class)->willReturn($collector);

        return new GuardrailHintCapture($container, FakeGuardrailHintCollector::class);
    }

    #[Test]
    public function runsTheCallWithoutHintsWhenNrLlmHasNoCollector(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');

        $captured = (new GuardrailHintCapture($container))->capture(static fn(): string => 'text');

        // The released nr-llm the tests install has no GuardrailHintCollector.
        self::assertSame(['result' => 'text', 'hints' => []], $captured);
    }

    #[Test]
    public function runsTheCallWithoutHintsWhenBuiltWithoutContainer(): void
    {
        $captured = (new GuardrailHintCapture(null, FakeGuardrailHintCollector::class))->capture(static fn(): int => 7);

        self::assertSame(['result' => 7, 'hints' => []], $captured);
    }

    #[Test]
    public function returnsTheResultAndTheHintsTheCollectorReported(): void
    {
        $object = new stdClass();
        $object->guardrail = 'rule-judge';
        $object->kind = 'violation';
        $object->message = 'Rule 2 "Address the reader formally" is broken by "du".';
        $object->rule = 'Address the reader formally';
        $object->passage = 'du';

        $captured = $this->captureWith([
            $object,
            ['kind' => 'unavailable', 'message' => 'The text was not checked.'],
            ['kind' => 'violation', 'message' => ''],
            ['kind' => 'violation'],
            'not a hint',
        ])->capture(static fn(): array => ['questions' => []]);

        self::assertSame(['questions' => []], $captured['result']);
        self::assertSame([
            ['kind' => 'violation', 'message' => 'Rule 2 "Address the reader formally" is broken by "du".', 'passage' => 'du'],
            ['kind' => 'unavailable', 'message' => 'The text was not checked.', 'passage' => ''],
        ], $captured['hints']);
    }

    #[Test]
    public function concerningLeavesOutOnlyTheHintsWhosePassageIsInAnotherSection(): void
    {
        $hints = [
            ['kind' => 'violation', 'message' => 'in this section', 'passage' => "Kauf  JETZT\nein"],
            ['kind' => 'violation', 'message' => 'in another section', 'passage' => 'Schnäppchen'],
            ['kind' => 'violation', 'message' => 'in no section', 'passage' => '<script>alert(1)</script>'],
            ['kind' => 'violation', 'message' => 'in both', 'passage' => 'Angebot'],
            ['kind' => 'unavailable', 'message' => 'not checked', 'passage' => ''],
        ];
        $sections = [
            ['section' => 'Intro', 'header' => 'Angebot', 'bodytext' => '<p>Das Schnäppchen</p>'],
            ['section' => 'Hero', 'header' => 'Angebot', 'bodytext' => '<p>Kauf <strong>jetzt</strong> ein &amp; spare</p>', 'imageKeywords' => ['sale']],
        ];

        self::assertSame(
            ['in this section', 'in no section', 'in both', 'not checked'],
            array_column(GuardrailHintCapture::concerning($hints, $sections, 1), 'message'),
        );
    }

    #[Test]
    public function forResponseLeavesThePassageOut(): void
    {
        self::assertSame(
            [['kind' => 'violation', 'message' => 'Rule broken.']],
            GuardrailHintCapture::forResponse([['kind' => 'violation', 'message' => 'Rule broken.', 'passage' => 'secret passage']]),
        );
    }

    #[Test]
    public function storableMessageLeavesTheQuotedPassageOut(): void
    {
        $e = new StorableMessageException('Rule 1 is broken by "the passage".', 'Rule 1 is broken.');

        self::assertSame('Rule 1 is broken.', GuardrailHintCapture::storableMessage($e));
        self::assertSame('Plain failure', GuardrailHintCapture::storableMessage(new RuntimeException('Plain failure')));
    }
}
