<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Form\FieldControl;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Field control button that triggers a test content generation.
 * Shows an inline preview of what the LLM produces with the current template config.
 */
class TestGenerateControl extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $id = StringUtility::getUniqueId('t3js-formengine-fieldcontrol-');

        /** @var array<string, mixed> $row */
        $row = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $rawUid = $row['uid'] ?? 0;
        $uid = is_int($rawUid) ? $rawUid : (is_string($rawUid) ? (int) $rawUid : 0);

        if ($uid === 0) {
            return [];
        }

        return [
            'iconIdentifier' => 'actions-play',
            'title' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldControl.testGenerate',
            'linkAttributes' => [
                'id' => $id,
                'data-template-uid' => (string) $uid,
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create(
                    '@netresearch/nr-landingpage/form-engine/field-control/test-generate.js',
                )->instance($id),
            ],
        ];
    }
}
