<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(PageCreatorService::class)]
final class PageCreatorServiceTest extends UnitTestCase
{
    private function createTemplate(string $publishMode = 'hidden'): Template
    {
        return new Template(uid: 1, title: 'T', identifier: 't', publishMode: $publishMode);
    }

    private function createMockDataHandler(array $substMap = [], array $errorLog = []): DataHandler
    {
        $dh = $this->createMock(DataHandler::class);
        $dh->substNEWwithIDs = $substMap;
        $dh->errorLog = $errorLog;
        return $dh;
    }

    private function createService(DataHandler $dataHandler, ?EventDispatcherInterface $eventDispatcher = null): PageCreatorService
    {
        $dispatcher = $eventDispatcher ?? $this->createPassthroughDispatcher();

        return new class ($dispatcher, $dataHandler) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $eventDispatcher,
                private readonly DataHandler $mockDataHandler,
            ) {
                parent::__construct($eventDispatcher);
            }

            protected function createDataHandler(): DataHandler
            {
                return $this->mockDataHandler;
            }
        };
    }

    private function createPassthroughDispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        return $dispatcher;
    }

    #[Test]
    public function createLandingPageReturnsPageAndContentUids(): void
    {
        $dh = $this->createMockDataHandler([
            'NEW_page' => 42,
            'NEW_content_0' => 100,
            'NEW_content_1' => 101,
        ]);

        $service = $this->createService($dh);
        $result = $service->createLandingPage(
            $this->createTemplate(),
            10,
            'Test Page',
            '/test-page',
            ['seo_title' => 'SEO Test'],
            [
                ['section' => 'hero', 'ctype' => 'header', 'header' => 'Hello', 'subheader' => 'Sub', 'bodytext' => ''],
                ['section' => 'text', 'ctype' => 'text', 'header' => 'Content', 'subheader' => '', 'bodytext' => '<p>Text</p>'],
            ],
        );

        self::assertSame(42, $result['pageUid']);
        self::assertSame([100, 101], $result['contentUids']);
    }

    #[Test]
    public function hiddenFlagSetForHiddenPublishMode(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return $dataMap['pages']['NEW_page']['hidden'] === 1;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate('hidden'), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function hiddenFlagNotSetForVisiblePublishMode(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return $dataMap['pages']['NEW_page']['hidden'] === 0;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate('visible'), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function contentElementsGetCorrectSorting(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10, 'NEW_content_1' => 11]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return ($dataMap['tt_content']['NEW_content_0']['sorting'] ?? 0) === 256
                    && ($dataMap['tt_content']['NEW_content_1']['sorting'] ?? 0) === 512;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'a', 'ctype' => 'text', 'header' => 'A', 'subheader' => '', 'bodytext' => ''],
            ['section' => 'b', 'ctype' => 'text', 'header' => 'B', 'subheader' => '', 'bodytext' => ''],
        ]);
    }

    #[Test]
    public function pageFieldsMergedIntoPageData(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return ($dataMap['pages']['NEW_page']['seo_title'] ?? '') === 'My SEO Title'
                    && ($dataMap['pages']['NEW_page']['description'] ?? '') === 'My Description';
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', pageFields: ['seo_title', 'description']);
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [
            'seo_title' => 'My SEO Title',
            'description' => 'My Description',
        ], []);
    }

    #[Test]
    public function reservedPageFieldsAreBlocked(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                // pid should be parentPageId (10), not overwritten to 999
                return $page['pid'] === 10
                    && !isset($page['TSconfig'])
                    && !isset($page['is_siteroot']);
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', pageFields: ['seo_title', 'pid', 'TSconfig', 'is_siteroot']);
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [
            'pid' => '999',
            'TSconfig' => 'malicious = 1',
            'is_siteroot' => '1',
            'seo_title' => 'Valid',
        ], []);
    }

    #[Test]
    public function nonAllowedPageFieldsAreBlocked(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                return ($page['seo_title'] ?? '') === 'Valid'
                    && !isset($page['og_title']);
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', pageFields: ['seo_title']);
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [
            'seo_title' => 'Valid',
            'og_title' => 'Not allowed by template',
        ], []);
    }

    #[Test]
    public function emptyPageFieldsAllowlistAllowsAllNonReservedFields(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                return ($page['seo_title'] ?? '') === 'Valid'
                    && ($page['og_title'] ?? '') === 'Also valid';
            }), []);

        // Empty pageFields = no restriction (beyond reserved fields)
        $template = new Template(uid: 1, title: 'T', identifier: 't', pageFields: []);
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [
            'seo_title' => 'Valid',
            'og_title' => 'Also valid',
        ], []);
    }

    #[Test]
    public function throwsExceptionOnDataHandlerError(): void
    {
        $dh = $this->createMockDataHandler([], ['Some error occurred']);
        $service = $this->createService($dh);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Some error occurred/');

        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function throwsExceptionWhenNoPageUidReturned(): void
    {
        $dh = $this->createMockDataHandler([]);
        $service = $this->createService($dh);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no UID returned/');

        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function dispatchesBeforePageCreationEvent(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof BeforePageCreationEvent) {
                    self::assertSame(10, $event->parentPageId);
                    self::assertSame(1, $event->template->uid);
                }
                return $event;
            });

        $service = $this->createService($dh, $dispatcher);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function dispatchesAfterContentGenerationEvent(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 42, 'NEW_content_0' => 100]);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof AfterContentGenerationEvent) {
                    self::assertSame(42, $event->pageUid);
                    self::assertSame([100], $event->contentElementUids);
                }
                return $event;
            });

        $service = $this->createService($dh, $dispatcher);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'a', 'ctype' => 'text', 'header' => 'A', 'subheader' => '', 'bodytext' => ''],
        ]);
    }
}
