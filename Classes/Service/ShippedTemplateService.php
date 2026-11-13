<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use UnexpectedValueException;

/**
 * Imports the template records that ship with the extension
 * (Configuration/ShippedTemplates.json) as records on the root level.
 *
 * The file holds the shared "defaults" and the "templates"; a prompt is a list
 * of lines. The templates start without colours, so the colours of the site
 * settings apply, and the language of the generated text comes from the default
 * language of the site, so the prompts are English.
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
        private string $definitionFile = __DIR__ . '/../../Configuration/ShippedTemplates.json',
    ) {}

    /**
     * Identifiers of the shipped templates that have no record yet.
     *
     * @return list<string>
     */
    public function missingIdentifiers(): array
    {
        $shipped = array_map(static fn(array $row): string => (string) $row['identifier'], $this->definitions());
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
     * The shipped template rows, the shared defaults of the file applied.
     *
     * @return list<array<string, int|string>>
     */
    public function definitions(): array
    {
        $json = file_get_contents($this->definitionFile);
        $data = $json === false ? null : json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['defaults'] ?? null) || !is_array($data['templates'] ?? null)) {
            throw new UnexpectedValueException('The shipped template definitions need "defaults" and "templates".', 1762992001);
        }

        $list = [];
        foreach ($data['templates'] as $template) {
            if (!is_array($template) || !is_string($template['identifier'] ?? null) || !is_string($template['title'] ?? null)) {
                throw new UnexpectedValueException('A shipped template definition lacks its title or identifier.', 1762992002);
            }
            $row = [...$data['defaults'], ...$template];
            // A prompt is written as a list of lines.
            if (is_array($row['system_prompt'] ?? null)) {
                $row['system_prompt'] = implode("\n", array_filter($row['system_prompt'], is_string(...)));
            }
            /** @var array<string, int|string> $row */
            $list[] = $row;
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
                $queryBuilder->createNamedParameter(array_map(static fn(array $row): string => (string) $row['identifier'], $this->definitions()), Connection::PARAM_STR_ARRAY),
            ))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rows, is_string(...)));
    }
}
