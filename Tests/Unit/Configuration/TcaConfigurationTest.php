<?php

declare(strict_types=1);

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
