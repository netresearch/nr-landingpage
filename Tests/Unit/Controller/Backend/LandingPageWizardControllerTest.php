<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Controller\Backend;

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLandingpage\Service\ImageProviderService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\PromptOptimizerService;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(LandingPageWizardController::class)]
final class LandingPageWizardControllerTest extends UnitTestCase
{
    private CompletionService&MockObject $completionService;
    private ConnectionPool&MockObject $connectionPool;
    private ImageSearchService&MockObject $imageSearchService;
    private ImageProviderService&MockObject $imageProviderService;
    private PageCreatorService&MockObject $pageCreatorService;
    private PromptOptimizerService&MockObject $promptOptimizerService;
    private LandingPageWizardController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->completionService = $this->createMock(CompletionService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);

        // ModuleTemplateFactory is final — instantiate without constructor since AJAX actions never use it
        /** @var ModuleTemplateFactory $moduleTemplateFactory */
        $moduleTemplateFactory = (new ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor();

        $pageRenderer = $this->createMock(PageRenderer::class);

        /** @var UriBuilder $uriBuilder */
        $uriBuilder = (new ReflectionClass(UriBuilder::class))->newInstanceWithoutConstructor();

        $templateService = new TemplateService($this->connectionPool);
        $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $briefingService = new BriefingService($this->completionService, $llmServiceManager, $configRepo);
        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');
        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main']);
        $backendLayoutService->method('formatColumnMapForPrompt')->willReturn('');
        $contentGeneratorService = new ContentGeneratorService($this->completionService, $llmServiceManager, $configRepo, $cTypeMetadataService, $backendLayoutService, new \Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer());
        $this->imageSearchService = $this->createMock(ImageSearchService::class);
        $this->imageProviderService = $this->createMock(ImageProviderService::class);

        $this->pageCreatorService = $this->createMock(PageCreatorService::class);
        $this->promptOptimizerService = $this->createMock(PromptOptimizerService::class);

        $this->subject = new LandingPageWizardController(
            $moduleTemplateFactory,
            $pageRenderer,
            $uriBuilder,
            $templateService,
            $briefingService,
            $contentGeneratorService,
            $this->imageSearchService,
            $this->imageProviderService,
            $this->pageCreatorService,
            $this->connectionPool,
            $this->createMock(SiteFinder::class),
            new \Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer(),
            $this->promptOptimizerService,
        );
    }

