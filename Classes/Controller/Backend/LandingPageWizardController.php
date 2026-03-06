<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Controller\Backend;

use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\TemplateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;

final class LandingPageWizardController
{
    private ?ModuleTemplate $moduleTemplate = null;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly TemplateService $templateService,
        private readonly BriefingService $briefingService,
        private readonly ContentGeneratorService $contentGeneratorService,
        private readonly ImageSearchService $imageSearchService,
        private readonly PageCreatorService $pageCreatorService,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->initializeAction($request);
        \assert($this->moduleTemplate instanceof ModuleTemplate);

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
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            // TODO: Pass $template->llmConfiguration to BriefingService when LlmConfiguration support is added
            $questions = $this->briefingService->generateQuestions($template);

            return new JsonResponse(['success' => true, 'data' => $questions]);
        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            // TODO: Pass $template->llmConfiguration to ContentGeneratorService when LlmConfiguration support is added
            $pageFields = $this->contentGeneratorService->generatePageFields($template, $stringAnswers);

            return new JsonResponse(['success' => true, 'data' => $pageFields]);
        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            // TODO: Pass $template->llmConfiguration to ContentGeneratorService when LlmConfiguration support is added
            $contentSections = $this->contentGeneratorService->generateContent($template, $stringAnswers);

            // Optionally search for images based on section headers
            $images = [];
            foreach ($contentSections as $section) {
                $keywords = $this->imageSearchService->extractKeywords($section['header']);
                if ($keywords !== []) {
                    $images[] = $this->imageSearchService->searchByKeywords($keywords, 3);
                }
            }

            return new JsonResponse(['success' => true, 'data' => [
                'sections' => $contentSections,
                'images' => $images,
            ]]);
        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            // Regenerate ALL content and return only the section at the given index.
            // The LLM cannot regenerate just one section — we regenerate all and pick the one the user wanted refreshed.
            // TODO: Pass $template->llmConfiguration to ContentGeneratorService when LlmConfiguration support is added
            $contentSections = $this->contentGeneratorService->generateContent($template, $stringAnswers);

            if (!isset($contentSections[$sectionIndex])) {
                return new JsonResponse(['success' => false, 'error' => 'Section index out of range'], 400);
            }

            return new JsonResponse(['success' => true, 'data' => $contentSections[$sectionIndex]]);
        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            /** @var list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}> $typedSections */
            $typedSections = [];
            foreach ($contentSections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $typedSections[] = [
                    'section' => is_string($section['section'] ?? null) ? $section['section'] : '',
                    'ctype' => is_string($section['ctype'] ?? null) ? $section['ctype'] : '',
                    'header' => is_string($section['header'] ?? null) ? $section['header'] : '',
                    'subheader' => is_string($section['subheader'] ?? null) ? $section['subheader'] : '',
                    'bodytext' => is_string($section['bodytext'] ?? null) ? $section['bodytext'] : '',
                ];
            }

            $result = $this->pageCreatorService->createLandingPage(
                $template,
                $parentPageId,
                $title,
                $slug,
                $stringPageFields,
                $typedSections,
            );

            return new JsonResponse(['success' => true, 'data' => $result]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function initializeAction(ServerRequestInterface $request): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);

        $this->pageRenderer->addInlineSettingArray('NrLandingpage', [
            'ajaxUrls' => [
                'templates' => '/ajax/nr-landingpage/wizard/templates',
                'generateBriefing' => '/ajax/nr-landingpage/wizard/generate-briefing',
                'generatePageFields' => '/ajax/nr-landingpage/wizard/generate-page-fields',
                'generateContent' => '/ajax/nr-landingpage/wizard/generate-content',
                'regenerateSection' => '/ajax/nr-landingpage/wizard/regenerate-section',
                'save' => '/ajax/nr-landingpage/wizard/save',
            ],
        ]);

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
}
