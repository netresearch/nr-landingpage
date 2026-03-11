<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\GenerationContext;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
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

    private function createService(
        DataHandler $dataHandler,
        ?EventDispatcherInterface $eventDispatcher = null,
        int $workspaceId = 0,
        ?ResourceFactory $resourceFactory = null,
    ): PageCreatorService {
        $dispatcher = $eventDispatcher ?? $this->createPassthroughDispatcher();
        $factory = $resourceFactory ?? $this->createMock(ResourceFactory::class);

        return new class ($dispatcher, $factory, $dataHandler, $workspaceId) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $eventDispatcher,
                ResourceFactory $resourceFactory,
                private readonly DataHandler $mockDataHandler,
                private readonly int $mockWorkspaceId,
            ) {
                parent::__construct($eventDispatcher, $resourceFactory);
            }

            protected function createDataHandler(): DataHandler
            {
                return $this->mockDataHandler;
            }

            protected function getCurrentWorkspaceId(): int
            {
                return $this->mockWorkspaceId;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Some error occurred/');

        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function throwsExceptionWhenNoPageUidReturned(): void
    {
        $dh = $this->createMockDataHandler([]);
        $service = $this->createService($dh);

        $this->expectException(RuntimeException::class);
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
    public function workspaceIdIsLoggedWhenNonZero(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Creating page in workspace', ['workspaceId' => 5]);

        $service = $this->createService($dh, workspaceId: 5);
        $service->setLogger($logger);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], []);
    }

    #[Test]
    public function contentElementsOmitEmptySubheaderAndBodytext(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ce = $dataMap['tt_content']['NEW_content_0'] ?? [];
                return ($ce['CType'] ?? '') === 'header'
                    && !isset($ce['subheader'])
                    && !isset($ce['bodytext']);
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'header', 'header' => 'Hello', 'subheader' => '', 'bodytext' => ''],
        ]);
    }

    #[Test]
    public function contentElementsIncludeNonEmptySubheaderAndBodytext(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ce = $dataMap['tt_content']['NEW_content_0'] ?? [];
                return ($ce['subheader'] ?? '') === 'Sub'
                    && ($ce['bodytext'] ?? '') === '<p>Body</p>';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'text', 'ctype' => 'text', 'header' => 'H', 'subheader' => 'Sub', 'bodytext' => '<p>Body</p>'],
        ]);
    }

    #[Test]
    public function buildContentElementsSkipsNonArrayEntries(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return !isset($dataMap['tt_content']);
            }), []);

        $service = $this->createService($dh);
        // @phpstan-ignore argument.type (intentionally passing invalid data for test)
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            'not-an-array',
            123,
        ]);
    }

    #[Test]
    public function pageFieldsWithEmptyValuesAreSkipped(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                return ($page['seo_title'] ?? '') === 'Valid'
                    && !isset($page['description']);
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', pageFields: ['seo_title', 'description']);
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [
            'seo_title' => 'Valid',
            'description' => '',
        ], []);
    }

    #[Test]
    public function getCurrentWorkspaceIdReturnsZeroWhenNoBeUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dispatcher = $this->createPassthroughDispatcher();

        // Use real PageCreatorService (not the test subclass) to test getCurrentWorkspaceId
        $factory = $this->createMock(ResourceFactory::class);
        $service = new class ($dispatcher, $factory, $dh) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $eventDispatcher,
                ResourceFactory $resourceFactory,
                private readonly DataHandler $mockDataHandler,
            ) {
                parent::__construct($eventDispatcher, $resourceFactory);
            }

            protected function createDataHandler(): DataHandler
            {
                return $this->mockDataHandler;
            }

            public function exposeGetCurrentWorkspaceId(): int
            {
                return $this->getCurrentWorkspaceId();
            }
        };

        self::assertSame(0, $service->exposeGetCurrentWorkspaceId());
    }

    #[Test]
    public function getCurrentWorkspaceIdReturnsWorkspaceFromBeUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 3;
        $GLOBALS['BE_USER'] = $backendUser;

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dispatcher = $this->createPassthroughDispatcher();

        $factory = $this->createMock(ResourceFactory::class);
        $service = new class ($dispatcher, $factory, $dh) extends PageCreatorService {
            public function __construct(
                EventDispatcherInterface $eventDispatcher,
                ResourceFactory $resourceFactory,
                private readonly DataHandler $mockDataHandler,
            ) {
                parent::__construct($eventDispatcher, $resourceFactory);
            }

            protected function createDataHandler(): DataHandler
            {
                return $this->mockDataHandler;
            }

            public function exposeGetCurrentWorkspaceId(): int
            {
                return $this->getCurrentWorkspaceId();
            }
        };

        self::assertSame(3, $service->exposeGetCurrentWorkspaceId());
    }

    #[Test]
    public function backendLayoutSetFromTemplate(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                return ($page['backend_layout'] ?? '') === 'pagets__default'
                    && ($page['backend_layout_next_level'] ?? '') === 'pagets__default';
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', backendLayout: 'pagets__default');
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [], []);
    }

    #[Test]
    public function backendLayoutNotSetWhenEmpty(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $page = $dataMap['pages']['NEW_page'];
                return !isset($page['backend_layout'])
                    && !isset($page['backend_layout_next_level']);
            }), []);

        $template = new Template(uid: 1, title: 'T', identifier: 't', backendLayout: '');
        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [], []);
    }

    #[Test]
    public function imageReferenceCreatedForTextmediaCType(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? null;
                if ($ref === null) {
                    return false;
                }
                return $ref['uid_local'] === 42
                    && $ref['uid_foreign'] === 'NEW_content_0'
                    && $ref['pid'] === 'NEW_page'
                    && $ref['tablenames'] === 'tt_content'
                    && $ref['fieldname'] === 'assets'
                    && ($dataMap['tt_content']['NEW_content_0']['assets'] ?? '') === 'NEW_ref_0';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'textmedia', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function imageReferenceCreatedForImageCType(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? null;
                return $ref !== null && $ref['fieldname'] === 'image';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'image', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function imageReferenceCreatedForTextpicCType(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? null;
                return $ref !== null && $ref['fieldname'] === 'image';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'textpic', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function textCTypeWithImageIsUpgradedToTextpic(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                // CType should be upgraded from 'text' to 'textpic'
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                if (($element['CType'] ?? '') !== 'textpic') {
                    return false;
                }
                // Image reference should exist with field 'image' (textpic uses 'image')
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? [];
                return ($ref['uid_local'] ?? 0) === 42
                    && ($ref['fieldname'] ?? '') === 'image';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>T</p>', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function textCTypeWithoutImageStaysText(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                return ($element['CType'] ?? '') === 'text'
                    && !isset($dataMap['sys_file_reference']);
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>T</p>', 'imageUid' => 0],
        ]);
    }

    #[Test]
    public function imageReferenceCreatedForUploadsCType(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? null;
                return $ref !== null
                    && $ref['fieldname'] === 'media'
                    && ($dataMap['tt_content']['NEW_content_0']['media'] ?? '') === 'NEW_ref_0';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'files', 'ctype' => 'uploads', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function unknownCTypeWithImageIsUpgradedToTextpic(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                $ref = $dataMap['sys_file_reference']['NEW_ref_0'] ?? [];
                return ($element['CType'] ?? '') === 'textpic'
                    && ($ref['fieldname'] ?? '') === 'image';
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'header', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function noImageReferenceWhenImageUidIsZero(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return !isset($dataMap['sys_file_reference']);
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'textmedia', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 0],
        ]);
    }

    #[Test]
    public function sysFileReferenceIncludesPidForAllCTypes(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10, 'NEW_content_1' => 11]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                foreach ($dataMap['sys_file_reference'] ?? [] as $ref) {
                    if (!isset($ref['pid']) || $ref['pid'] !== 'NEW_page') {
                        return false;
                    }
                }
                return count($dataMap['sys_file_reference'] ?? []) === 2;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'textmedia', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 42],
            ['section' => 'text', 'ctype' => 'textpic', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'imageUid' => 43],
        ]);
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

    #[Test]
    public function contentElementsUseColPosFromSectionData(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10, 'NEW_content_1' => 11]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return ($dataMap['tt_content']['NEW_content_0']['colPos'] ?? -1) === 1
                    && ($dataMap['tt_content']['NEW_content_1']['colPos'] ?? -1) === 2;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'main', 'ctype' => 'text', 'header' => 'Main', 'subheader' => '', 'bodytext' => '', 'colPos' => 1],
            ['section' => 'sidebar', 'ctype' => 'text', 'header' => 'Side', 'subheader' => '', 'bodytext' => '', 'colPos' => 2],
        ]);
    }

    #[Test]
    public function contentElementsDefaultColPosToZeroWhenMissing(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return ($dataMap['tt_content']['NEW_content_0']['colPos'] ?? -1) === 0;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
        ]);
    }

    #[Test]
    public function contentElementsHandleNumericStringColPos(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return ($dataMap['tt_content']['NEW_content_0']['colPos'] ?? -1) === 3;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 10, 'T', '/t', [], [
            ['section' => 'hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '', 'colPos' => '3'],
        ]);
    }

    #[Test]
    public function generationMetadataWrittenToPageData(): void
    {
        $template = new Template(uid: 5, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['text']);
        $context = new GenerationContext(['title' => 'Test'], 99);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap) use ($template): bool {
                $page = $dataMap['pages']['NEW_page'];
                return $page['tx_nrlandingpage_template_uid'] === 5
                    && $page['tx_nrlandingpage_config_hash'] === $template->getConfigHash()
                    && isset($page['tx_nrlandingpage_generated_at'])
                    && is_int($page['tx_nrlandingpage_generated_at'])
                    && $page['tx_nrlandingpage_briefing_data'] === '{"title":"Test"}'
                    && $page['tx_nrlandingpage_source_page_uid'] === 99;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [], [], $context);
    }

    #[Test]
    public function regeneratePageIsAlwaysHidden(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', publishMode: 'visible');
        $context = new GenerationContext([], 42);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                return $dataMap['pages']['NEW_page']['hidden'] === 1;
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($template, 10, 'T', '/t', [], [], $context);
    }

    #[Test]
    public function resolveImagePlaceholdersReplacesWithUrl(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/team-photo.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<section><img data-image-slot="0" alt="Team"></section>',
            42,
        );

        self::assertStringContainsString('src="/fileadmin/team-photo.jpg"', $result);
        self::assertStringContainsString('alt="Team"', $result);
        self::assertStringNotContainsString('data-image-slot', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersRemovesWhenNoImage(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $subject = $this->createService($dh);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<section><img data-image-slot="0" alt="Team"><p>Text</p></section>',
            0,
        );

        self::assertStringNotContainsString('<img', $result);
        self::assertStringContainsString('<p>Text</p>', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersHandlesReorderedAttributes(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(7)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<img alt="Hero" data-image-slot="0" class="hero-img">',
            7,
        );

        self::assertStringContainsString('src="/fileadmin/hero.jpg"', $result);
        self::assertStringContainsString('alt="Hero"', $result);
        self::assertStringNotContainsString('data-image-slot', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersFallsBackOnInvalidFile(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->willThrowException(new \InvalidArgumentException('File not found'));

        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<section><img data-image-slot="0" alt="X"><p>Text</p></section>',
            999,
        );

        self::assertStringNotContainsString('<img', $result);
        self::assertStringContainsString('<p>Text</p>', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersReturnsUnchangedWhenNoPlaceholder(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $input = '<section><p>No image here</p></section>';
        $result = $method->invoke($subject, $input, 42);

        self::assertSame($input, $result);
    }

    #[Test]
    public function resolveImagePlaceholdersFallsBackOnNullPublicUrl(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn(null);

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<section><img data-image-slot="0" alt="Hero"><p>Text</p></section>',
            42,
        );

        self::assertStringNotContainsString('<img', $result);
        self::assertStringContainsString('<p>Text</p>', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersEscapesPublicUrl(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/file with "quotes" & special.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(1)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<img data-image-slot="0" alt="Test">',
            1,
        );

        self::assertStringContainsString('src="/fileadmin/file with &quot;quotes&quot; &amp; special.jpg"', $result);
        self::assertStringNotContainsString('data-image-slot', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersPreservesCssClass(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(1)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<img data-image-slot="0" alt="Hero" class="hero-img rounded">',
            1,
        );

        self::assertStringContainsString('class="hero-img rounded"', $result);
        self::assertStringContainsString('src="/fileadmin/hero.jpg"', $result);
        self::assertStringNotContainsString('data-image-slot', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersEscapesAltAttribute(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(1)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        // Alt text with characters that need HTML escaping
        $result = $method->invoke(
            $subject,
            '<img data-image-slot="0" alt="Tom & Jerry\'s photo">',
            1,
        );

        // The & and ' in alt should be HTML-escaped
        self::assertStringContainsString('alt="Tom &amp; Jerry&#039;s photo"', $result);
        self::assertStringContainsString('src="/fileadmin/hero.jpg"', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersAddsAltWhenMissing(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(1)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        $result = $method->invoke(
            $subject,
            '<img data-image-slot="0">',
            1,
        );

        // Must have alt attribute for WCAG 1.1.1 compliance
        self::assertStringContainsString('alt=""', $result);
        self::assertStringContainsString('src="/fileadmin/hero.jpg"', $result);
    }

    #[Test]
    public function resolveImagePlaceholdersDoesNotDoubleEncodeAlt(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(1)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1]);
        $subject = $this->createService($dh, resourceFactory: $resourceFactory);

        $method = new ReflectionMethod(PageCreatorService::class, 'resolveImagePlaceholders');
        // LLM already produced escaped entity
        $result = $method->invoke(
            $subject,
            '<img data-image-slot="0" alt="Tom &amp; Jerry">',
            1,
        );

        // Should not double-encode to &amp;amp;
        self::assertStringContainsString('alt="Tom &amp; Jerry"', $result);
        self::assertStringNotContainsString('&amp;amp;', $result);
    }

    #[Test]
    public function htmlCtypeWithImageButNoPlaceholderSkipsSysFileReference(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                // CType stays html
                if (($element['CType'] ?? '') !== 'html') {
                    return false;
                }
                // bodytext unchanged (no placeholder to resolve)
                if (($element['bodytext'] ?? '') !== '<section><p>No image slot</p></section>') {
                    return false;
                }
                // No sys_file_reference created
                return !isset($dataMap['sys_file_reference']);
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 1, 'T', '/t', [], [
            ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '',
             'bodytext' => '<section><p>No image slot</p></section>', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function htmlCtypeResolvesImageIntoBodytextInsteadOfSysFileReference(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                // CType stays html (not upgraded to textpic)
                if (($element['CType'] ?? '') !== 'html') {
                    return false;
                }
                // bodytext contains resolved src URL
                if (!str_contains($element['bodytext'] ?? '', 'src="/fileadmin/hero.jpg"')) {
                    return false;
                }
                // No data-image-slot placeholder remaining
                if (str_contains($element['bodytext'] ?? '', 'data-image-slot')) {
                    return false;
                }
                // No sys_file_reference created
                return !isset($dataMap['sys_file_reference']);
            }), []);

        $service = $this->createService($dh, resourceFactory: $resourceFactory);
        $service->createLandingPage($this->createTemplate(), 1, 'T', '/t', [], [
            ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '',
             'bodytext' => '<section><img data-image-slot="0" alt="Hero shot"></section>', 'imageUid' => 42],
        ]);
    }

    #[Test]
    public function htmlCtypeRemovesPlaceholderWhenNoImageSelected(): void
    {
        $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
        $dh->expects(self::once())->method('start')
            ->with(self::callback(function (array $dataMap): bool {
                $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
                // CType stays html
                if (($element['CType'] ?? '') !== 'html') {
                    return false;
                }
                // Placeholder removed, text preserved
                $body = $element['bodytext'] ?? '';
                return !str_contains($body, '<img') && str_contains($body, '<p>Text</p>');
            }), []);

        $service = $this->createService($dh);
        $service->createLandingPage($this->createTemplate(), 1, 'T', '/t', [], [
            ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '',
             'bodytext' => '<section><img data-image-slot="0" alt="Hero"><p>Text</p></section>', 'imageUid' => 0],
        ]);
    }
}
