<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
    ) {}

    /**
     * Create a landing page with content elements via DataHandler.
     *
     * @param array<string, string> $pageFields SEO and other page field values
     * @param list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}> $contentSections
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
    ): array {
        $pageData = $this->buildPageData($template, $parentPageId, $title, $slug, $pageFields);
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
     * @param list<array{section: string, ctype: string, header: string, subheader: string, bodytext: string}> $contentSections
     * @return list<array<string, mixed>>
     */
    private function buildContentElements(array $contentSections): array
    {
        $elements = [];
        foreach ($contentSections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $element = [
                'CType' => (string) ($section['ctype'] ?? 'text'),
                'header' => (string) ($section['header'] ?? ''),
                'colPos' => 0,
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
