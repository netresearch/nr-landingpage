<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TcaConfigurationTest extends UnitTestCase
{
    private array $templateTca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateTca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_nrlandingpage_domain_model_template.php';
    }

    #[Test]
    public function llmConfigurationFieldUsesCorrectForeignTable(): void
    {
        $config = $this->templateTca['columns']['llm_configuration']['config'] ?? [];

        self::assertSame('select', $config['type'], 'llm_configuration should use type=select');
        self::assertSame('selectSingle', $config['renderType'], 'llm_configuration should use renderType=selectSingle');
        self::assertSame(
            'tx_nrllm_configuration',
            $config['foreign_table'],
            'llm_configuration must reference tx_nrllm_configuration (not tx_nrllm_domain_model_llmconfiguration)',
        );
    }

    #[Test]
    public function llmConfigurationFieldHasDefaultOption(): void
    {
        $config = $this->templateTca['columns']['llm_configuration']['config'] ?? [];

        self::assertSame(0, $config['default'], 'Default should be 0 (use default config)');
        self::assertNotEmpty($config['items'], 'Must have at least one item (the default option)');
        self::assertSame(0, $config['items'][0]['value'], 'First item must be the default (value=0)');
    }

    #[Test]
    public function everyTabOfTheFormHasItsOwnLabel(): void
    {
        $showitem = (string) ($this->templateTca['types']['1']['showitem'] ?? '');
        preg_match_all('/--div--;([^,]+)/', $showitem, $matches);
        // Labels that resolve to the same text, such as an extension's
        // "Access" next to core's, count as one.
        $texts = array_map(
            static fn(string $label): string => strtolower((string) preg_replace('/^tabs\./', '', substr($label, (int) strrpos($label, ':') + 1))),
            $matches[1],
        );

        self::assertNotSame([], $texts);
        self::assertSame(array_values(array_unique($texts)), $texts, 'Two tabs carry the same label: ' . implode(', ', $texts));
    }

    #[Test]
    public function staticItemsOfRecordSelectsHaveAnIcon(): void
    {
        // The records of the foreign table come with an icon, so the select
        // shows an icon box; a static item without one leaves it empty.
        foreach ($this->templateTca['columns'] as $fieldName => $fieldConfig) {
            $config = $fieldConfig['config'] ?? [];
            if (($config['renderType'] ?? '') !== 'selectSingle' || !isset($config['foreign_table'])) {
                continue;
            }
            foreach ($config['items'] ?? [] as $item) {
                self::assertNotSame('', (string) ($item['icon'] ?? ''), sprintf('Static item of "%s" has no icon', $fieldName));
            }
        }
    }

    #[Test]
    public function llmConfigurationIsLabelledAsAConfigurationInEveryLanguage(): void
    {
        $label = (string) $this->templateTca['columns']['llm_configuration']['label'];
        $description = (string) $this->templateTca['columns']['llm_configuration']['description'];
        $languageDir = dirname(__DIR__, 3) . '/Resources/Private/Language/';
        foreach (['locallang_db.xlf' => 'source', 'de.locallang_db.xlf' => 'target'] as $file => $element) {
            $xml = simplexml_load_file($languageDir . $file);
            self::assertNotFalse($xml);
            foreach ([$label, $description] as $reference) {
                $id = substr($reference, strrpos($reference, ':') + 1);
                $units = $xml->xpath(sprintf('//*[local-name()="trans-unit"][@id="%s"]/*[local-name()="%s"]', $id, $element));
                self::assertNotEmpty($units, $file . ': ' . $id);
                $text = (string) $units[0];
                self::assertMatchesRegularExpression('/configuration|Konfiguration/i', $text, $file . ': ' . $id);
                if ($reference === $label) {
                    // The description names the model as one part of a configuration.
                    self::assertDoesNotMatchRegularExpression('/\bmodel\b|\bModell\b/i', $text, $file . ': ' . $id);
                }
            }
        }
    }

    #[Test]
    public function germanQuotationMarksAreClosedTypographically(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/Resources/Private/Language/de.*.xlf') ?: [] as $file) {
            preg_match_all('/„[^“"\n<]*"/u', (string) file_get_contents($file), $matches);
            self::assertSame([], $matches[0], basename($file) . ' closes „ with a straight quote');
        }
    }

    #[Test]
    public function allColumnsHaveDescriptions(): void
    {
        $columns = $this->templateTca['columns'] ?? [];

        foreach ($columns as $fieldName => $fieldConfig) {
            if ($fieldName === 'hidden') {
                continue;
            }
            self::assertArrayHasKey(
                'description',
                $fieldConfig,
                sprintf('TCA column "%s" is missing a description for editors', $fieldName),
            );
        }
    }

    #[Test]
    public function foreignTableReferencesMatchExtTablesSql(): void
    {
        $nrLlmSql = file_get_contents(
            dirname(__DIR__, 3) . '/.Build/vendor/netresearch/nr-llm/ext_tables.sql',
        );
        self::assertIsString($nrLlmSql);

        $config = $this->templateTca['columns']['llm_configuration']['config'] ?? [];
        $foreignTable = $config['foreign_table'] ?? '';

        self::assertNotEmpty($foreignTable);
        self::assertStringContainsString(
            'CREATE TABLE ' . $foreignTable,
            $nrLlmSql,
            sprintf('foreign_table "%s" not found in nr-llm ext_tables.sql', $foreignTable),
        );
    }
}
