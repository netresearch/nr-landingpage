<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\EventListener\ImportShippedTemplatesListener;
use Netresearch\NrLandingpage\Service\ShippedTemplateService;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLandingpage\Upgrades\ImportShippedTemplatesWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ShippedTemplateService::class)]
#[CoversClass(ImportShippedTemplatesWizard::class)]
#[CoversClass(ImportShippedTemplatesListener::class)]
final class ShippedTemplateServiceTest extends FunctionalTestCase
{
    private const SHIPPED = ['product-launch', 'event', 'creative-one-pager', 'saas-feature'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    private ShippedTemplateService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->subject = $this->get(ShippedTemplateService::class);
    }

    #[Test]
    public function importCreatesTheFourTemplatesOnTheRootLevel(): void
    {
        self::assertSame(self::SHIPPED, $this->subject->missingIdentifiers());

        $created = $this->subject->import();

        self::assertSame(self::SHIPPED, $created);
        self::assertSame([], $this->subject->missingIdentifiers());
        $rows = $this->rows();
        self::assertSame(self::SHIPPED, array_column($rows, 'identifier'));
        foreach ($rows as $row) {
            self::assertSame(0, (int) $row['pid']);
            self::assertSame(0, (int) $row['hidden']);
            self::assertGreaterThan(0, (int) $row['crdate']);
        }
    }

    #[Test]
    public function importedTemplatesAreSelectableInTheModule(): void
    {
        $this->subject->import();
        $this->setUpBackendUser(1);

        $titles = array_map(
            static fn($template): string => $template->title,
            $this->get(TemplateService::class)->loadForUser(),
        );

        self::assertSame(['Product Launch', 'Event', 'Creative One-Pager', 'SaaS Feature'], $titles);
    }

    #[Test]
    public function creativeTemplateIsLoadedInCreativeMode(): void
    {
        $this->subject->import();
        $this->setUpBackendUser(1);

        $byIdentifier = [];
        foreach ($this->get(TemplateService::class)->loadForUser() as $template) {
            $byIdentifier[$template->identifier] = $template;
        }

        self::assertTrue($byIdentifier['creative-one-pager']->isCreativeMode());
        self::assertFalse($byIdentifier['event']->isCreativeMode());
        self::assertSame('required', $byIdentifier['event']->briefingMode);
        self::assertSame(['header', 'text', 'textmedia', 'bullets'], $byIdentifier['product-launch']->allowedCTypes);
    }

    #[Test]
    public function secondImportCreatesNothing(): void
    {
        $this->subject->import();

        self::assertSame([], $this->subject->import());
        self::assertCount(4, $this->rows());
    }

    #[Test]
    public function importNeitherOverwritesNorRestoresWhatAnEditorChanged(): void
    {
        $this->subject->import();
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrlandingpage_domain_model_template');
        $connection->update('tx_nrlandingpage_domain_model_template', ['title' => 'Our launch page'], ['identifier' => 'product-launch']);
        $connection->update('tx_nrlandingpage_domain_model_template', ['deleted' => 1], ['identifier' => 'event']);
        $connection->update('tx_nrlandingpage_domain_model_template', ['hidden' => 1], ['identifier' => 'saas-feature']);
        $connection->delete('tx_nrlandingpage_domain_model_template', ['identifier' => 'creative-one-pager']);

        self::assertSame(['creative-one-pager'], $this->subject->missingIdentifiers());
        self::assertSame(['creative-one-pager'], $this->subject->import());

        $titles = array_column($this->rows(), 'title', 'identifier');
        self::assertSame('Our launch page', $titles['product-launch']);
        self::assertCount(4, $titles);
    }

    #[Test]
    public function wizardIsNecessaryUntilTheImportRanAndRunsAgain(): void
    {
        $wizard = $this->get(ImportShippedTemplatesWizard::class);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertCount(4, $this->rows());
        self::assertFalse($wizard->updateNecessary());
        self::assertTrue($this->subject->wasImported());
    }

    #[Test]
    public function wizardIsNotNecessaryWhenAllTemplatesExist(): void
    {
        $this->subject->import();

        self::assertFalse($this->get(ImportShippedTemplatesWizard::class)->updateNecessary());
    }

    #[Test]
    public function setupOfTheExtensionImportsOnce(): void
    {
        $listener = $this->get(ImportShippedTemplatesListener::class);
        $package = $this->get(PackageManager::class)->getPackage('nr_landingpage');

        $listener(new PackageInitializationEvent(extensionKey: 'nr_landingpage', package: $package));
        self::assertCount(4, $this->rows());
        self::assertTrue($this->subject->wasImported());

        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrlandingpage_domain_model_template')
            ->delete('tx_nrlandingpage_domain_model_template', ['identifier' => 'event']);
        $listener(new PackageInitializationEvent(extensionKey: 'nr_landingpage', package: $package));
        self::assertCount(3, $this->rows(), 'a second setup does not bring back a template that was removed');
    }

    #[Test]
    public function setupOfAnotherExtensionImportsNothing(): void
    {
        $listener = $this->get(ImportShippedTemplatesListener::class);
        $package = $this->get(PackageManager::class)->getPackage('nr_llm');

        $listener(new PackageInitializationEvent(extensionKey: 'nr_llm', package: $package));

        self::assertSame([], $this->rows());
        self::assertFalse($this->subject->wasImported());
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_nrlandingpage_domain_model_template');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tx_nrlandingpage_domain_model_template')->orderBy('uid')->executeQuery()->fetchAllAssociative();
    }
}
