<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Form\FieldInformation;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Renders a description block for the system_prompt field controls
 * (Auto-optimize and Preview buttons).
 *
 * Displayed as fieldInformation above the textarea, giving users
 * context about what each button does before they click it.
 */
class PromptToolsDescription extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->initializeResultArray();

        $languageService = $GLOBALS['LANG'] ?? null;
        \assert($languageService instanceof LanguageService);

        $optimizeTitle = $languageService->sL(
            'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldInformation.promptTools.optimize.title',
        );
        $optimizeDesc = $languageService->sL(
            'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldInformation.promptTools.optimize.description',
        );
        $previewTitle = $languageService->sL(
            'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldInformation.promptTools.preview.title',
        );
        $previewDesc = $languageService->sL(
            'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldInformation.promptTools.preview.description',
        );

        // fieldInformation allows only: <a><br><div><em><i><p><strong><span><code>
        $result['html'] = '<div class="small" style="margin-bottom:0.5rem;">'
            . '<p style="margin-bottom:0.25rem;">'
            . '<strong>' . htmlspecialchars($optimizeTitle) . '</strong>'
            . '<br>'
            . '<span class="text-body-secondary">' . htmlspecialchars($optimizeDesc) . '</span>'
            . '</p>'
            . '<p style="margin-bottom:0;">'
            . '<strong>' . htmlspecialchars($previewTitle) . '</strong>'
            . '<br>'
            . '<span class="text-body-secondary">' . htmlspecialchars($previewDesc) . '</span>'
            . '</p>'
            . '</div>';

        return $result;
    }
}
