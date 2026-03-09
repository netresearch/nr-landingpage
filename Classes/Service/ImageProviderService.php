<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use finfo;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Orchestrates image sourcing for landing page sections.
 *
 * When a template has no image task (imageTask = 0), only FAL search is used.
 * When an image task is configured, FAL is tried first and AI generation
 * is used as fallback. The task's prompt_template provides the image prompt.
 */
class ImageProviderService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_IMAGE_PROMPT = 'Professional high-resolution photograph for a corporate landing page section about "{section}". Style: modern, clean, bright natural lighting, shallow depth of field. No text overlays, no watermarks. Suitable as a website hero or section background image.';

    /** @var array<string, callable(string, array<string, mixed>): ImageGenerationResult> */
    private array $imageGenerators = [];

    /** @var array<int, string> */
    private array $promptTemplateCache = [];

    private string $lastImageError = '';

    /** @var list<string> */
    private array $imageErrors = [];

    public function __construct(
        private readonly ImageSearchService $imageSearchService,
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * Register an image generation backend.
     *
     * Availability is checked eagerly at registration time (once per request).
     *
     * @param callable(string, array<string, mixed>): ImageGenerationResult $generator
     */
    public function registerGenerator(string $name, callable $generator, callable $availabilityCheck): void
    {
        if ($availabilityCheck()) {
            $this->imageGenerators[$name] = $generator;
        }
    }

    /**
     * Resolve images for a set of content sections based on the template's image task config.
     *
     * @param list<array{section: string, header: string, imageKeywords: list<string>, imagePrompt: string}> $sections
     * @return list<list<array{uid: int, name: string, title: string, alternative: string, publicUrl: string, generated?: bool}>>
     */
    public function resolveImagesForSections(Template $template, array $sections): array
    {
        $this->imageErrors = [];
        $images = [];
        foreach ($sections as $section) {
            $this->lastImageError = '';
            $images[] = $this->resolveImagesForSection($template, $section);
            $this->imageErrors[] = $this->lastImageError;
        }

        return $images;
    }

    /**
     * Return per-section image generation errors from the last resolveImagesForSections call.
     *
     * @return list<string> One entry per section, empty string if no error.
     */
    public function getImageErrors(): array
    {
        return $this->imageErrors;
    }

    /**
     * Generate an AI image for a single section and store it in FAL.
     *
     * @return array{uid: int, name: string, title: string, alternative: string, publicUrl: string, generated: bool}|null
     */
    public function generateImageForSection(Template $template, string $imagePrompt, string $sectionHeader): ?array
    {
        $this->lastImageError = '';

        if ($this->imageGenerators === []) {
            $this->lastImageError = 'No image generation service available';
            $this->logger?->warning($this->lastImageError);
            return null;
        }

        $prompt = $this->buildImagePrompt($template, $imagePrompt, $sectionHeader);

        foreach ($this->imageGenerators as $name => $generator) {
            try {
                $result = $generator($prompt, [
                    'size' => '1024x1024',
                    'quality' => 'standard',
                ]);

                $stored = $this->storeGeneratedImage($result, $sectionHeader);
                if ($stored !== null) {
                    $this->lastImageError = '';
                    return $stored;
                }
            } catch (Throwable $e) {
                $this->lastImageError = 'Image generation failed';
                $this->logger?->error('Image generation failed with ' . $name, [
                    'error' => $e->getMessage(),
                    'section' => $sectionHeader,
                ]);
            }
        }

        return null;
    }

    /**
     * Check if any AI image generation backend is available.
     */
    public function isAiGenerationAvailable(): bool
    {
        return $this->imageGenerators !== [];
    }

    /**
     * Return the error message from the last failed image generation attempt.
     */
    public function getLastImageError(): string
    {
        return $this->lastImageError;
    }

    /**
     * Resolve images for a section: always searches FAL, and additionally
     * generates an AI image when the template has an image task configured
     * and a generator is available. Both sources are combined so the user
     * can choose from FAL results and/or AI-generated images.
     *
     * @param array{section: string, header: string, imageKeywords: list<string>, imagePrompt: string} $section
     * @return list<array{uid: int, name: string, title: string, alternative: string, publicUrl: string, generated?: bool, recommended?: bool}>
     */
    private function resolveImagesForSection(Template $template, array $section): array
    {
        $keywords = $section['imageKeywords'] ?? [];
        if ($keywords === []) {
            $keywords = $this->imageSearchService->extractKeywords($section['header'] ?? '');
        }

        // Always search FAL
        $falImages = $keywords !== [] ? $this->imageSearchService->searchByKeywords($keywords, 6) : [];

        // Mark the first FAL result as recommended when no AI generation is available
        if ($falImages !== [] && !$template->hasImageTask()) {
            $falImages[0]['recommended'] = true;
            return $falImages;
        }

        // When template has an image task and a generator is available, also generate an AI image
        $allImages = $falImages;
        if ($template->hasImageTask() && $this->imageGenerators !== []) {
            $generated = $this->generateImageForSection(
                $template,
                $section['imagePrompt'] ?? '',
                $section['header'] ?? $section['section'] ?? '',
            );
            if ($generated !== null) {
                $generated['recommended'] = true;
                // AI-generated images go first (recommended)
                array_unshift($allImages, $generated);
            } elseif ($allImages !== []) {
                // AI generation failed — recommend first FAL image
                $allImages[0]['recommended'] = true;
            }
        } elseif ($allImages !== []) {
            $allImages[0]['recommended'] = true;
        }

        return $allImages;
    }

    private function buildImagePrompt(Template $template, string $sectionPrompt, string $sectionHeader): string
    {
        if ($sectionPrompt !== '') {
            return $sectionPrompt;
        }

        $taskPromptTemplate = $this->loadTaskPromptTemplate($template->imageTask);
        $promptTemplate = $taskPromptTemplate !== ''
            ? $taskPromptTemplate
            : self::DEFAULT_IMAGE_PROMPT;

        return str_replace('{section}', $sectionHeader, $promptTemplate);
    }

    /**
     * Load the prompt_template from the referenced LLM task (cached per request).
     */
    private function loadTaskPromptTemplate(int $taskUid): string
    {
        if ($taskUid <= 0) {
            return '';
        }

        if (isset($this->promptTemplateCache[$taskUid])) {
            return $this->promptTemplateCache[$taskUid];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_task');
        $row = $queryBuilder
            ->select('prompt_template')
            ->from('tx_nrllm_task')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            $this->promptTemplateCache[$taskUid] = '';
            return '';
        }

        $template = is_string($row['prompt_template'] ?? null) ? $row['prompt_template'] : '';

        // nr-llm tasks use {{input}} as placeholder — map to {section} (case-insensitive, allows spaces)
        $result = preg_replace('/\{\{\s*input\s*\}\}/i', '{section}', $template) ?? $template;
        $this->promptTemplateCache[$taskUid] = $result;

        return $result;
    }

    /**
     * @return array{uid: int, name: string, title: string, alternative: string, publicUrl: string, generated: bool}|null
     */
    private function storeGeneratedImage(ImageGenerationResult $result, string $sectionHeader): ?array
    {
        $content = $result->getBinaryContent() ?? $result->downloadFromUrl();
        if ($content === null) {
            $this->logger?->error('Could not download generated image', ['url' => $result->url]);
            return null;
        }

        // Validate MIME type against TYPO3's configured allowed image extensions
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);
        if ($mimeType === false || !str_starts_with($mimeType, 'image/')) {
            $this->logger?->error('AI-generated content is not an image', [
                'mimeType' => $mimeType,
                'section' => $sectionHeader,
            ]);
            return null;
        }

        $ext = $this->resolveAllowedExtension($mimeType);
        if ($ext === null) {
            $this->logger?->error('AI-generated image type is not allowed by TYPO3 configuration', [
                'mimeType' => $mimeType,
                'section' => $sectionHeader,
                'allowedImageExtensions' => $this->getAllowedImageExtensions(),
            ]);
            return null;
        }

        $tempFile = null;
        try {
            $storage = $this->storageRepository->getDefaultStorage();
            if ($storage === null) {
                $this->logger?->error('No default storage available for storing generated image');
                return null;
            }

            $folderPath = 'generated-landingpage/';
            if (!$storage->hasFolder($folderPath)) {
                $storage->createFolder($folderPath);
            }
            $folder = $storage->getFolder($folderPath);
            $filename = 'lp-' . date('Ymd-His') . '-' . substr(md5($sectionHeader . uniqid()), 0, 8) . '.' . $ext;

            $tempFile = tempnam(sys_get_temp_dir(), 'lpimg_');
            if ($tempFile === false) {
                return null;
            }
            file_put_contents($tempFile, $content);

            /** @var File $file */
            $file = $storage->addFile($tempFile, $folder, $filename);

            // Clean up temp file in case the storage driver copied instead of moved
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            $metaData = $file->getMetaData();
            $metaData->offsetSet('title', mb_substr($sectionHeader, 0, 255));
            $metaData->offsetSet('alternative', 'AI-generated image: ' . mb_substr($sectionHeader, 0, 200));
            $metaData->save();

            $publicUrl = $file->getPublicUrl() ?? '';

            return [
                'uid' => $file->getUid(),
                'name' => $file->getName(),
                'title' => mb_substr($sectionHeader, 0, 255),
                'alternative' => 'AI-generated image: ' . mb_substr($sectionHeader, 0, 200),
                'publicUrl' => $publicUrl,
                'generated' => true,
            ];
        } catch (Throwable $e) {
            if ($tempFile !== null && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            $this->logger?->error('Failed to store generated image in FAL', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve a file extension for the given MIME type, respecting TYPO3's
     * configured allowed image extensions ($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']).
     *
     * Returns null if the MIME type does not map to any allowed extension.
     */
    private function resolveAllowedExtension(string $mimeType): ?string
    {
        $allowedExtensions = array_map(
            'trim',
            explode(',', $this->getAllowedImageExtensions()),
        );
        $allowedExtensions = array_filter($allowedExtensions, static fn(string $ext): bool => $ext !== '');

        $mimeTypeDetector = new MimeTypeDetector();
        $candidates = $mimeTypeDetector->getFileExtensionsForMimeType($mimeType);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowedExtensions, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Read the admin-configured list of allowed image file extensions.
     */
    private function getAllowedImageExtensions(): string
    {
        $conf = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $gfx = is_array($conf) ? ($conf['GFX'] ?? []) : [];

        return is_array($gfx) && is_string($gfx['imagefile_ext'] ?? null)
            ? $gfx['imagefile_ext']
            : '';
    }
}
