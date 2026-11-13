<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Upgrades;

use Netresearch\NrLandingpage\Service\ShippedTemplateService;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\RepeatableInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Creates the template records that ship with the extension in an installation
 * that had the extension before they existed. A new installation gets them on
 * extension setup. Run again, the wizard creates the ones that are missing.
 */
#[UpgradeWizard('nrLandingpageImportShippedTemplates')]
final readonly class ImportShippedTemplatesWizard implements UpgradeWizardInterface, RepeatableInterface
{
    public function __construct(
        private ShippedTemplateService $shippedTemplates,
    ) {}

    public function getTitle(): string
    {
        return 'Landing page templates: import the shipped templates';
    }

    public function getDescription(): string
    {
        return 'Creates the templates Product Launch, Event, Creative One-Pager and SaaS Feature on the root level, '
            . 'unless a template with the same identifier exists. Existing templates are not changed.';
    }

    public function executeUpdate(): bool
    {
        $this->shippedTemplates->import();
        $this->shippedTemplates->markImported();

        return true;
    }

    public function updateNecessary(): bool
    {
        return !$this->shippedTemplates->wasImported() && $this->shippedTemplates->missingIdentifiers() !== [];
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
