<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\ContextMenu;

use Netresearch\NrLandingpage\ContextMenu\LandingPageItemProvider;
use Netresearch\NrLandingpage\Service\LandingPageDetectionService;
use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(LandingPageItemProvider::class)]
final class LandingPageItemProviderTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private LandingPageItemProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['LANG'] = $this->createMock(LanguageService::class);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->subject = new LandingPageItemProvider();
    }

    #[Test]
    public function canHandleReturnsTrueForPages(): void
    {
        $this->subject->setContext('pages', '42', 'tree');

        self::assertTrue($this->subject->canHandle());
    }

    #[Test]
    public function canHandleReturnsFalseForOtherTables(): void
    {
        $this->subject->setContext('tt_content', '42', 'tree');

        self::assertFalse($this->subject->canHandle());
    }

    #[Test]
    public function canRenderReturnsFalseWhenNoTemplates(): void
    {
        $this->registerServiceMocks();
        $templateService = $this->createTemplateService([]);

        $this->subject->setContext('pages', '42', 'tree');
        $this->subject->setTemplateService($templateService);

        $items = $this->subject->addItems([]);

        self::assertSame([], $items);
    }

    #[Test]
    public function canRenderReturnsTrueWhenTemplatesAvailable(): void
    {
        $this->registerServiceMocks();
        $templateService = $this->createTemplateService([
            [
                'uid' => 1,
                'title' => 'Test',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => '',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        ]);

        $this->subject->setContext('pages', '42', 'tree');
        $this->subject->setTemplateService($templateService);

        $items = $this->subject->addItems([]);

        self::assertArrayHasKey('landingPageCreate', $items);
    }

    private function registerServiceMocks(): void
    {
        $icon = $this->createMock(Icon::class);
        $icon->method('render')->willReturn('');

        $iconFactory = $this->createMock(IconFactory::class);
        $iconFactory->method('getIcon')->willReturn($icon);
        GeneralUtility::addInstance(IconFactory::class, $iconFactory);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/nr-landingpage'));
        GeneralUtility::setSingletonInstance(UriBuilder::class, $uriBuilder);

        // Mock LandingPageDetectionService for isGeneratedLandingPage() check
        $detectionService = $this->createMock(LandingPageDetectionService::class);
        $detectionService->method('isGeneratedLandingPage')->willReturn(false);
        $this->subject->setDetectionService($detectionService);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createTemplateService(array $rows): TemplateService
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return new TemplateService($connectionPool);
    }

    #[Test]
    public function getPriorityReturns50(): void
    {
        self::assertSame(50, $this->subject->getPriority());
    }

    #[Test]
    public function canRenderReturnsFalseForUnknownItemName(): void
    {
        $this->subject->setContext('pages', '42', 'tree');

        $canRender = new ReflectionMethod($this->subject, 'canRender');
        self::assertFalse($canRender->invoke($this->subject, 'unknownItem', 'item'));
    }

    #[Test]
    public function canRenderReturnsFalseWhenItemIsDisabled(): void
    {
        $templateService = $this->createTemplateService([
            [
                'uid' => 1, 'title' => 'Test', 'identifier' => 'test', 'description' => '',
                'llm_configuration' => 0, 'system_prompt' => '', 'allowed_ctypes' => '',
                'page_fields' => '', 'reference_pages' => '', 'briefing_mode' => 'optional',
                'publish_mode' => 'hidden', 'be_groups' => '', 'backend_layout' => '',
                'prompt_optimizer_context' => '', 'prompt_optimizer_meta_prompt' => '',
                'deleted' => 0, 'hidden' => 0,
            ],
        ]);

        $this->subject->setContext('pages', '42', 'tree');
        $this->subject->setTemplateService($templateService);

        // Inject disabled items via reflection
        $disabledProp = new ReflectionProperty($this->subject, 'disabledItems');
        $disabledProp->setValue($this->subject, ['landingPageCreate']);

        $canRender = new ReflectionMethod($this->subject, 'canRender');
        self::assertFalse($canRender->invoke($this->subject, 'landingPageCreate', 'item'));
    }

    #[Test]
    public function addItemsIncludesNavigateUriInDataset(): void
    {
        $this->registerServiceMocks();
        $templateService = $this->createTemplateService([
            [
                'uid' => 1,
                'title' => 'Test',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => '',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        ]);

        $this->subject->setContext('pages', '42', 'tree');
        $this->subject->setTemplateService($templateService);

        $items = $this->subject->addItems([]);

        self::assertArrayHasKey('landingPageCreate', $items);
        $item = $items['landingPageCreate'];
        self::assertArrayHasKey('additionalAttributes', $item);
        self::assertArrayHasKey('data-callback-module', $item['additionalAttributes']);
        self::assertSame('@netresearch/nr-landingpage/context-menu-actions', $item['additionalAttributes']['data-callback-module']);
        self::assertArrayHasKey('data-navigate-uri', $item['additionalAttributes']);
        self::assertNotEmpty($item['additionalAttributes']['data-navigate-uri']);
    }
}
