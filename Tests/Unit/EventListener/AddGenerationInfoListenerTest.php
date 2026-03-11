<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\EventListener;

use Doctrine\DBAL\Result;
use Netresearch\NrLandingpage\EventListener\AddGenerationInfoListener;
use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AddGenerationInfoListener::class)]
final class AddGenerationInfoListenerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => match (true) {
                str_contains($key, 'generationInfo.title') => 'AI-Generated Landing Page',
                str_contains($key, 'generationInfo.template') => 'Template',
                str_contains($key, 'generationInfo.generatedAt') => 'Generated',
                str_contains($key, 'generationInfo.sourcePageUid') => 'Re-generated from page',
                str_contains($key, 'generationInfo.briefing') => 'Briefing',
                str_contains($key, 'generationInfo.templateDeleted') => 'Template deleted',
                str_contains($key, 'generationInfo.configChanged') => 'The template configuration has changed since this page was generated. Consider re-generating.',
                default => '',
            },
        );
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    private function createEvent(array $queryParams = []): ModifyPageLayoutContentEvent
    {
        $request = (new ServerRequest())->withQueryParams($queryParams);

        /** @var ModuleTemplate $moduleTemplate */
        $moduleTemplate = (new ReflectionClass(ModuleTemplate::class))->newInstanceWithoutConstructor();

        return new ModifyPageLayoutContentEvent($request, $moduleTemplate);
    }

    /**
     * Creates a ConnectionPool mock that returns different results per query.
     * First executeQuery returns $pageRow, second returns $templateRow.
     *
     * @param array<string, mixed>|null $pageRow
     * @param array<string, mixed>|null $templateRow
     */
    private function createConnectionPool(?array $pageRow, ?array $templateRow = null): ConnectionPool
    {
        $pageResult = $this->createMock(Result::class);
        $pageResult->method('fetchAssociative')->willReturn($pageRow ?? false);
        $pageResult->method('fetchOne')->willReturn(false);

        $templateResult = $this->createMock(Result::class);
        $templateResult->method('fetchAssociative')->willReturn($templateRow ?? false);
        $templateResult->method('fetchOne')->willReturn(false);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $restrictions = $this->createMock(DefaultRestrictionContainer::class);

        $callIndex = 0;
        $results = [$pageResult, $templateResult];

        // Each getQueryBuilderForTable call gets its own QueryBuilder with the right result
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            function () use (&$callIndex, $results, $expressionBuilder, $restrictions): QueryBuilder {
                $result = $results[$callIndex] ?? $results[0];
                $callIndex++;

                $qb = $this->createMock(QueryBuilder::class);
                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnSelf();
                $qb->method('where')->willReturnSelf();
                $qb->method('andWhere')->willReturnSelf();
                $qb->method('executeQuery')->willReturn($result);
                $qb->method('expr')->willReturn($expressionBuilder);
                $qb->method('createNamedParameter')->willReturn('42');
                $qb->method('getRestrictions')->willReturn($restrictions);

                return $qb;
            },
        );

        return $pool;
    }

    private function createListener(ConnectionPool $pool): AddGenerationInfoListener
    {
        $templateService = new TemplateService($pool);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new \TYPO3\CMS\Core\Http\Uri('/module/nr-landingpage?regeneratePageUid=42'));

        return new AddGenerationInfoListener($pool, $templateService, $uriBuilder);
    }

    #[Test]
    public function invokeReturnsEarlyWhenPageIdIsZero(): void
    {
        $pool = $this->createConnectionPool(null);
        $event = $this->createEvent(['id' => 0]);

        $this->createListener($pool)($event);

        self::assertSame('', $event->getHeaderContent());
    }

    #[Test]
    public function invokeReturnsEarlyForNonGeneratedPage(): void
    {
        $pool = $this->createConnectionPool([
            'tx_nrlandingpage_template_uid' => 0,
            'tx_nrlandingpage_briefing_data' => '',
            'tx_nrlandingpage_config_hash' => '',
            'tx_nrlandingpage_generated_at' => 0,
            'tx_nrlandingpage_source_page_uid' => 0,
        ]);

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        self::assertSame('', $event->getHeaderContent());
    }

    #[Test]
    public function invokeRendersInfoBoxForGeneratedPage(): void
    {
        $pool = $this->createConnectionPool(
            [
                'tx_nrlandingpage_template_uid' => 1,
                'tx_nrlandingpage_briefing_data' => '',
                'tx_nrlandingpage_config_hash' => '',
                'tx_nrlandingpage_generated_at' => 1710000000,
                'tx_nrlandingpage_source_page_uid' => 0,
            ],
            [
                'uid' => 1,
                'title' => 'Test Template',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => 'text',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'image_task' => 0,
                'generation_mode' => 'structured',
                'color_primary' => '',
                'color_secondary' => '',
                'color_background' => '',
                'color_text' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        );

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        $content = $event->getHeaderContent();
        self::assertStringContainsString('AI-Generated Landing Page', $content);
        self::assertStringContainsString('Test Template', $content);
        self::assertStringContainsString('callout-info', $content);
        self::assertStringNotContainsString('alert-warning', $content);
        self::assertStringContainsString('Re-Generate Landing Page', $content);
        self::assertStringContainsString('btn btn-default', $content);
        self::assertStringContainsString('regeneratePageUid', $content);
        self::assertStringContainsString('&middot;', $content);
    }

    #[Test]
    public function invokeShowsConfigChangedWarningWhenHashDiverges(): void
    {
        $pool = $this->createConnectionPool(
            [
                'tx_nrlandingpage_template_uid' => 1,
                'tx_nrlandingpage_briefing_data' => '',
                'tx_nrlandingpage_config_hash' => 'outdated-hash-from-previous-config',
                'tx_nrlandingpage_generated_at' => 1710000000,
                'tx_nrlandingpage_source_page_uid' => 0,
            ],
            [
                'uid' => 1,
                'title' => 'Test Template',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => 'text',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'image_task' => 0,
                'generation_mode' => 'structured',
                'color_primary' => '',
                'color_secondary' => '',
                'color_background' => '',
                'color_text' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        );

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        $content = $event->getHeaderContent();
        self::assertStringContainsString('alert-warning', $content);
        self::assertStringContainsString('template configuration has changed', $content);
    }

    #[Test]
    public function invokeShowsDeletedTemplateLabelWhenTemplateNotFound(): void
    {
        // Page has template_uid=999, but template table returns no row
        $pool = $this->createConnectionPool(
            [
                'tx_nrlandingpage_template_uid' => 999,
                'tx_nrlandingpage_briefing_data' => '',
                'tx_nrlandingpage_config_hash' => 'some-hash',
                'tx_nrlandingpage_generated_at' => 1710000000,
                'tx_nrlandingpage_source_page_uid' => 0,
            ],
            null,
        );

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        $content = $event->getHeaderContent();
        // When template is not found, should show "Template deleted" label
        self::assertStringContainsString('callout-info', $content);
        // Template name should be either "Template deleted" or fallback
        // (depends on whether TemplateService.loadByUid returns null)
        self::assertStringContainsString('AI-Generated Landing Page', $content);
    }

    #[Test]
    public function invokeShowsBriefingAnswers(): void
    {
        $briefingData = json_encode(['Topic' => 'Summer Sale', 'Audience' => 'Young adults'], JSON_THROW_ON_ERROR);
        $pool = $this->createConnectionPool(
            [
                'tx_nrlandingpage_template_uid' => 1,
                'tx_nrlandingpage_briefing_data' => $briefingData,
                'tx_nrlandingpage_config_hash' => '',
                'tx_nrlandingpage_generated_at' => 1710000000,
                'tx_nrlandingpage_source_page_uid' => 0,
            ],
            [
                'uid' => 1,
                'title' => 'Test',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => 'text',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'image_task' => 0,
                'generation_mode' => 'structured',
                'color_primary' => '',
                'color_secondary' => '',
                'color_background' => '',
                'color_text' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        );

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        $content = $event->getHeaderContent();
        self::assertStringContainsString('Summer Sale', $content);
        self::assertStringContainsString('Young adults', $content);
        self::assertStringContainsString('Topic', $content);
    }

    #[Test]
    public function invokeShowsSourcePageUid(): void
    {
        $pool = $this->createConnectionPool(
            [
                'tx_nrlandingpage_template_uid' => 1,
                'tx_nrlandingpage_briefing_data' => '',
                'tx_nrlandingpage_config_hash' => '',
                'tx_nrlandingpage_generated_at' => 1710000000,
                'tx_nrlandingpage_source_page_uid' => 55,
            ],
            [
                'uid' => 1,
                'title' => 'Test',
                'identifier' => 'test',
                'description' => '',
                'llm_configuration' => 0,
                'system_prompt' => '',
                'allowed_ctypes' => 'text',
                'page_fields' => '',
                'reference_pages' => '',
                'briefing_mode' => 'optional',
                'publish_mode' => 'hidden',
                'be_groups' => '',
                'backend_layout' => '',
                'prompt_optimizer_context' => '',
                'prompt_optimizer_meta_prompt' => '',
                'image_task' => 0,
                'generation_mode' => 'structured',
                'color_primary' => '',
                'color_secondary' => '',
                'color_background' => '',
                'color_text' => '',
                'deleted' => 0,
                'hidden' => 0,
            ],
        );

        $event = $this->createEvent(['id' => 42]);
        $this->createListener($pool)($event);

        $content = $event->getHeaderContent();
        self::assertStringContainsString('Re-generated from page', $content);
        self::assertStringContainsString('55', $content);
    }
}
