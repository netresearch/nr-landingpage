<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\EventListener;

use Netresearch\NrLandingpage\Service\ShippedTemplateService;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;

/**
 * Creates the template records that ship with the extension when the extension
 * is set up, once. An installation that had the extension before gets them
 * through the upgrade wizard.
 */
#[AsEventListener]
final readonly class ImportShippedTemplatesListener
{
    public function __construct(
        private ShippedTemplateService $shippedTemplates,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PackageInitializationEvent $event): void
    {
        if ($event->getExtensionKey() !== 'nr_landingpage' || $this->shippedTemplates->wasImported()) {
            return;
        }

        try {
            $created = $this->shippedTemplates->import();
            $this->shippedTemplates->markImported();
        } catch (Throwable $e) {
            // Setup must not fail over sample records; the upgrade wizard offers the import again.
            $this->logger->warning('The shipped landing page templates were not imported', ['exception' => $e]);

            return;
        }

        if ($created !== []) {
            $this->logger->info('Imported the shipped landing page templates', ['identifiers' => $created]);
        }
    }
}
