<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Controller\Backend;

use Netresearch\NrLandingpage\Domain\Model\GenerationContext;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use Netresearch\NrLandingpage\Service\ImageProviderService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\PromptOptimizerService;
use Netresearch\NrLandingpage\Service\TemplateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;

final class LandingPageWizardController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ?ModuleTemplate $moduleTemplate = null;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
        private readonly TemplateService $templateService,
        private readonly BriefingService $briefingService,
        private readonly ContentGeneratorService $contentGeneratorService,
        private readonly ImageSearchService $imageSearchService,
        private readonly ImageProviderService $imageProviderService,
        private readonly PageCreatorService $pageCreatorService,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly CreativeHtmlSanitizer $creativeHtmlSanitizer,
        private readonly ?PromptOptimizerService $promptOptimizerService = null,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->initializeAction($request);
        \assert($this->moduleTemplate instanceof ModuleTemplate);

        $queryParams = $request->getQueryParams();
        $rawParentPageId = $queryParams['parentPageId'] ?? 0;
        $parentPageId = is_numeric($rawParentPageId) ? (int) $rawParentPageId : 0;
        $rawRegeneratePageUid = $queryParams['regeneratePageUid'] ?? 0;
        $regeneratePageUid = is_numeric($rawRegeneratePageUid) ? (int) $rawRegeneratePageUid : 0;
        $autoStartWizard = ($queryParams['autoStartWizard'] ?? 0) === '1' || ($queryParams['autoStartWizard'] ?? 0) === 1;

        $templates = $this->templateService->loadForUser();
        $templateData = array_map(
            fn($template): array => [
                'uid' => $template->uid,
                'title' => $template->title,
                'identifier' => $template->identifier,
                'description' => $template->description,
                'briefingMode' => $template->briefingMode,
                'ctypeCount' => count($template->allowedCTypes),
                'hasSystemPrompt' => $template->systemPrompt !== '',
                'hasReferencePages' => $template->hasReferencePages(),
                'backendLayout' => $template->backendLayout,
                'editUrl' => (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['tx_nrlandingpage_domain_model_template' => [$template->uid => 'edit']],
                    'returnUrl' => (string) $request->getAttribute('normalizedParams')?->getRequestUri(),
                ]),
            ],
            $templates,
        );

        $this->moduleTemplate->assign('parentPageId', $parentPageId);
        $this->moduleTemplate->assign('regeneratePageUid', $regeneratePageUid);
        $this->moduleTemplate->assign('autoStartWizard', $autoStartWizard);
        $this->moduleTemplate->assign('templates', $templateData);
        $this->moduleTemplate->assign('createTemplateUrl', (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_nrlandingpage_domain_model_template' => [0 => 'new']],
            'returnUrl' => (string) $request->getAttribute('normalizedParams')?->getRequestUri(),
        ]));

        return $this->moduleTemplate->renderResponse('Backend/LandingPageWizard/Index');
    }

    public function templatesAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $templates = $this->templateService->loadForUser();

            $data = array_map(
                static fn($template): array => [
                    'uid' => $template->uid,
                    'title' => $template->title,
                    'identifier' => $template->identifier,
                    'description' => $template->description,
                    'briefingMode' => $template->briefingMode,
                ],
                $templates,
            );

            return new JsonResponse(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function generateBriefingAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            $questions = $this->briefingService->generateQuestions($template);

            return new JsonResponse(['success' => true, 'data' => $questions]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function generatePageFieldsAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $briefingAnswers = $this->extractArrayFromBody($body, 'briefingAnswers');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            /** @var array<string, string> $stringAnswers */
            $stringAnswers = array_map(strval(...), array_filter($briefingAnswers, is_string(...)));

            $parentPageId = $this->extractIntFromBody($body, 'parentPageId');
            $outputLanguage = $this->resolveOutputLanguage($parentPageId);

            $pageFields = $this->contentGeneratorService->generatePageFields($template, $stringAnswers, $outputLanguage);

            return new JsonResponse(['success' => true, 'data' => $pageFields]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function generateContentAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $briefingAnswers = $this->extractArrayFromBody($body, 'briefingAnswers');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            /** @var array<string, string> $stringAnswers */
            $stringAnswers = array_map(strval(...), array_filter($briefingAnswers, is_string(...)));

            $parentPageId = $this->extractIntFromBody($body, 'parentPageId');
            $outputLanguage = $this->resolveOutputLanguage($parentPageId);
            $template = $template->withResolvedColors($this->resolveColorDefaults($parentPageId));

            $contentSections = $this->contentGeneratorService->generateContent($template, $stringAnswers, $outputLanguage, $parentPageId);

            $images = $this->imageProviderService->resolveImagesForSections($template, $contentSections);
            $imageErrors = $this->imageProviderService->getImageErrors();

            return new JsonResponse(['success' => true, 'data' => [
                'sections' => $contentSections,
                'images' => $images,
                'imageErrors' => $imageErrors,
                'hasImageTask' => $template->hasImageTask(),
                'aiGenerationAvailable' => $this->imageProviderService->isAiGenerationAvailable(),
                'generationMode' => $template->generationMode,
            ]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function regenerateSectionAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $briefingAnswers = $this->extractArrayFromBody($body, 'briefingAnswers');
            $sectionIndex = $this->extractIntFromBody($body, 'sectionIndex', -1);

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            if ($sectionIndex < 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid sectionIndex'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            /** @var array<string, string> $stringAnswers */
            $stringAnswers = array_map(strval(...), array_filter($briefingAnswers, is_string(...)));

            $parentPageId = $this->extractIntFromBody($body, 'parentPageId');
            $outputLanguage = $this->resolveOutputLanguage($parentPageId);
            $template = $template->withResolvedColors($this->resolveColorDefaults($parentPageId));

            // Regenerate ALL content and return only the section at the given index.
            // The LLM cannot regenerate just one section — we regenerate all and pick the one the user wanted refreshed.
            $contentSections = $this->contentGeneratorService->generateContent($template, $stringAnswers, $outputLanguage, $parentPageId);

            if (!isset($contentSections[$sectionIndex])) {
                return new JsonResponse(['success' => false, 'error' => 'Section index out of range'], 400);
            }

            return new JsonResponse(['success' => true, 'data' => $contentSections[$sectionIndex]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function generateImageAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $imagePrompt = $this->extractStringFromBody($body, 'imagePrompt');
            $sectionHeader = $this->extractStringFromBody($body, 'sectionHeader');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            if (!$this->imageProviderService->isAiGenerationAvailable()) {
                return new JsonResponse(['success' => false, 'error' => 'AI image generation is not configured'], 400);
            }

            $image = $this->imageProviderService->generateImageForSection($template, $imagePrompt, $sectionHeader);
            if ($image === null) {
                return new JsonResponse(['success' => false, 'error' => 'Image generation failed. Please try again.'], 500);
            }

            return new JsonResponse(['success' => true, 'data' => ['image' => $image]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function searchImagesAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $query = $this->extractStringFromBody($body, 'query');

            if ($query === '') {
                return new JsonResponse(['success' => true, 'data' => ['images' => []]]);
            }

            $keywords = $this->imageSearchService->extractKeywords($query);
            if ($keywords === []) {
                $keywords = [trim($query)];
            }

            $images = $this->imageSearchService->searchByKeywords($keywords, 12);

            return new JsonResponse(['success' => true, 'data' => ['images' => $images]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $parentPageId = $this->extractIntFromBody($body, 'parentPageId');
            $title = $this->extractStringFromBody($body, 'title');
            $slug = $this->extractStringFromBody($body, 'slug');
            $pageFields = $this->extractArrayFromBody($body, 'pageFields');
            $contentSections = $this->extractArrayFromBody($body, 'contentSections');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            if ($parentPageId === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid parentPageId'], 400);
            }

            if ($title === '') {
                return new JsonResponse(['success' => false, 'error' => 'Missing title'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            /** @var array<string, string> $stringPageFields */
            $stringPageFields = array_map(strval(...), array_filter($pageFields, is_string(...)));

            $allowedCTypes = $template->allowedCTypes;

            /** @var list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}> $typedSections */
            $typedSections = [];
            foreach ($contentSections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $ctype = is_string($section['ctype'] ?? null) ? $section['ctype'] : '';
                // Creative mode always uses CType "html" — skip allowlist enforcement
                if (!$template->isCreativeMode() && $allowedCTypes !== [] && $ctype !== '' && !in_array($ctype, $allowedCTypes, true)) {
                    $ctype = 'text';
                }
                $rawImageUid = $section['imageUid'] ?? 0;
                $imageUid = is_numeric($rawImageUid) ? (int) $rawImageUid : 0;

                $rawColPos = $section['colPos'] ?? 0;
                $colPos = is_numeric($rawColPos) ? (int) $rawColPos : 0;

                $bodytext = is_string($section['bodytext'] ?? null) ? $section['bodytext'] : '';
                // Re-sanitize creative mode content at save time (editors can edit source in wizard)
                if ($template->isCreativeMode()) {
                    $bodytext = $this->creativeHtmlSanitizer->sanitize($bodytext);
                }

                $typedSections[] = [
                    'section' => is_string($section['section'] ?? null) ? $section['section'] : '',
                    'ctype' => $ctype,
                    'header' => is_string($section['header'] ?? null) ? $section['header'] : '',
                    'subheader' => is_string($section['subheader'] ?? null) ? $section['subheader'] : '',
                    'bodytext' => $bodytext,
                    'imageUid' => $imageUid,
                    'colPos' => $colPos,
                ];
            }

            $briefingAnswers = $this->extractArrayFromBody($body, 'briefingAnswers');
            $rawSourcePageUid = $body['sourcePageUid'] ?? 0;
            $sourcePageUid = is_numeric($rawSourcePageUid) ? (int) $rawSourcePageUid : 0;

            $generationContext = new GenerationContext(
                briefingAnswers: $briefingAnswers,
                sourcePageUid: $sourcePageUid,
            );

            /** @var array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}> $animations */
            $animations = array_values(array_map(
                static fn(mixed $section): array => is_array($section) && is_array($section['animation'] ?? null)
                    ? $section['animation']
                    : [],
                $contentSections,
            ));

            $result = $this->pageCreatorService->createLandingPage(
                $template,
                $parentPageId,
                $title,
                $slug,
                $stringPageFields,
                $typedSections,
                $generationContext,
                $animations,
            );

            return new JsonResponse(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function optimizePromptAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            if ($this->promptOptimizerService === null) {
                return new JsonResponse(['success' => false, 'error' => 'Prompt optimizer service not available'], 500);
            }

            $optimizedPrompt = $this->promptOptimizerService->generateOptimizedPrompt(
                $template->withResolvedColors([]),
            );

            return new JsonResponse(['success' => true, 'data' => ['prompt' => $optimizedPrompt]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Test content generation from the template record edit form.
     * Runs LLM + image resolution with a user-provided sample title
     * and returns the result for inline preview.
     */
    public function testGenerateAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $templateUid = $this->extractIntFromBody($body, 'templateUid');
            $sampleTitle = $this->extractStringFromBody($body, 'sampleTitle');

            if ($templateUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing or invalid templateUid'], 400);
            }

            if ($sampleTitle === '') {
                return new JsonResponse(['success' => false, 'error' => 'Please provide a sample title/topic'], 400);
            }

            $template = $this->templateService->loadByUid($templateUid);
            if ($template === null) {
                return new JsonResponse(['success' => false, 'error' => 'Template not found'], 400);
            }

            $template = $template->withResolvedColors([]);
            $briefingAnswers = ['title' => $sampleTitle];
            $contentSections = $this->contentGeneratorService->generateContent($template, $briefingAnswers);
            $images = $this->imageProviderService->resolveImagesForSections($template, $contentSections);
            $imageErrors = $this->imageProviderService->getImageErrors();

            return new JsonResponse(['success' => true, 'data' => [
                'sections' => $contentSections,
                'images' => $images,
                'imageErrors' => $imageErrors,
                'aiGenerationAvailable' => $this->imageProviderService->isAiGenerationAvailable(),
                'generationMode' => $template->generationMode,
            ]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function generationInfoAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->parseRequestBody($request);
            $pageUid = $this->extractIntFromBody($body, 'pageUid');

            if ($pageUid === 0) {
                return new JsonResponse(['success' => false, 'error' => 'Missing pageUid'], 400);
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeByType(
                \TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction::class,
            );
            $row = $queryBuilder
                ->select(
                    'tx_nrlandingpage_template_uid',
                    'tx_nrlandingpage_briefing_data',
                    'tx_nrlandingpage_config_hash',
                    'tx_nrlandingpage_generated_at',
                    'tx_nrlandingpage_source_page_uid',
                    'pid',
                )
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($row === false) {
                return new JsonResponse(['success' => false, 'error' => 'Page not found'], 404);
            }

            $briefingData = is_string($row['tx_nrlandingpage_briefing_data'] ?? null)
                ? $row['tx_nrlandingpage_briefing_data']
                : '';
            $briefingAnswers = $briefingData !== '' ? json_decode($briefingData, true, 512, JSON_THROW_ON_ERROR) : [];

            $templateUid = $row['tx_nrlandingpage_template_uid'] ?? 0;
            $configHash = $row['tx_nrlandingpage_config_hash'] ?? '';
            $generatedAt = $row['tx_nrlandingpage_generated_at'] ?? 0;
            $sourcePageUid = $row['tx_nrlandingpage_source_page_uid'] ?? 0;
            $parentPageId = $row['pid'] ?? 0;

            // Follow source_page_uid chain to find briefing data from ancestor pages
            // (covers pages created before briefing_data storage was implemented)
            if ($briefingAnswers === [] && is_numeric($sourcePageUid) && (int) $sourcePageUid > 0) {
                $ancestorUid = (int) $sourcePageUid;
                $visited = [$pageUid];
                $maxDepth = 10;
                while ($ancestorUid > 0 && !in_array($ancestorUid, $visited, true) && $maxDepth-- > 0) {
                    $visited[] = $ancestorUid;
                    $ancestorQb = $this->connectionPool->getQueryBuilderForTable('pages');
                    $ancestorQb->getRestrictions()->removeByType(
                        \TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction::class,
                    );
                    $ancestorRow = $ancestorQb
                        ->select('tx_nrlandingpage_briefing_data', 'tx_nrlandingpage_source_page_uid')
                        ->from('pages')
                        ->where($ancestorQb->expr()->eq('uid', $ancestorQb->createNamedParameter($ancestorUid, \Doctrine\DBAL\ParameterType::INTEGER)))
                        ->executeQuery()
                        ->fetchAssociative();

                    if ($ancestorRow === false) {
                        break;
                    }

                    $ancestorBriefing = is_string($ancestorRow['tx_nrlandingpage_briefing_data'] ?? null)
                        ? $ancestorRow['tx_nrlandingpage_briefing_data']
                        : '';
                    if ($ancestorBriefing !== '') {
                        $briefingAnswers = json_decode($ancestorBriefing, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($briefingAnswers) && $briefingAnswers !== []) {
                            break;
                        }
                        $briefingAnswers = [];
                    }

                    $nextUid = $ancestorRow['tx_nrlandingpage_source_page_uid'] ?? 0;
                    $ancestorUid = is_numeric($nextUid) ? (int) $nextUid : 0;
                }
            }

            return new JsonResponse(['success' => true, 'data' => [
                'templateUid' => is_numeric($templateUid) ? (int) $templateUid : 0,
                'briefingAnswers' => is_array($briefingAnswers) ? $briefingAnswers : [],
                'configHash' => is_string($configHash) ? $configHash : '',
                'generatedAt' => is_numeric($generatedAt) ? (int) $generatedAt : 0,
                'sourcePageUid' => is_numeric($sourcePageUid) ? (int) $sourcePageUid : 0,
                'parentPageId' => is_numeric($parentPageId) ? (int) $parentPageId : 0,
            ]]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function initializeAction(ServerRequestInterface $request): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);

        $this->pageRenderer->addInlineSettingArray('NrLandingpage', [
            'ajaxUrls' => [
                'templates' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_templates'),
                'generateBriefing' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_generate_briefing'),
                'generatePageFields' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_generate_page_fields'),
                'generateContent' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_generate_content'),
                'regenerateSection' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_regenerate_section'),
                'generateImage' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_generate_image'),
                'searchImages' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_search_images'),
                'save' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_save'),
                'generationInfo' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_generation_info'),
                'optimizePrompt' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nr_landingpage_optimize_prompt'),
            ],
            'moduleUrls' => [
                'editRecord' => (string) $this->uriBuilder->buildUriFromRoute('record_edit'),
                'pageLayout' => (string) $this->uriBuilder->buildUriFromRoute('web_layout'),
            ],
        ]);

        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_landingpage/Resources/Private/Language/locallang.xlf',
            'wizard.',
            '',
        );

        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-landingpage/wizard.js');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        $content = (string) $request->getBody();
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractStringFromBody(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractIntFromBody(array $body, string $key, int $default = 0): int
    {
        $value = $body[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) || is_float($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string|int, mixed>
     */
    private function extractArrayFromBody(array $body, string $key): array
    {
        $value = $body[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Resolve the default language title from the site configuration for a given page.
     *
     * Returns e.g. "Deutsch", "English", "Français" — or empty string if unresolvable.
     */
    private function resolveOutputLanguage(int $parentPageId): string
    {
        if ($parentPageId <= 0) {
            return '';
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($parentPageId);
            $defaultLanguage = $site->getDefaultLanguage();

            // Prefer the human-readable title (e.g. "Deutsch"), fall back to locale
            $title = $defaultLanguage->getTitle();
            if ($title !== '') {
                return $title;
            }

            return $defaultLanguage->getLocale()->getName();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Read color defaults from site settings for a given page.
     *
     * @return array{colorPrimary?: string, colorSecondary?: string, colorBackground?: string, colorText?: string}
     */
    private function resolveColorDefaults(int $parentPageId): array
    {
        if ($parentPageId <= 0) {
            return [];
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($parentPageId);
            $settings = $site->getSettings();
            if ($settings->isEmpty()) {
                return [];
            }

            $defaults = [];
            $map = [
                'colorPrimary' => 'nr_landingpage.colorPrimary',
                'colorSecondary' => 'nr_landingpage.colorSecondary',
                'colorBackground' => 'nr_landingpage.colorBackground',
                'colorText' => 'nr_landingpage.colorText',
            ];
            foreach ($map as $key => $settingKey) {
                if ($settings->has($settingKey)) {
                    /** @var string $value */
                    $value = $settings->get($settingKey);
                    if ($value !== '') {
                        $defaults[$key] = $value;
                    }
                }
            }

            return $defaults;
        } catch (Throwable) {
            return [];
        }
    }

    private function errorResponse(Throwable $e): ResponseInterface
    {
        $this->logger?->error('Wizard action failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return new JsonResponse([
            'success' => false,
            'error' => $e->getMessage() ?: 'An internal error occurred. Please try again.',
        ], 500);
    }
}
