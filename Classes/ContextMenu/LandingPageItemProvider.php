<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\ContextMenu;

use Netresearch\NrLandingpage\Service\TemplateService;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class LandingPageItemProvider extends AbstractProvider
{
    /** @var array<string, array{type: string, label: string, iconIdentifier: string, callbackAction: string}> */
    protected $itemsConfiguration = [
        'landingPageCreate' => [
            'type' => 'item',
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang.xlf:contextMenu.createLandingPage',
            'iconIdentifier' => 'nr-landingpage-module',
            'callbackAction' => 'createLandingPage',
        ],
    ];

    private ?TemplateService $templateService = null;

    protected function initialize(): void
    {
        $this->initDisabledItems();
    }

    public function canHandle(): bool
    {
        return $this->table === 'pages';
    }

    public function getPriority(): int
    {
        return 50;
    }

    protected function canRender(string $itemName, string $type): bool
    {
        if ($itemName !== 'landingPageCreate') {
            return false;
        }

        if (in_array($itemName, $this->disabledItems, true)) {
            return false;
        }

        return $this->getTemplateService()->loadForUser() !== [];
    }

    /**
     * @return array<string, string>
     */
    protected function getAdditionalAttributes(string $itemName): array
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $moduleUrl = (string) $uriBuilder->buildUriFromRoute('nr_landingpage', [
            'parentPageId' => $this->identifier,
        ]);

        return [
            // TODO: Phase 9 — create Resources/Public/JavaScript/context-menu-actions.js
            'data-callback-module' => '@netresearch/nr-landingpage/context-menu-actions',
            'data-navigate-uri' => $moduleUrl,
        ];
    }

    private function getTemplateService(): TemplateService
    {
        if ($this->templateService === null) {
            $this->templateService = GeneralUtility::getContainer()->get(TemplateService::class);
        }

        return $this->templateService;
    }

    /**
     * @internal Only for testing purposes
     */
    public function setTemplateService(TemplateService $templateService): void
    {
        $this->templateService = $templateService;
    }
}
