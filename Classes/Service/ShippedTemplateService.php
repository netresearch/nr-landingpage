<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;

/**
 * Imports the template records that ship with the extension
 * (Configuration/ShippedTemplates.php) as records on the root level.
 *
 * A record is created only when no record with its identifier exists, hidden
 * or deleted ones included, so the import never overwrites an edited template
 * and never brings back one an editor deleted.
 */
final readonly class ShippedTemplateService
{
    private const TABLE = 'tx_nrlandingpage_domain_model_template';

    private const REGISTRY_NAMESPACE = 'nr_landingpage';

    private const REGISTRY_KEY = 'shippedTemplatesImported';

    public function __construct(
        private ConnectionPool $connectionPool,
        private Registry $registry,
        private string $definitionFile = __DIR__ . '/../../Configuration/ShippedTemplates.php',
    ) {}

    /**
     * Identifiers of the shipped templates that have no record yet.
     *
     * @return list<string>
     */
    public function missingIdentifiers(): array
    {
        $shipped = array_column($this->definitions(), 'identifier');
        $existing = $this->existingIdentifiers();

        return array_values(array_diff($shipped, $existing));
    }

    /**
     * Create the missing records.
     *
     * @return list<string> identifiers of the records created
     */
    public function import(): array
    {
        $missing = $this->missingIdentifiers();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();
        $created = [];

        foreach ($this->definitions() as $definition) {
            if (!in_array($definition['identifier'], $missing, true)) {
                continue;
            }
            $connection->insert(self::TABLE, [...$definition, 'pid' => 0, 'tstamp' => $now, 'crdate' => $now]);
            $created[] = $definition['identifier'];
        }

        return $created;
    }

    /** Whether the import ran before: on extension setup or through the console command. */
    public function wasImported(): bool
    {
        return (bool) $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, false);
    }

    public function markImported(): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, true);
    }

    /**
     * @return list<array{identifier: string, title: string, ...<string, int|string>}>
     */
    private function definitions(): array
    {
        /** @var mixed $definitions */
        $definitions = require $this->definitionFile;
        if (!is_array($definitions)) {
            throw new RuntimeException('The shipped template definitions are not a list.', 1762992001);
        }

        $list = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || !is_string($definition['identifier'] ?? null) || !is_string($definition['title'] ?? null)) {
                throw new RuntimeException('A shipped template definition lacks its title or identifier.', 1762992002);
            }
            /** @var array{identifier: string, title: string, ...<string, int|string>} $definition */
            $list[] = $definition;
        }

        return $list;
    }

    /** @return list<string> */
    private function existingIdentifiers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Hidden records count, and so do deleted ones: see the class comment.
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('identifier')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->in(
                'identifier',
                $queryBuilder->createNamedParameter(array_column($this->definitions(), 'identifier'), Connection::PARAM_STR_ARRAY),
            ))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rows, is_string(...)));
    }
}
