<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\ParentPageVerdict;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
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

    public const WORKSPACE_MODULE_IDENTIFIER = 'nr_landingpage_workspace';

    public const TEMPLATE_TABLE = 'tx_nrlandingpage_domain_model_template';

    public function __construct(
        private readonly ModuleProvider $moduleProvider,
        private readonly PageDoktypeRegistry $pageDoktypeRegistry,
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
     * Whether the current user has access to the content workspace module.
     */
    public function mayUseWorkspace(): bool
    {
        $backendUser = $this->getBackendUser();

        return $backendUser !== null
            && $this->moduleProvider->accessGranted(self::WORKSPACE_MODULE_IDENTIFIER, $backendUser);
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
        return $this->checkParentPage($pageId) === ParentPageVerdict::Allowed;
    }

    /**
     * Whether the current user may create a landing page below the given
     * page, and if not, why. The page checks DataHandler makes when it
     * inserts a page: the page is in the user's page tree (web mount and
     * "show"), the user may modify the pages table and has "create page" on
     * this page itself (hasNewPagePermission()), and the page's type holds
     * subpages. A page the user cannot see counts as not found.
     */
    public function checkParentPage(int $pageId): ParentPageVerdict
    {
        $page = $this->readablePage($pageId);
        $backendUser = $this->getBackendUser();
        if ($page === null || $backendUser === null) {
            return ParentPageVerdict::NotFound;
        }
        if (!$backendUser->check('tables_modify', 'pages') || !$this->hasNewPagePermission($page, $backendUser)) {
            return ParentPageVerdict::NoPermission;
        }
        $doktype = $page['doktype'] ?? 0;
        if (!$this->pageDoktypeRegistry->isRecordTypeAllowedForDoktype('pages', is_numeric($doktype) ? (int) $doktype : 0)) {
            return ParentPageVerdict::NoSubpages;
        }

        return ParentPageVerdict::Allowed;
    }

    /**
     * "Create page" on the page itself, computed the way
     * DataHandler::hasPageContextPermission() does in 13.4 and 14.3: from
     * the permissions for everybody, for the owner and for the owning group.
     * BackendUserAuthentication::calcPerms() is stricter (it grants nothing
     * to a user without a backend group), DataHandler is the authority.
     *
     * @param array<string, mixed> $page
     */
    private function hasNewPagePermission(array $page, BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }
        $permission = Permission::PAGE_NEW;
        $value = static fn(string $field): int => is_numeric($page[$field] ?? null) ? (int) $page[$field] : 0;
        $groups = array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $backendUser->userGroupsUID);

        return ($value('perms_everybody') & $permission) === $permission
            || ($value('perms_userid') === (int) $backendUser->getUserId() && ($value('perms_user') & $permission) === $permission)
            || ($groups !== [] && in_array($value('perms_groupid'), $groups, true) && ($value('perms_group') & $permission) === $permission);
    }

    /**
     * The title of the page, when the current user may see it.
     */
    public function readablePageTitle(int $pageId): ?string
    {
        $title = $this->readablePage($pageId)['title'] ?? null;

        return is_string($title) ? $title : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readablePage(int $pageId): ?array
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || $pageId <= 0) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($page) || ($page['uid'] ?? null) === null) {
            return null;
        }

        /** @var array<string, mixed> $page */
        return $page;
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
