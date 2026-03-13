<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderContext;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Resolves column position metadata from TYPO3 backend layouts.
 *
 * Extracts colPos → name mappings from BackendLayout definitions
 * so the LLM can decide which column to place content elements in.
 */
class BackendLayoutService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    /**
     * BackendLayoutView is injected to ensure data providers are registered
     * in the shared DataProviderCollection singleton before we use it.
     * BackendLayoutView's constructor registers the DefaultDataProvider
     * and any custom providers from TYPO3_CONF_VARS.
     *
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    public function __construct(
        private readonly DataProviderCollection $dataProviderCollection,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly ConnectionPool $connectionPool,
        /** @phpstan-ignore property.onlyWritten (Injected to trigger provider registration in its constructor) */
        private readonly BackendLayoutView $backendLayoutView,
    ) {}

    /**
     * Get column descriptions for a backend layout identifier.
     *
     * Returns a map of colPos number to column name, e.g.:
     *   [0 => "Main Content", 1 => "Sidebar", 2 => "Footer"]
     *
     * @return array<int, string> colPos → column name
     */
    public function getColumnMap(string $backendLayoutIdentifier, int $pageId = 0): array
    {
        if ($backendLayoutIdentifier === '') {
            return [0 => 'Main'];
        }

        $layout = $this->dataProviderCollection->getBackendLayout($backendLayoutIdentifier, $pageId);

        // PageTSconfig-based layouts (pagets__*) need a real page context.
        // If pageId=0 failed, find any page that uses this layout as context.
        if (!$layout instanceof BackendLayout && $pageId === 0) {
            $resolvedPageId = $this->findPageWithLayout($backendLayoutIdentifier);
            if ($resolvedPageId > 0) {
                $layout = $this->dataProviderCollection->getBackendLayout($backendLayoutIdentifier, $resolvedPageId);
            }
        }

        if (!$layout instanceof BackendLayout) {
            $this->logger?->warning('Could not resolve backend layout, falling back to single column', [
                'identifier' => $backendLayoutIdentifier,
                'pageId' => $pageId,
            ]);
            return [0 => 'Main'];
        }

        $columns = $layout->getUsedColumns();
        if ($columns === []) {
            $this->logger?->info('Backend layout has no columns defined, falling back to single column', [
                'identifier' => $backendLayoutIdentifier,
            ]);
            return [0 => 'Main'];
        }

        $languageService = $this->getLanguageService();

        // getUsedColumns() returns [colPos => label], where label may be an LLL reference.
        $result = [];
        foreach ($columns as $colPos => $label) {
            $resolved = is_string($label) ? $this->resolveLabel($label, $languageService) : '';
            $result[(int) $colPos] = $resolved !== '' ? $resolved : 'Column ' . $colPos;
        }

        return $result;
    }

    /**
     * Format column map as a human-readable string for LLM prompts.
     *
     * Returns one line per column with colPos number and name.
     * Returns empty string when only a single column exists (no layout decision needed).
     *
     * @param array<int, string> $columnMap
     */
    public function formatColumnMapForPrompt(array $columnMap): string
    {
        if (count($columnMap) <= 1) {
            return '';
        }

        $lines = [];
        foreach ($columnMap as $colPos => $name) {
            $lines[] = '- colPos ' . $colPos . ': "' . $name . '"';
        }

        return implode("\n", $lines);
    }

    /**
     * Find any page that uses the given backend layout, so we have a page context
     * for resolving PageTSconfig-based layouts when no pageId was provided.
     */
    private function findPageWithLayout(string $identifier): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->or(
                    $qb->expr()->eq('backend_layout', $qb->createNamedParameter($identifier)),
                    $qb->expr()->eq('backend_layout_next_level', $qb->createNamedParameter($identifier)),
                ),
                $qb->expr()->eq('deleted', 0),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int) ($row['uid'] ?? 0) : 0;
    }

    private function resolveLabel(string $label, LanguageService $languageService): string
    {
        if ($label === '') {
            return '';
        }

        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }

        $translated = $languageService->sL($label);

        return $translated !== '' ? $translated : $label;
    }

    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            return $lang;
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $user = $beUser instanceof \TYPO3\CMS\Core\Authentication\AbstractUserAuthentication ? $beUser : null;

        return $this->languageServiceFactory->createFromUserPreferences($user);
    }
}
