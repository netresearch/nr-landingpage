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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageCreatorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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

        foreach ($pageFields as $field => $value) {
            if (is_string($field) && is_string($value) && $value !== '') {
                $data[$field] = $value;
            }
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
     * Factory method to allow mocking in tests.
     */
    protected function createDataHandler(): DataHandler
    {
        return GeneralUtility::makeInstance(DataHandler::class);
    }
}
