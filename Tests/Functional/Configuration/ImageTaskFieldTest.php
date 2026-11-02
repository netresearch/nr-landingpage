<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The template field "image_task" offers image prompts only: the tasks an
 * integrator marked "Landing page: image prompt" in nr-llm, after the
 * media-library entry, and the task a template already holds.
 */
final class ImageTaskFieldTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $tasks = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_task');
        $tasks->insert('tx_nrllm_task', ['uid' => 1, 'pid' => 0, 'identifier' => 'translate-de', 'name' => 'Translate to German', 'placements' => '']);
        $tasks->insert('tx_nrllm_task', ['uid' => 2, 'pid' => 0, 'identifier' => 'lp-photo', 'name' => 'Landing page photo', 'placements' => 'landingpage_image']);
        $tasks->insert('tx_nrllm_task', ['uid' => 3, 'pid' => 0, 'identifier' => 'summarize', 'name' => 'Summarize', 'placements' => 'ai_tasks']);
    }

    #[Test]
    public function thePlaceIsOfferedOnTheTaskRecord(): void
    {
        $values = array_column($GLOBALS['TCA']['tx_nrllm_task']['columns']['placements']['config']['items'], 'value');

        self::assertContains('landingpage_image', $values);
    }

    #[Test]
    public function theFieldOffersTheMediaLibraryFirstAndThenOnlyMarkedImagePrompts(): void
    {
        self::assertSame(
            [[0, 'none'], ['--div--', 'nrllm_placement'], [2, 'nrllm_placement']],
            $this->valuesAndGroups($this->itemsOfTheEditForm(0)),
        );
    }

    #[Test]
    public function theFieldKeepsAnUnmarkedTaskATemplateAlreadyHolds(): void
    {
        self::assertSame(
            [[0, 'none'], ['--div--', 'nrllm_placement'], [2, 'nrllm_placement'], ['--div--', 'nrllm_unmarked'], [1, 'nrllm_unmarked']],
            $this->valuesAndGroups($this->itemsOfTheEditForm(1)),
        );
    }

    #[Test]
    public function theFieldDeclaresALabelForEachGroup(): void
    {
        $groups = $GLOBALS['TCA']['tx_nrlandingpage_domain_model_template']['columns']['image_task']['config']['itemGroups'];

        self::assertSame(['nrllm_placement', 'nrllm_unmarked'], array_keys($groups));
    }

    /**
     * The items of the field as FormEngine builds them for the edit form of
     * a template that holds the given task: foreign table, static item and
     * itemsProcFunc, run by the core's own data providers. The core puts the
     * ungrouped "Media Library only" first and opens each group with a
     * `--div--` header.
     *
     * @return list<mixed>
     */
    private function itemsOfTheEditForm(int $current): array
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->getConnectionPool()->getConnectionForTable('tx_nrlandingpage_domain_model_template')->insert(
            'tx_nrlandingpage_domain_model_template',
            ['uid' => 7, 'pid' => 0, 'title' => 'T', 'identifier' => 't', 'image_task' => $current],
        );

        $request = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));
        $result = $this->get(FormDataCompiler::class)->compile(
            ['request' => $request, 'tableName' => 'tx_nrlandingpage_domain_model_template', 'vanillaUid' => 7, 'command' => 'edit'],
            $this->get(TcaDatabaseRecord::class),
        );

        return array_values($result['processedTca']['columns']['image_task']['config']['items']);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function valuesAndGroups(array $items): array
    {
        return array_map(
            static fn(mixed $item): array => $item instanceof SelectItem
                ? [$item->getValue(), $item->getGroup()]
                : [is_array($item) ? ($item['value'] ?? null) : null, is_array($item) ? ($item['group'] ?? null) : null],
            $items,
        );
    }
}
