<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(TemplateService::class)]
final class TemplateServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    private TemplateService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrlandingpage_domain_model_template.csv');
        $this->subject = $this->get(TemplateService::class);
    }

    #[Test]
    public function loadByUidReturnsTemplateForExistingRecord(): void
    {
        $template = $this->subject->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(1, $template->uid);
        self::assertSame('Product LP', $template->title);
        self::assertSame('product-lp', $template->identifier);
        self::assertSame('Product landing page template', $template->description);
        self::assertSame('optional', $template->briefingMode);
        self::assertSame(['text', 'textmedia'], $template->allowedCTypes);
        self::assertSame(['seo_title', 'description'], $template->pageFields);
    }

    #[Test]
    public function loadByUidReturnsNullForNonExistentRecord(): void
    {
        self::assertNull($this->subject->loadByUid(999));
    }

    #[Test]
    public function loadByUidReturnsNullForHiddenRecord(): void
    {
        self::assertNull($this->subject->loadByUid(3));
    }

    #[Test]
    public function loadByUidReturnsNullForDeletedRecord(): void
    {
        self::assertNull($this->subject->loadByUid(4));
    }

    #[Test]
    public function loadForUserReturnsAllAccessibleTemplatesForAdmin(): void
    {
        $this->setUpBackendUser(1);

        $templates = $this->subject->loadForUser();

        // Admin sees all non-hidden, non-deleted: uid 1 and 2
        self::assertCount(2, $templates);
        $uids = array_map(static fn($t) => $t->uid, $templates);
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    #[Test]
    public function loadForUserFiltersTemplatesByBeGroups(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['admin'] = 0;
        $backendUser->userGroupsUID = [1];

        $templates = $this->subject->loadForUser();

        // uid 1: no be_groups restriction -> accessible
        // uid 2: restricted to groups 1,2 -> accessible (user has group 1)
        self::assertCount(2, $templates);
    }

    #[Test]
    public function loadForUserExcludesRestrictedTemplatesForUnauthorizedUser(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->user['admin'] = 0;
        $backendUser->userGroupsUID = [99];

        $templates = $this->subject->loadForUser();

        // uid 1: no be_groups restriction -> accessible
        // uid 2: restricted to groups 1,2 -> NOT accessible (user has group 99)
        self::assertCount(1, $templates);
        self::assertSame(1, $templates[0]->uid);
    }

    #[Test]
    public function loadByUidReadsBackendLayout(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('pagets__default', $template->backendLayout);
    }

    #[Test]
    public function loadByUidDefaultsEmptyBackendLayout(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(2);

        self::assertNotNull($template);
        self::assertSame('', $template->backendLayout);
    }

    #[Test]
    public function loadByUidReadsPromptOptimizerContext(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame('Brand: Acme Corp', $template->promptOptimizerContext);
    }

    #[Test]
    public function loadByUidReadsPromptOptimizerMetaPrompt(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(2);

        self::assertNotNull($template);
        self::assertSame('Custom meta-prompt for events', $template->promptOptimizerMetaPrompt);
    }

    #[Test]
    public function loadByUidDefaultsEmptyPromptOptimizerFields(): void
    {
        $this->setUpBackendUser(1);

        // Template 2 has empty prompt_optimizer_context, template 1 has empty meta_prompt
        $template1 = $this->subject->loadByUid(1);
        self::assertNotNull($template1);
        self::assertSame('', $template1->promptOptimizerMetaPrompt);

        $template2 = $this->subject->loadByUid(2);
        self::assertNotNull($template2);
        self::assertSame('', $template2->promptOptimizerContext);
    }

    #[Test]
    public function loadByUidParsesBeGroupsCorrectly(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(2);

        self::assertNotNull($template);
        self::assertSame([1, 2], $template->beGroups);
        self::assertSame('required', $template->briefingMode);
        self::assertSame('visible', $template->publishMode);
    }

    /**
     * Proves ext_tables.sql includes all columns (Bug 4: "Unknown column" on INSERT).
     * If any column is missing from ext_tables.sql, this test fails with a DB error.
     */
    #[Test]
    public function databaseSchemaSupportsAllTemplateColumns(): void
    {
        $connection = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrlandingpage_domain_model_template');

        $connection->insert('tx_nrlandingpage_domain_model_template', [
            'title' => 'Schema Test',
            'identifier' => 'schema-test',
            'description' => 'Verifies all columns exist',
            'llm_configuration' => 0,
            'system_prompt' => 'Test prompt',
            'allowed_ctypes' => 'text,header',
            'page_fields' => 'seo_title',
            'reference_pages' => '',
            'briefing_mode' => 'optional',
            'publish_mode' => 'hidden',
            'be_groups' => '',
            'backend_layout' => 'pagets__default',
            'prompt_optimizer_context' => 'Brand: Test',
            'prompt_optimizer_meta_prompt' => 'Custom meta',
            'image_task' => 42,
        ]);

        $this->setUpBackendUser(1);

        // Verify the record can be read back
        $row = $connection->select(
            ['*'],
            'tx_nrlandingpage_domain_model_template',
            ['identifier' => 'schema-test'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('Schema Test', $row['title']);
        self::assertSame('pagets__default', $row['backend_layout']);
        self::assertSame('Brand: Test', $row['prompt_optimizer_context']);
        self::assertSame('Custom meta', $row['prompt_optimizer_meta_prompt']);
        self::assertSame(42, (int) $row['image_task']);
    }

    #[Test]
    public function loadByUidReadsImageTask(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(2);

        self::assertNotNull($template);
        self::assertSame(5, $template->imageTask);
        self::assertTrue($template->hasImageTask());
    }

    #[Test]
    public function loadByUidDefaultsImageTaskToZero(): void
    {
        $this->setUpBackendUser(1);

        $template = $this->subject->loadByUid(1);

        self::assertNotNull($template);
        self::assertSame(0, $template->imageTask);
        self::assertFalse($template->hasImageTask());
    }
}
