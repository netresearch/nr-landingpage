<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Controller;

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\BackendLayoutService;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\CTypeMetadataService;
use Netresearch\NrLandingpage\Service\ImageProviderService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\PromptOptimizerService;
use Netresearch\NrLandingpage\Service\SectionNormalizer;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The site of the wizard's parent page reaches every LLM call of the wizard, and through it the configuration that site names in
 * `nrllm.configuration` (nr-llm ADR-244).
 *
 * Everything is real except the two nr-llm entry points, which record what
 * they are called with: the site settings, the page tree, the template and
 * configuration records, and nr-llm's own ConfigurationResolver, which turns
 * the recorded site into the configuration the call runs on.
 */
#[CoversClass(LandingPageWizardController::class)]
#[CoversClass(ContentGeneratorService::class)]
#[CoversClass(BriefingService::class)]
#[CoversClass(PromptOptimizerService::class)]
final class SiteConfigurationTest extends FunctionalTestCase
{
    private const SITE = 'shop';
    private const PAGE_IN_SITE = 2;
    private const PAGE_OUTSIDE_SITES = 3;
    private const TEMPLATE_ON_DEFAULT = 1;
    private const TEMPLATE_WITH_CONFIGURATION = 2;
    private const CREATIVE_TEMPLATE_ON_DEFAULT = 3;

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    /** @var list<ChatOptions> Options of every call on the installation default */
    private array $defaultCalls = [];

    /** @var list<array{configuration: string, metadata: array<string, mixed>}> Every call on a named configuration */
    private array $configurationCalls = [];

    private LandingPageWizardController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SiteConfiguration/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SiteConfiguration/tx_nrlandingpage_domain_model_template.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SiteConfiguration/tx_nrllm_configuration.csv');
        $this->writeSite();
        $this->setUpBackendUser(1);

