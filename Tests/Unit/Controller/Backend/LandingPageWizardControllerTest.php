<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Controller\Backend;

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLlm\Service\Feature\CompletionService;
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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(LandingPageWizardController::class)]
final class LandingPageWizardControllerTest extends UnitTestCase
{
    private CompletionService&MockObject $completionService;
    private ConnectionPool&MockObject $connectionPool;
    private PageCreatorService&MockObject $pageCreatorService;
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
        $briefingService = new BriefingService($this->completionService);
        $contentGeneratorService = new ContentGeneratorService($this->completionService);
        $imageSearchService = new ImageSearchService($this->connectionPool);

        $this->pageCreatorService = $this->createMock(PageCreatorService::class);

        $this->subject = new LandingPageWizardController(
            $moduleTemplateFactory,
            $pageRenderer,
            $uriBuilder,
            $templateService,
            $briefingService,
            $contentGeneratorService,
            $imageSearchService,
            $this->pageCreatorService,
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
        self::assertSame('DataHandler failed', $data['error']);
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
}
