<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Command;

use Netresearch\NrLandingpage\Service\ShippedTemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the template records that ship with the extension that are missing.
 *
 * Extension setup does this once; the command repeats it, for example after an
 * editor removed a shipped template for good and wants it back. A template that
 * exists is not changed, also not when it is hidden or deleted.
 */
#[AsCommand(
    name: 'nr-landingpage:import-templates',
    description: 'Create the landing page templates that ship with the extension and are missing.',
)]
final class ImportShippedTemplatesCommand extends Command
{
    public function __construct(
        private readonly ShippedTemplateService $shippedTemplates,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->shippedTemplates->import();
        $this->shippedTemplates->markImported();

        if ($created === []) {
            $output->writeln('All shipped templates exist already; nothing created.');

            return Command::SUCCESS;
        }

        $output->writeln('Created: ' . implode(', ', $created));

        return Command::SUCCESS;
    }
}
