<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Permission checks for the wizard and template AJAX endpoints.
 *
 * AJAX routes are reachable by every logged-in backend user, so each
 * endpoint asks this guard whether the current user may use the landing
 * page module, edit templates, or read/create pages below a given page.
 */
class BackendAccessGuard
{
    public const MODULE_IDENTIFIER = 'nr_landingpage';

    public const TEMPLATE_TABLE = 'tx_nrlandingpage_domain_model_template';

    public function __construct(
        private readonly ModuleProvider $moduleProvider,
    ) {}

    /**
     * Whether the current user has access to the landing page module.
     */
    public function mayUseWizard(): bool
    {
        $backendUser = $this->getBackendUser();

        return $backendUser !== null
            && $this->moduleProvider->accessGranted(self::MODULE_IDENTIFIER, $backendUser);
    }

    /**
     * Whether the current user may modify landing page template records.
     */
    public function mayEditTemplates(): bool
    {
        $backendUser = $this->getBackendUser();

        return $backendUser !== null
            && $backendUser->check('tables_modify', self::TEMPLATE_TABLE);
    }

    /**
     * Whether the current user may see the page with the given uid.
     */
    public function mayReadPage(int $pageId): bool
    {
        return $this->hasPagePermission($pageId, Permission::PAGE_SHOW);
    }

    /**
     * Whether the current user may create subpages below the given page.
     */
    public function mayCreatePageBelow(int $pageId): bool
    {
        return $this->hasPagePermission($pageId, Permission::PAGE_NEW);
    }

    private function hasPagePermission(int $pageId, int $permission): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || $pageId <= 0) {
            return false;
        }

        return BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause($permission)) !== false;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