        $this->subject = $this->buildController();
    }

    #[Test]
    public function contentOfATemplateOnTheDefaultRunsOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('generateContentAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame(self::SITE, $this->defaultCalls[0]->getSite());
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function creativeContentOfATemplateOnTheDefaultRunsOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('generateContentAction', self::CREATIVE_TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function aRegeneratedSectionRunsOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('regenerateSectionAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE, ['sectionIndex' => 0]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function pageFieldsOfATemplateOnTheDefaultRunOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('generatePageFieldsAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function briefingQuestionsOfATemplateOnTheDefaultRunOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('generateBriefingAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function anOptimizedPromptForAPageRunsOnTheConfigurationTheSiteNames(): void
    {
        $response = $this->call('optimizePromptAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertSame('shop-writer', $this->configurationNrLlmRunsOn($this->defaultCalls[0]));
    }

    #[Test]
    public function aConfigurationChosenOnTheTemplateWinsAndTheSiteStillTravels(): void
    {
        $response = $this->call('generateContentAction', self::TEMPLATE_WITH_CONFIGURATION, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->defaultCalls);
        self::assertCount(1, $this->configurationCalls);
        self::assertSame('template-writer', $this->configurationCalls[0]['configuration']);
        self::assertSame(self::SITE, $this->configurationCalls[0]['metadata'][ProviderCallContext::METADATA_SITE] ?? null);
    }

    #[Test]
    public function briefingQuestionsOnTheTemplatesConfigurationCarryTheSite(): void
    {
        $response = $this->call('generateBriefingAction', self::TEMPLATE_WITH_CONFIGURATION, self::PAGE_IN_SITE);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->configurationCalls);
        self::assertSame(self::SITE, $this->configurationCalls[0]['metadata'][ProviderCallContext::METADATA_SITE] ?? null);
    }

    #[Test]
    public function aPageOutsideEverySiteKeepsTheInstallationDefault(): void
    {
        $response = $this->call('generateContentAction', self::TEMPLATE_ON_DEFAULT, self::PAGE_OUTSIDE_SITES);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->defaultCalls);
        self::assertNull($this->defaultCalls[0]->getSite());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableSiteConfigurations(): array
    {
        return [
            'page fields, missing configuration' => ['generatePageFieldsAction', 'no-such-configuration'],
            'page fields, disabled configuration' => ['generatePageFieldsAction', 'disabled-writer'],
            'briefing, missing configuration' => ['generateBriefingAction', 'no-such-configuration'],
            'briefing, disabled configuration' => ['generateBriefingAction', 'disabled-writer'],
        ];
    }

    #[Test]
    #[DataProvider('unusableSiteConfigurations')]
    public function aSiteNamingAnUnusableConfigurationStopsTheStepWithTheReason(string $action, string $configuration): void
    {
        $this->writeSite($configuration);
        $this->subject = $this->buildController(passThroughToNrLlm: true);

        $response = $this->call($action, self::TEMPLATE_ON_DEFAULT, self::PAGE_IN_SITE);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString($configuration, (string) $response->getBody());
    }

    /**
     * @param array<string, int> $extra
     */
    private function call(string $action, int $templateUid, int $parentPageId, array $extra = []): ResponseInterface
    {
        $body = ['templateUid' => $templateUid, 'parentPageId' => $parentPageId, 'briefingAnswers' => ['topic' => 'Summer sale']] + $extra;
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        /** @var ServerRequestInterface $request */
        $request = (new ServerRequest())->withBody($stream)->withHeader('Content-Type', 'application/json');

        $response = $this->subject->{$action}($request);
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * The configuration nr-llm's chat()/complete() run on for these options,
     * resolved the way LlmServiceManager resolves it for a call without a
     * pinned provider.
     */
    private function configurationNrLlmRunsOn(ChatOptions $options): string
    {
        $configuration = $this->get(ConfigurationResolver::class)->resolveDefaultConfiguration(null, $options->getSite());

        return $configuration instanceof LlmConfiguration ? $configuration->getIdentifier() : '(installation default)';
    }

    /**
     * A site on page 1 that names an LLM configuration, written in the nested
     * form and depending on nr-llm's set, as the nr-llm manual describes it.
     */
    private function writeSite(string $configuration = 'shop-writer'): void
    {
        $directory = Environment::getConfigPath() . '/sites/' . self::SITE;
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'https://shop.example/'",
            'dependencies:',
            '  - netresearch/nr-llm',
            'languages:',
            '  - title: English',
            '    enabled: true',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '',
        ]));
        GeneralUtility::writeFile($directory . '/settings.yaml', implode("\n", [
            'nrllm:',
            '  configuration: ' . $configuration,
            '',
        ]));

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->getCache('core')->flush();
        $cacheManager->getCache('runtime')->flush();
    }

    /**
     * @param bool $passThroughToNrLlm Let a call on the installation default
     *                                 resolve its configuration the way nr-llm
     *                                 does, so a configuration it cannot use
     *                                 fails as it would in production.
     */
    private function buildController(bool $passThroughToNrLlm = false): LandingPageWizardController
    {
        $completionService = $this->createMock(CompletionServiceInterface::class);
        $completionService->method('completeJson')->willReturnCallback(
            function (string $prompt, ?ChatOptions $options = null) use ($passThroughToNrLlm): array {
                $options ??= new ChatOptions();
                $this->defaultCalls[] = $options;
                if ($passThroughToNrLlm) {
                    $this->get(ConfigurationResolver::class)->resolveDefaultConfiguration(null, $options->getSite());
                }

                return $this->answer();
            },
        );
        $completionService->method('complete')->willReturnCallback(
            function (string $prompt, ?ChatOptions $options = null): CompletionResponse {
                $this->defaultCalls[] = $options ?? new ChatOptions();

                return $this->completion('An optimized prompt');
            },
        );

        $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmServiceManager->method('chatWithConfiguration')->willReturnCallback(
            function (array $messages, LlmConfiguration $configuration, array $metadata = []): CompletionResponse {
                $this->configurationCalls[] = ['configuration' => $configuration->getIdentifier(), 'metadata' => $metadata];

                return $this->completion((string) json_encode($this->answer(), JSON_THROW_ON_ERROR));
            },
        );

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $backendLayoutService = $this->createMock(BackendLayoutService::class);
        $backendLayoutService->method('getColumnMap')->willReturn([0 => 'Main']);
        $cTypeMetadataService = $this->createMock(CTypeMetadataService::class);
        $cTypeMetadataService->method('buildCTypeDescription')->willReturn('');

        /** @var ModuleTemplateFactory $moduleTemplateFactory AJAX actions never render a module */
        $moduleTemplateFactory = (new ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor();
        /** @var UriBuilder $uriBuilder */
        $uriBuilder = (new ReflectionClass(UriBuilder::class))->newInstanceWithoutConstructor();

        $imageSearchService = $this->createMock(ImageSearchService::class);

        return new LandingPageWizardController(
            $moduleTemplateFactory,
            $this->createMock(PageRenderer::class),
            $uriBuilder,
            new TemplateService($connectionPool),
            new BriefingService($completionService, $llmServiceManager, $configurationRepository),
            new ContentGeneratorService($completionService, $llmServiceManager, $configurationRepository, $cTypeMetadataService, $backendLayoutService, new CreativeHtmlSanitizer()),
            $imageSearchService,
            $this->createMock(ImageProviderService::class),
            $this->createMock(PageCreatorService::class),
            $connectionPool,
            GeneralUtility::makeInstance(SiteFinder::class),
            new SectionNormalizer($imageSearchService, new CreativeHtmlSanitizer()),
            $this->get(BackendAccessGuard::class),
            new PromptOptimizerService($completionService, $llmServiceManager, $configurationRepository, $backendLayoutService),
        );
    }

    /**
     * One answer that every step accepts: a section, page fields and a question.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'sections' => [['section' => 'Hero', 'ctype' => 'text', 'colPos' => 0, 'header' => 'Sale', 'bodytext' => '<p>Now</p>']],
            'title' => 'Summer sale',
            'questions' => [['id' => 'topic', 'label' => 'Topic', 'type' => 'text']],
        ];
    }

    private function completion(string $content): CompletionResponse
    {
        return new CompletionResponse(content: $content, model: 'test-model', usage: new UsageStatistics(1, 1, 2));
    }
}
