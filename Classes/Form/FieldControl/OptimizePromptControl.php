<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Form\FieldControl;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Field control button that triggers the AI prompt optimizer.
 * Renders a button next to the system_prompt field that calls the
 * AJAX endpoint and updates the field value with the optimized prompt.
 */
class OptimizePromptControl extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        /** @var array<string, mixed> $parameterArray */
        $parameterArray = $this->data['parameterArray'] ?? [];
        $itemName = is_string($parameterArray['itemFormElName'] ?? null)
            ? $parameterArray['itemFormElName']
            : '';
        $id = StringUtility::getUniqueId('t3js-formengine-fieldcontrol-');

        /** @var array<string, mixed> $row */
        $row = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $rawUid = $row['uid'] ?? 0;
        $uid = is_int($rawUid) ? $rawUid : (is_string($rawUid) ? (int) $rawUid : 0);

        if ($uid === 0) {
            return [];
        }

        return [
            'iconIdentifier' => 'actions-rocket',
            'title' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldControl.optimizePrompt',
            'linkAttributes' => [
                'id' => $id,
                'data-item-name' => $itemName,
                'data-template-uid' => (string) $uid,
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create(
                    '@netresearch/nr-landingpage/form-engine/field-control/optimize-prompt.js',
                )->instance($id),
            ],
        ];
    }
}