    private function createJsonRequest(array $body): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return (new ServerRequest())->withBody($stream)->withHeader('Content-Type', 'application/json');
    }

    private function createTemplate(int $uid = 1): Template
    {
        return new Template(
            uid: $uid,
            title: 'Test Template',
            identifier: 'test',
            systemPrompt: 'Test prompt',
            allowedCTypes: ['text', 'header'],
            pageFields: ['seo_title', 'description'],
        );
    }

    /**
     * Configure the ConnectionPool mock to return a specific row for loadByUid.
     *
     * @param array<string, mixed>|false $row
     */
    private function mockLoadByUid(array|false $row): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $statement->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($statement);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
    }

    /**
     * Configure the ConnectionPool mock to return a template row for loadForUser.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function mockLoadForUser(array $rows): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $statement->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('executeQuery')->willReturn($statement);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(int $uid = 1, string $title = 'Test Template'): array
    {
        return [
            'uid' => $uid,
            'title' => $title,
            'identifier' => 'test',
            'description' => 'Test description',
            'llm_configuration' => 0,
            'system_prompt' => 'Test prompt',
            'allowed_ctypes' => 'text,header',
            'page_fields' => 'seo_title,description',
            'reference_pages' => '',
            'briefing_mode' => 'optional',
            'publish_mode' => 'hidden',
            'be_groups' => '',
            'backend_layout' => '',
            'prompt_optimizer_context' => '',
            'prompt_optimizer_meta_prompt' => '',
            'image_task' => 0,
        ];
    }

    #[Test]
    public function templatesActionReturnsTemplateList(): void
    {
        $this->mockLoadForUser([
            $this->templateRow(1, 'Template A'),
            $this->templateRow(2, 'Template B'),
        ]);

        $response = $this->subject->templatesAction($this->createJsonRequest([]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(2, $data['data']);
        self::assertSame(1, $data['data'][0]['uid']);
        self::assertSame('Template A', $data['data'][0]['title']);
    }

    #[Test]
    public function generateBriefingActionReturnsQuestions(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $questions = [
            ['id' => 'audience', 'label' => 'Zielgruppe', 'type' => 'text', 'required' => true, 'placeholder' => ''],
        ];
        $this->completionService->method('completeJson')->willReturn($questions);

        $response = $this->subject->generateBriefingAction($this->createJsonRequest(['templateUid' => 1]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['data']);
        self::assertSame('audience', $data['data'][0]['id']);
    }

    #[Test]
    public function generateBriefingActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->generateBriefingAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function generateContentActionReturnsContentSections(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $sections = [
            ['section' => 'Hero', 'ctype' => 'text', 'header' => 'Welcome', 'subheader' => '', 'bodytext' => '<p>Hello</p>'],
        ];
        $this->completionService->method('completeJson')->willReturn($sections);

        $response = $this->subject->generateContentAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => ['audience' => 'B2B'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['data']['sections']);
        self::assertSame('Hero', $data['data']['sections'][0]['section']);
    }

    #[Test]
    public function generatePageFieldsActionReturnsFieldValues(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $pageFields = ['seo_title' => 'My Landing Page', 'description' => 'A great page'];
        $this->completionService->method('completeJson')->willReturn($pageFields);

        $response = $this->subject->generatePageFieldsAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => ['audience' => 'B2B'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('My Landing Page', $data['data']['seo_title']);
    }

    #[Test]
    public function saveActionReturnsPageUid(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $this->pageCreatorService->method('createLandingPage')->willReturn([
            'pageUid' => 42,
            'contentUids' => [100, 101],
        ]);

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
            'title' => 'My Landing Page',
            'slug' => '/my-landing-page',
            'pageFields' => ['seo_title' => 'SEO Title'],
            'contentSections' => [
                ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>B</p>'],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame(42, $data['data']['pageUid']);
        self::assertSame([100, 101], $data['data']['contentUids']);
    }

    #[Test]
    public function saveActionReturns500OnException(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $this->pageCreatorService->method('createLandingPage')
            ->willThrowException(new RuntimeException('DataHandler failed'));

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
            'title' => 'My Landing Page',
            'slug' => '/my-landing-page',
            'pageFields' => [],
            'contentSections' => [],
        ]));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertNotEmpty($data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturnsNewSection(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $sections = [
            ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H1', 'subheader' => '', 'bodytext' => '<p>A</p>'],
            ['section' => 'Features', 'ctype' => 'text', 'header' => 'H2', 'subheader' => '', 'bodytext' => '<p>B</p>'],
        ];
        $this->completionService->method('completeJson')->willReturn($sections);

        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => ['audience' => 'B2B'],
            'sectionIndex' => 1,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Features', $data['data']['section']);
        self::assertSame('H2', $data['data']['header']);
    }

    #[Test]
    public function generateBriefingActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->generateBriefingAction($this->createJsonRequest(['templateUid' => 999]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function generatePageFieldsActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->generatePageFieldsAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function generatePageFieldsActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->generatePageFieldsAction($this->createJsonRequest(['templateUid' => 999]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function generateContentActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->generateContentAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function generateContentActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->generateContentAction($this->createJsonRequest(['templateUid' => 999]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturns400WhenNegativeSectionIndex(): void
    {
        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 1,
            'sectionIndex' => -1,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('sectionIndex', $data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 999,
            'sectionIndex' => 0,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturns400WhenSectionOutOfRange(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $sections = [
            ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H1', 'subheader' => '', 'bodytext' => '<p>A</p>'],
        ];
        $this->completionService->method('completeJson')->willReturn($sections);

        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => [],
            'sectionIndex' => 5,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('out of range', $data['error']);
    }

    #[Test]
    public function saveActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->saveAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function saveActionReturns400WhenNoParentPageId(): void
    {
        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('parentPageId', $data['error']);
    }

    #[Test]
    public function saveActionReturns400WhenNoTitle(): void
    {
        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('title', $data['error']);
    }

    #[Test]
    public function saveActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 999,
            'parentPageId' => 10,
            'title' => 'My Page',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function templatesActionReturns500OnException(): void
    {
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willThrowException(new RuntimeException('DB connection failed'));

        $response = $this->subject->templatesAction($this->createJsonRequest([]));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertNotEmpty($data['error']);
    }

    #[Test]
    public function parseRequestBodyHandlesFormData(): void
    {
        $request = (new ServerRequest())->withParsedBody(['templateUid' => 1]);

        $this->mockLoadByUid($this->templateRow());

        $questions = [
            ['id' => 'q1', 'label' => 'Question', 'type' => 'text', 'required' => true, 'placeholder' => ''],
        ];
        $this->completionService->method('completeJson')->willReturn($questions);

        $response = $this->subject->generateBriefingAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function extractIntFromBodyHandlesFloatValues(): void
    {
        $request = (new ServerRequest())->withParsedBody(['templateUid' => 1.5]);

        $this->mockLoadByUid($this->templateRow());

        $questions = [
            ['id' => 'q1', 'label' => 'Question', 'type' => 'text', 'required' => true, 'placeholder' => ''],
        ];
        $this->completionService->method('completeJson')->willReturn($questions);

        // Float 1.5 should be cast to int 1, which matches templateRow uid=1
        $response = $this->subject->generateBriefingAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function saveActionHandlesNonArrayContentSections(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $this->pageCreatorService->method('createLandingPage')->willReturn([
            'pageUid' => 42,
            'contentUids' => [],
        ]);

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
            'title' => 'My Page',
            'slug' => '/my-page',
            'pageFields' => [],
            'contentSections' => [
                'not-an-array',
                42,
                ['section' => 'Hero', 'ctype' => 'text', 'header' => 'H', 'subheader' => '', 'bodytext' => '<p>B</p>'],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame(42, $data['data']['pageUid']);
    }

    #[Test]
    public function generateBriefingActionReturns500WhenLlmThrows(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM timeout'));

        $response = $this->subject->generateBriefingAction($this->createJsonRequest(['templateUid' => 1]));

        // BriefingService catches exception and returns [], controller returns success with empty data
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame([], $data['data']);
    }

    #[Test]
    public function generatePageFieldsActionReturns500WhenLlmThrows(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM timeout'));

        $response = $this->subject->generatePageFieldsAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => ['topic' => 'Test'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame([], $data['data']);
    }

    #[Test]
    public function generateContentActionReturns500WhenLlmThrows(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM timeout'));

        $response = $this->subject->generateContentAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => ['topic' => 'Test'],
        ]));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame('LLM timeout', $data['error']);
    }

    #[Test]
    public function regenerateSectionActionReturns500WhenLlmThrows(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->completionService->method('completeJson')
            ->willThrowException(new RuntimeException('LLM timeout'));

        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => [],
            'sectionIndex' => 0,
        ]));

        // LLM exception now propagates to controller's errorResponse()
        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame('LLM timeout', $data['error']);
    }

    #[Test]
    public function parseRequestBodyReturnsEmptyArrayForEmptyBody(): void
    {
        // Request with no parsed body and empty stream body
        $request = new ServerRequest();

        $response = $this->subject->generateBriefingAction($request);

        // Empty body → templateUid=0 → 400 error
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function parseRequestBodyReturnsEmptyArrayForInvalidJson(): void
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write('not valid json{{{');
        $stream->rewind();

        $request = (new ServerRequest())->withBody($stream);

        $response = $this->subject->generateBriefingAction($request);

        // Invalid JSON → empty body → templateUid=0 → 400 error
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function extractIntFromBodyReturnsDefaultForArrayValue(): void
    {
        // When templateUid is an array, extractIntFromBody should return default (0)
        $request = (new ServerRequest())->withParsedBody(['templateUid' => ['nested' => 'value']]);

        $response = $this->subject->generateBriefingAction($request);

        // Array value → default 0 → "Missing or invalid templateUid"
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function extractIntFromBodyReturnDefaultForBoolValue(): void
    {
        $request = (new ServerRequest())->withParsedBody(['templateUid' => true]);

        $response = $this->subject->generateBriefingAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function generateBriefingActionReturns500WhenDbThrows(): void
    {
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willThrowException(new RuntimeException('DB down'));

        $response = $this->subject->generateBriefingAction($this->createJsonRequest(['templateUid' => 1]));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function generatePageFieldsActionReturns500WhenDbThrows(): void
    {
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willThrowException(new RuntimeException('DB down'));

        $response = $this->subject->generatePageFieldsAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => [],
        ]));

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function generateContentActionReturns500WhenDbThrows(): void
    {
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willThrowException(new RuntimeException('DB down'));

        $response = $this->subject->generateContentAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => [],
        ]));

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function regenerateSectionActionReturns500WhenDbThrows(): void
    {
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willThrowException(new RuntimeException('DB down'));

        $response = $this->subject->regenerateSectionAction($this->createJsonRequest([
            'templateUid' => 1,
            'briefingAnswers' => [],
            'sectionIndex' => 0,
        ]));

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function saveActionAllowsAnyCTypeWhenAllowedCTypesIsEmpty(): void
    {
        $row = $this->templateRow();
        $row['allowed_ctypes'] = '';
        $this->mockLoadByUid($row);

        $this->pageCreatorService->expects(self::once())
            ->method('createLandingPage')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $sections): bool {
                    return $sections[0]['ctype'] === 'image'
                        && $sections[1]['ctype'] === 'custom_element';
                }),
            )
            ->willReturn(['pageUid' => 42, 'contentUids' => [100, 101]]);

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
            'title' => 'My Page',
            'slug' => '/my-page',
            'pageFields' => [],
            'contentSections' => [
                ['section' => 'Hero', 'ctype' => 'image', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
                ['section' => 'Custom', 'ctype' => 'custom_element', 'header' => 'C', 'subheader' => '', 'bodytext' => ''],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function optimizePromptActionReturnsOptimizedPrompt(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $this->promptOptimizerService->expects(self::once())
            ->method('generateOptimizedPrompt')
            ->willReturn('Optimized system prompt text');

        $response = $this->subject->optimizePromptAction($this->createJsonRequest(['templateUid' => 1]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Optimized system prompt text', $data['data']['prompt']);
    }

    #[Test]
    public function optimizePromptActionReturns400WhenNoTemplateUid(): void
    {
        $response = $this->subject->optimizePromptAction($this->createJsonRequest([]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('templateUid', $data['error']);
    }

    #[Test]
    public function optimizePromptActionReturns400WhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);

        $response = $this->subject->optimizePromptAction($this->createJsonRequest(['templateUid' => 999]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Template not found', $data['error']);
    }

    #[Test]
    public function optimizePromptActionReturnsErrorWithMessageWhenServiceThrows(): void
    {
        $this->mockLoadByUid($this->templateRow());

        $this->promptOptimizerService->method('generateOptimizedPrompt')
            ->willThrowException(new RuntimeException('LLM failed'));

        $response = $this->subject->optimizePromptAction($this->createJsonRequest(['templateUid' => 1]));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame('LLM failed', $data['error']);
    }

    #[Test]
    public function saveActionReplacesDisallowedCTypeWithText(): void
    {
        // Template allows 'text' and 'header' only
        $this->mockLoadByUid($this->templateRow());

        $this->pageCreatorService->expects(self::once())
            ->method('createLandingPage')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $sections): bool {
                    // The disallowed 'image' ctype should be replaced with 'text'
                    return $sections[0]['ctype'] === 'text'
                        && $sections[1]['ctype'] === 'header';
                }),
            )
            ->willReturn(['pageUid' => 42, 'contentUids' => [100, 101]]);

        $response = $this->subject->saveAction($this->createJsonRequest([
            'templateUid' => 1,
            'parentPageId' => 10,
            'title' => 'My Page',
            'slug' => '/my-page',
            'pageFields' => [],
            'contentSections' => [
                ['section' => 'Hero', 'ctype' => 'image', 'header' => 'H', 'subheader' => '', 'bodytext' => ''],
                ['section' => 'Intro', 'ctype' => 'header', 'header' => 'I', 'subheader' => '', 'bodytext' => ''],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    // ---- generateImageAction tests ----

    #[Test]
    public function generateImageActionReturnsBadRequestWhenTemplateUidMissing(): void
    {
        $request = $this->createJsonRequest([
            'imagePrompt' => 'A hero image',
            'sectionHeader' => 'Hero',
        ]);

        $response = $this->subject->generateImageAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function generateImageActionReturnsBadRequestWhenTemplateNotFound(): void
    {
        $this->mockLoadByUid(false);
        $request = $this->createJsonRequest([
            'templateUid' => 999,
            'imagePrompt' => 'A hero image',
            'sectionHeader' => 'Hero',
        ]);

        $response = $this->subject->generateImageAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function generateImageActionReturnsBadRequestWhenAiUnavailable(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->imageProviderService->method('isAiGenerationAvailable')->willReturn(false);

        $request = $this->createJsonRequest([
            'templateUid' => 1,
            'imagePrompt' => 'A hero image',
            'sectionHeader' => 'Hero',
        ]);

        $response = $this->subject->generateImageAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function generateImageActionReturns500WhenGenerationFails(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->imageProviderService->method('isAiGenerationAvailable')->willReturn(true);
        $this->imageProviderService->method('generateImageForSection')->willReturn(null);

        $request = $this->createJsonRequest([
            'templateUid' => 1,
            'imagePrompt' => 'A hero image',
            'sectionHeader' => 'Hero',
        ]);

        $response = $this->subject->generateImageAction($request);

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function generateImageActionReturnsImageOnSuccess(): void
    {
        $this->mockLoadByUid($this->templateRow());
        $this->imageProviderService->method('isAiGenerationAvailable')->willReturn(true);
        $imageData = [
            'uid' => 99,
            'name' => 'gen.png',
            'title' => 'Hero',
            'alternative' => 'AI-generated image: Hero',
            'publicUrl' => '/fileadmin/gen.png',
            'generated' => true,
        ];
        $this->imageProviderService->method('generateImageForSection')->willReturn($imageData);

        $request = $this->createJsonRequest([
            'templateUid' => 1,
            'imagePrompt' => 'A hero image',
            'sectionHeader' => 'Hero',
        ]);

        $response = $this->subject->generateImageAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame(99, $data['data']['image']['uid']);
    }

    // ---- searchImagesAction tests ----

    #[Test]
    public function searchImagesActionReturnsEmptyForEmptyQuery(): void
    {
        $request = $this->createJsonRequest(['query' => '']);

        $response = $this->subject->searchImagesAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame([], $data['data']['images']);
    }

    #[Test]
    public function searchImagesActionReturnsResultsForValidQuery(): void
    {
        $this->imageSearchService->method('extractKeywords')->willReturn(['office']);
        $this->imageSearchService->method('searchByKeywords')->willReturn([
            ['uid' => 5, 'name' => 'office.jpg', 'title' => 'Office', 'alternative' => '', 'publicUrl' => '/office.jpg'],
        ]);

        $request = $this->createJsonRequest(['query' => 'modern office']);

        $response = $this->subject->searchImagesAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['data']['images']);
        self::assertSame(5, $data['data']['images'][0]['uid']);
    }

    #[Test]
    public function searchImagesActionFallsBackToRawQueryWhenNoKeywordsExtracted(): void
    {
        $this->imageSearchService->method('extractKeywords')->willReturn([]);
        $this->imageSearchService->method('searchByKeywords')->with(['test'], 12)->willReturn([]);

        $request = $this->createJsonRequest(['query' => 'test']);

        $response = $this->subject->searchImagesAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame([], $data['data']['images']);
    }

    #[Test]
    public function generationInfoActionReturnsMetadata(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn([
            'tx_nrlandingpage_template_uid' => 5,
            'tx_nrlandingpage_briefing_data' => '{"title":"Test"}',
            'tx_nrlandingpage_config_hash' => 'abc123',
            'tx_nrlandingpage_generated_at' => 1700000000,
            'tx_nrlandingpage_source_page_uid' => 0,
            'pid' => 10,
        ]);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $response = $this->subject->generationInfoAction($this->createJsonRequest(['pageUid' => 42]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame(5, $data['data']['templateUid']);
        self::assertSame(['title' => 'Test'], $data['data']['briefingAnswers']);
        self::assertSame('abc123', $data['data']['configHash']);
        self::assertSame(1700000000, $data['data']['generatedAt']);
        self::assertSame(0, $data['data']['sourcePageUid']);
        self::assertSame(10, $data['data']['parentPageId']);
    }
}
