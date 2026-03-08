<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Form\FieldInformation;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shows how many pages were generated from this template.
 *
 * Rendered as fieldInformation on the template title field.
 * Performs a COUNT(*) query on pages.tx_nrlandingpage_template_uid.
 */
class GeneratedPageCount extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->initializeResultArray();

        /** @var array<string, mixed> $row */
        $row = $this->data['databaseRow'] ?? [];
        $rawUid = $row['uid'] ?? 0;
        $templateUid = is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($templateUid <= 0) {
            return $result;
        }

        $count = $this->countGeneratedPages($templateUid);

        if ($count === 0) {
            return $result;
        }

        $languageService = $GLOBALS['LANG'] ?? null;
        \assert($languageService instanceof LanguageService);

        $label = $languageService->sL(
            'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:fieldInformation.generatedPageCount',
        );

        if ($label === '') {
            $label = '%d page(s) generated with this template';
        }

        $result['html'] = '<div class="form-description text-body-secondary">'
            . htmlspecialchars(sprintf($label, $count))
            . '</div>';

        return $result;
    }

    private function countGeneratedPages(int $templateUid): int
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $count = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_nrlandingpage_template_uid',
                    $queryBuilder->createNamedParameter($templateUid, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
