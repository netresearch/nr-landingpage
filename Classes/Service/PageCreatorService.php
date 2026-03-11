<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\GenerationContext;
use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageCreatorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const RESERVED_PAGE_FIELDS = [
        'uid',
        'pid',
        'doktype',
        'hidden',
        'deleted',
        'sorting',
        'slug',
        'title',
        'TSconfig',
        'is_siteroot',
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
        'editlock',
        't3ver_oid',
        't3ver_wsid',
        't3ver_state',
        't3ver_stage',
    ];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Create a landing page with content elements via DataHandler.
     *
     * @param array<string, string> $pageFields SEO and other page field values
     * @param list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string, imageUid?: int, colPos?: int}> $contentSections
     * @return array{pageUid: int, contentUids: list<int>}
     * @throws RuntimeException if page creation fails
     */
    public function createLandingPage(
        Template $template,
        int $parentPageId,
        string $title,
        string $slug,
        array $pageFields,
        array $contentSections,
        ?GenerationContext $generationContext = null,
    ): array {
        $pageData = $this->buildPageData($template, $parentPageId, $title, $slug, $pageFields);
        $pageData = $this->addGenerationMetadata($pageData, $template, $generationContext);
        $contentElements = $this->buildContentElements($contentSections);

        /** @var BeforePageCreationEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new BeforePageCreationEvent($template, $parentPageId, $pageData, $contentElements),
        );

        $pageData = $event->pageData;
        $contentElements = $event->contentElements;

        $newPageId = 'NEW_page';
        $dataMap = [
            'pages' => [
                $newPageId => $pageData,
            ],
        ];

        $contentUidMap = [];
        foreach ($contentElements as $index => $element) {
            $newContentId = 'NEW_content_' . $index;
            $element['pid'] = $newPageId;
            $element['sorting'] = ($index + 1) * 256;

            $rawImageUid = $contentSections[$index]['imageUid'] ?? 0;
            $imageUid = is_int($rawImageUid) ? $rawImageUid : (is_numeric($rawImageUid) ? (int) $rawImageUid : 0);
            $ctype = is_string($element['CType'] ?? null) ? $element['CType'] : '';

            $imageField = $this->getImageFieldForCType($ctype);

            // Upgrade CType when an image is selected but current type has no image field
            if ($imageUid > 0 && $imageField === '') {
                $ctype = 'textpic';
                $element['CType'] = 'textpic';
                $imageField = 'image';
            }

            if ($imageUid > 0 && $imageField !== '') {
                $newRefId = 'NEW_ref_' . $index;
                $dataMap['sys_file_reference'][$newRefId] = [
                    'pid' => $newPageId,
                    'uid_local' => $imageUid,
                    'uid_foreign' => $newContentId,
                    'tablenames' => 'tt_content',
                    'fieldname' => $imageField,
                ];
                $element[$imageField] = $newRefId;
            }

            $dataMap['tt_content'][$newContentId] = $element;
            $contentUidMap[] = $newContentId;
        }

        $dataHandler = $this->createDataHandler();
        $workspaceId = $this->getCurrentWorkspaceId();
        if ($workspaceId > 0) {
            $this->logger?->info('Creating page in workspace', ['workspaceId' => $workspaceId]);
        }
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            $errors = implode(', ', $dataHandler->errorLog);
            $this->logger?->error('DataHandler errors during page creation', ['errors' => $errors]);
            throw new RuntimeException('Page creation failed: ' . $errors);
        }

        $rawPageUid = $dataHandler->substNEWwithIDs[$newPageId] ?? 0;
        \assert(is_int($rawPageUid) || is_string($rawPageUid));
        $pageUid = (int) $rawPageUid;
        if ($pageUid === 0) {
            throw new RuntimeException('Page creation failed: no UID returned');
        }

        $contentUids = [];
        foreach ($contentUidMap as $newId) {
            $rawUid = $dataHandler->substNEWwithIDs[$newId] ?? 0;
            \assert(is_int($rawUid) || is_string($rawUid));
            $uid = (int) $rawUid;
            if ($uid > 0) {
                $contentUids[] = $uid;
            }
        }

        $this->eventDispatcher->dispatch(
            new AfterContentGenerationEvent($template, $pageUid, $contentUids),
        );

        return ['pageUid' => $pageUid, 'contentUids' => $contentUids];
    }

    /**
     * @param array<string, string> $pageFields
     * @return array<string, mixed>
     */
    private function buildPageData(
        Template $template,
        int $parentPageId,
        string $title,
        string $slug,
        array $pageFields,
    ): array {
        $data = [
            'pid' => $parentPageId,
            'title' => $title,
            'slug' => $slug,
            'doktype' => 1,
            'hidden' => $template->publishMode === 'hidden' ? 1 : 0,
        ];

        if ($template->backendLayout !== '') {
            $data['backend_layout'] = $template->backendLayout;
            $data['backend_layout_next_level'] = $template->backendLayout;
        }

        $allowedFields = $template->pageFields;
        foreach ($pageFields as $field => $value) {
            if (!is_string($field) || !is_string($value) || $value === '') {
                continue;
            }
            if (in_array($field, self::RESERVED_PAGE_FIELDS, true)) {
                $this->logger?->warning('Blocked reserved page field', ['field' => $field]);
                continue;
            }
            if ($allowedFields !== [] && !in_array($field, $allowedFields, true)) {
                $this->logger?->warning('Blocked non-allowed page field', ['field' => $field]);
                continue;
            }
            $data[$field] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $pageData
     * @return array<string, mixed>
     */
    private function addGenerationMetadata(
        array $pageData,
        Template $template,
        ?GenerationContext $generationContext,
    ): array {
        $pageData['tx_nrlandingpage_template_uid'] = $template->uid;
        $pageData['tx_nrlandingpage_config_hash'] = $template->getConfigHash();
        $pageData['tx_nrlandingpage_generated_at'] = time();

        if ($generationContext !== null) {
            $pageData['tx_nrlandingpage_briefing_data'] = json_encode(
                $generationContext->briefingAnswers,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
            $pageData['tx_nrlandingpage_source_page_uid'] = $generationContext->sourcePageUid;

            // Re-generated pages are always hidden for editorial review
            if ($generationContext->sourcePageUid > 0) {
                $pageData['hidden'] = 1;
            }
        }

        return $pageData;
    }

    /**
     * @param list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string, imageUid?: int, colPos?: int}> $contentSections
     * @return list<array<string, mixed>>
     */
    private function buildContentElements(array $contentSections): array
    {
        $elements = [];
        foreach ($contentSections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $rawColPos = $section['colPos'] ?? 0;
            $colPos = is_int($rawColPos) ? $rawColPos : (is_numeric($rawColPos) ? (int) $rawColPos : 0);

            $element = [
                'CType' => (string) ($section['ctype'] ?? 'text'),
                'header' => (string) ($section['header'] ?? ''),
                'colPos' => $colPos,
            ];

            $subheader = (string) ($section['subheader'] ?? '');
            if ($subheader !== '') {
                $element['subheader'] = $subheader;
            }

            $bodytext = (string) ($section['bodytext'] ?? '');
            if ($bodytext !== '') {
                $element['bodytext'] = $bodytext;
            }

            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * Replace <img data-image-slot="0"> placeholder with a real image URL,
     * or remove it if no image was selected.
     */
    private function resolveImagePlaceholders(string $bodytext, int $imageUid): string
    {
        $pattern = '/<img\b[^>]*\bdata-image-slot="0"[^>]*>/i';

        if ($imageUid <= 0) {
            return preg_replace($pattern, '', $bodytext) ?? $bodytext;
        }

        try {
            $file = $this->resourceFactory->getFileObject($imageUid);
            $publicUrl = $file->getPublicUrl();
        } catch (\Throwable) {
            // File deleted or invalid — remove placeholder
            return preg_replace($pattern, '', $bodytext) ?? $bodytext;
        }

        if ($publicUrl === null || $publicUrl === '') {
            return preg_replace($pattern, '', $bodytext) ?? $bodytext;
        }

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use ($publicUrl): string {
                $tag = $matches[0];

                // Extract alt attribute if present
                $alt = '';
                if (preg_match('/\balt="([^"]*)"/', $tag, $altMatch)) {
                    $alt = $altMatch[1];
                }

                return '<img src="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8')
                    . '" alt="' . $alt . '">';
            },
            $bodytext,
        ) ?? $bodytext;
    }

    /**
     * Determine which field stores images/media for a given CType.
     *
     * Covers all standard TYPO3 v14 CTypes that support file references:
     * - textmedia: uses 'assets' (images, video, audio)
     * - textpic, image: uses 'image'
     * - uploads: uses 'media'
     */
    private function getImageFieldForCType(string $ctype): string
    {
        return match ($ctype) {
            'textmedia' => 'assets',
            'image', 'textpic' => 'image',
            'uploads' => 'media',
            default => '',
        };
    }

    /**
     * Get workspace ID from the current backend user.
     * DataHandler automatically respects BE_USER->workspace for versioning.
     */
    protected function getCurrentWorkspaceId(): int
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser instanceof BackendUserAuthentication) {
            return (int) $beUser->workspace;
        }

        return 0;
    }

    /**
     * Factory method to allow mocking in tests.
     */
    protected function createDataHandler(): DataHandler
    {
        return GeneralUtility::makeInstance(DataHandler::class);
    }
}
