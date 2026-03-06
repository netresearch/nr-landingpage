<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Event;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(BeforePageCreationEvent::class)]
#[CoversClass(AfterContentGenerationEvent::class)]
final class EventTest extends UnitTestCase
{
    private function createTemplate(): Template
    {
        return new Template(uid: 1, title: 'T', identifier: 't');
    }

    #[Test]
    public function beforePageCreationEventHoldsData(): void
    {
        $template = $this->createTemplate();
        $event = new BeforePageCreationEvent($template, 10, ['title' => 'Test'], [['CType' => 'text']]);

        self::assertSame($template, $event->template);
        self::assertSame(10, $event->parentPageId);
        self::assertSame(['title' => 'Test'], $event->pageData);
        self::assertSame([['CType' => 'text']], $event->contentElements);
    }

    #[Test]
    public function beforePageCreationEventAllowsModification(): void
    {
        $event = new BeforePageCreationEvent($this->createTemplate(), 10, ['title' => 'Old'], []);
        $event->pageData = ['title' => 'New'];
        $event->contentElements = [['CType' => 'header']];

        self::assertSame('New', $event->pageData['title']);
        self::assertCount(1, $event->contentElements);
    }

    #[Test]
    public function afterContentGenerationEventHoldsData(): void
    {
        $template = $this->createTemplate();
        $event = new AfterContentGenerationEvent($template, 42, [100, 101]);

        self::assertSame($template, $event->template);
        self::assertSame(42, $event->pageUid);
        self::assertSame([100, 101], $event->contentElementUids);
    }
}
