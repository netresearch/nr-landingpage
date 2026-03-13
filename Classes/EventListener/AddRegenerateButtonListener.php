<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\EventListener;

use Netresearch\NrLandingpage\Service\LandingPageDetectionService;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Adds a "Re-Generate" button to the Page module docheader
 * for pages that were created by the Landing Page Wizard.
 */
#[AsEventListener]
final class AddRegenerateButtonListener
{
    public function __construct(
        private readonly LandingPageDetectionService $detectionService,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $rawPageId = $request->getQueryParams()['id'] ?? 0;
        $pageId = is_numeric($rawPageId) ? (int) $rawPageId : 0;

        if ($pageId <= 0) {
            return;
        }

        if (!$this->detectionService->isGeneratedLandingPage($pageId)) {
            return;
        }

        $moduleUrl = (string) $this->uriBuilder->buildUriFromRoute('nr_landingpage', [
            'regeneratePageUid' => $pageId,
            'autoStartWizard' => 1,
        ]);

        $buttonBar = $event->getModuleTemplate()->getDocHeaderComponent()->getButtonBar();
        $button = $buttonBar->makeLinkButton()
            ->setHref($moduleUrl)
            ->setTitle($this->getLanguageService()->sL(
                'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang.xlf:contextMenu.regenerateLandingPage',
            ) ?: 'Re-Generate Landing Page')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-bolt', IconSize::SMALL));

        $buttonBar->addButton($button, ButtonBar::BUTTON_POSITION_LEFT, 4);
    }

    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        \assert($lang instanceof LanguageService);

        return $lang;
    }
}
