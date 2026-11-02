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
        // Compared by the English text the editor reads, not by label id:
        // the extension's "Access" next to core's "Access" are two ids.
        $texts = array_map($this->labelText(...), $matches[1]);

        self::assertNotSame([], $texts);
        self::assertSame(array_values(array_unique($texts)), $texts, 'Two tabs carry the same label: ' . implode(', ', $texts));
    }

    /**
     * The English source text of an LLL reference to this extension or to
     * the core.
     */
    private function labelText(string $reference): string
    {
        self::assertMatchesRegularExpression('/^LLL:EXT:(nr_landingpage|core)\\/.+\\.xlf:.+$/', $reference);
        [$path, $id] = explode(':', substr($reference, strlen('LLL:EXT:')), 2);
        [$extension, $file] = explode('/', $path, 2);
        $root = dirname(__DIR__, 3);
        $directory = $extension === 'core' ? $root . '/.Build/vendor/typo3/cms-core' : $root;
        $xml = simplexml_load_file($directory . '/' . $file);
        self::assertNotFalse($xml, $reference);
        $units = $xml->xpath(sprintf('//*[local-name()="trans-unit"][@id="%s"]/*[local-name()="source"]', $id));
        self::assertNotEmpty($units, $reference);

        return strtolower(trim((string) $units[0]));
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
    public function germanTextsUseGermanQuotationMarks(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/Resources/Private/Language/de.*.xlf') ?: [] as $file) {
            // A straight quote in a German text is either the closing mark
            // after „ or one of a straight pair; German uses „…“.
            preg_match_all('/<target>[^<]*"[^<]*<\/target>/u', (string) file_get_contents($file), $matches);
            self::assertSame([], $matches[0], basename($file) . ' has a straight quotation mark');
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
