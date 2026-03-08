# nr-landingpage Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** TYPO3 Extension that generates Landing Pages via LLM using a step-by-step Backend Wizard.

**Architecture:** Backend-Modul mit AJAX-Wizard (analog nr-llm SetupWizard). Services via Constructor DI, DataHandler fuer DB-Writes, nr-llm CompletionService fuer LLM-Calls. TCA-Records fuer Templates. TDD mit typo3/testing-framework.

**Tech Stack:** PHP 8.2+, TYPO3 13.4/14.x, nr-llm (CompletionService, ChatOptions), PHPUnit 10+, phpat, Playwright, PHPStan, PHP-CS-Fixer, Rector, ES6

**Design-Dokument:** `docs/plans/2026-03-06-nr-landingpage-design.md`

**Referenz-Extension:** `netresearch/nr-llm` (GitHub: netresearch/t3x-nr-llm) – Setup, Test-Infrastruktur, CI und Quality-Tools als Vorlage nutzen.

---

## Review-Prozess (nach JEDER Phase)

Nach Abschluss jeder Phase werden **zwei Reviews** durchgefuehrt:

### Review 1: Statische Analyse + Quality

```bash
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl
Build/Scripts/runTests.sh -s rector-dry
Build/Scripts/runTests.sh -s architecture
```

Alle Fehler muessen behoben werden bevor die naechste Phase startet.

### Review 2: Code Review

- Code-Review Agent gegen Design-Dokument und TYPO3-Conformance
- Pruefpunkte:
  - strict_types in allen Dateien?
  - Constructor DI statt GeneralUtility::makeInstance()?
  - Keine versteckten Abhaengigkeiten (kein direkter nr-vault Import)?
  - Services stateless?
  - Tests decken alle neuen Klassen ab (>70% Coverage)?
  - Keine $GLOBALS in eigenen Klassen?
  - PSR-12 + TYPO3 CGL eingehalten?
- Alle Findings muessen behoben und committed werden

---

## Phase 1: Extension-Grundgeruest + Quality-Tools + Template-Model

### Task 1.1: Extension-Skeleton + Quality-Tools

**Files:**
- Create: `composer.json`
- Create: `ext_emconf.php`
- Create: `ext_localconf.php`
- Create: `Configuration/Services.yaml`
- Create: `phpstan.neon`
- Create: `.php-cs-fixer.dist.php`
- Create: `rector.php`
- Create: `phpunit.xml`
- Create: `Build/Scripts/runTests.sh`
- Create: `.editorconfig`

**Step 1: Create composer.json**

```json
{
    "name": "netresearch/nr-landingpage",
    "type": "typo3-cms-extension",
    "description": "TYPO3 Landing Page Generator powered by LLM",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "netresearch/nr-llm": "^0.4.0",
        "typo3/cms-core": "^13.4 || ^14.0",
        "typo3/cms-backend": "^13.4 || ^14.0"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.0",
        "infection/infection": "^0.29",
        "phpat/phpat": "^0.10",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^10.5 || ^11.0",
        "rector/rector": "^2.0",
        "saschaegerer/phpstan-typo3": "^2.0",
        "typo3/testing-framework": "^8.0 || ^9.0"
    },
    "suggest": {
        "typo3/cms-workspaces": "Workspace support for generated pages"
    },
    "autoload": {
        "psr-4": {
            "Netresearch\\NrLandingpage\\": "Classes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Netresearch\\NrLandingpage\\Tests\\": "Tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "typo3/cms-composer-installers": true,
            "typo3/class-alias-loader": true,
            "infection/extension-installer": true,
            "phpstan/extension-installer": true
        },
        "vendor-dir": ".Build/vendor",
        "bin-dir": ".Build/bin"
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "nr_landingpage",
            "web-dir": ".Build/Web"
        }
    },
    "scripts": {
        "ci": ["@ci:php-cs-fixer", "@ci:phpstan", "@ci:tests"],
        "ci:php-cs-fixer": "php-cs-fixer fix --dry-run --diff",
        "ci:phpstan": "phpstan analyse",
        "ci:tests": ["@ci:tests:unit", "@ci:tests:functional"],
        "ci:tests:unit": "phpunit -c phpunit.xml --testsuite unit",
        "ci:tests:functional": "phpunit -c phpunit.xml --testsuite functional",
        "ci:tests:architecture": "phpunit -c phpunit.xml --testsuite architecture",
        "fix:cgl": "php-cs-fixer fix"
    }
}
```

**Step 2: Create ext_emconf.php**

```php
<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Landing Page Generator',
    'description' => 'Generate Landing Pages via LLM using a step-by-step Backend Wizard',
    'category' => 'module',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'nr_llm' => '0.4.0-0.99.99',
        ],
        'suggests' => [
            'workspaces' => '',
        ],
    ],
];
```

**Step 3: Create ext_localconf.php**

```php
<?php

declare(strict_types=1);

defined('TYPO3') or die();
```

**Step 4: Create Configuration/Services.yaml**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrLandingpage\:
    resource: '../Classes/*'
```

**Step 5: Create phpstan.neon**

Orientiert an nr-llm Setup. Level 10 (max).

```neon
includes:
    - .Build/vendor/saschaegerer/phpstan-typo3/extension.neon

parameters:
    level: 10
    paths:
        - Classes
    treatPhpDocTypesAsCertain: false
```

**Step 6: Create .php-cs-fixer.dist.php**

Orientiert an nr-llm Setup. PSR-12 + TYPO3 CGL.

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Tests')
    ->in(__DIR__ . '/Configuration');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_line_empty_body' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],
    ])
    ->setFinder($finder);
```

**Step 7: Create rector.php**

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
    ])
    ->withPhpSets(php82: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
```

**Step 8: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         backupGlobals="true"
         bootstrap=".Build/vendor/autoload.php"
         cacheResult="false"
         colors="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>Tests/Unit</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>Tests/Functional</directory>
        </testsuite>
        <testsuite name="architecture">
            <directory>Tests/Architecture</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>Tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">Classes</directory>
        </include>
    </source>
</phpunit>
```

**Step 9: Create Build/Scripts/runTests.sh**

Docker-basierter Test-Runner analog nr-llm. Unterstuetzte Suites:
`-s unit`, `-s functional`, `-s phpstan`, `-s cgl`, `-s cgl-fix`, `-s rector-dry`, `-s architecture`, `-s e2e`, `-s mutation`.
Orientiert an: https://github.com/netresearch/t3x-nr-llm/blob/main/Build/Scripts/runTests.sh

**Step 10: Create .editorconfig**

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.{yaml,yml}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

**Step 11: Commit**

```bash
git add composer.json ext_emconf.php ext_localconf.php Configuration/Services.yaml \
    phpstan.neon .php-cs-fixer.dist.php rector.php phpunit.xml \
    Build/Scripts/runTests.sh .editorconfig
git commit -m "feat: initial extension skeleton with quality tools and test infrastructure"
```

---

### Task 1.2: Template TCA + SQL

**Files:**
- Create: `ext_tables.sql`
- Create: `Configuration/TCA/tx_nrlandingpage_domain_model_template.php`
- Create: `Resources/Private/Language/locallang_db.xlf`

**Step 1: Create ext_tables.sql**

```sql
CREATE TABLE tx_nrlandingpage_domain_model_template (
    title varchar(255) NOT NULL DEFAULT '',
    identifier varchar(255) NOT NULL DEFAULT '',
    description text,
    llm_configuration int(11) unsigned NOT NULL DEFAULT 0,
    system_prompt text,
    allowed_ctypes text,
    page_fields text,
    reference_pages text,
    briefing_mode varchar(20) NOT NULL DEFAULT 'optional',
    publish_mode varchar(20) NOT NULL DEFAULT 'hidden',
    be_groups text
);
```

**Step 2: Create TCA**

```php
<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'title,identifier,description',
        'iconfile' => 'EXT:nr_landingpage/Resources/Public/Icons/template.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;General,
                    title, identifier, description,
                --div--;LLM,
                    llm_configuration, system_prompt,
                --div--;Content,
                    allowed_ctypes, reference_pages,
                --div--;Page Fields,
                    page_fields,
                --div--;Wizard,
                    briefing_mode, publish_mode,
                --div--;Access,
                    be_groups, hidden,
            ',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.identifier',
            'config' => [
                'type' => 'slug',
                'generatorOptions' => [
                    'fields' => ['title'],
                    'replacements' => [
                        '/' => '-',
                    ],
                ],
                'fallbackCharacter' => '-',
                'eval' => 'uniqueInSite',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.description',
            'config' => [
                'type' => 'text',
                'rows' => 4,
            ],
        ],
        'llm_configuration' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.llm_configuration',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_nrllm_domain_model_llmconfiguration',
                'maxitems' => 1,
                'size' => 1,
            ],
        ],
        'system_prompt' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.system_prompt',
            'config' => [
                'type' => 'text',
                'rows' => 10,
                'enableRichtext' => false,
            ],
        ],
        'allowed_ctypes' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.allowed_ctypes',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailableCTypes',
            ],
        ],
        'page_fields' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.page_fields',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'itemsProcFunc' => \Netresearch\NrLandingpage\Service\TemplateService::class . '->getAvailablePageFields',
            ],
        ],
        'reference_pages' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.reference_pages',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'maxitems' => 10,
                'size' => 3,
            ],
        ],
        'briefing_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.briefing_mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'None', 'value' => 'none'],
                    ['label' => 'Optional', 'value' => 'optional'],
                    ['label' => 'Required', 'value' => 'required'],
                ],
                'default' => 'optional',
            ],
        ],
        'publish_mode' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.publish_mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Hidden', 'value' => 'hidden'],
                    ['label' => 'Visible', 'value' => 'visible'],
                ],
                'default' => 'hidden',
            ],
        ],
        'be_groups' => [
            'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.be_groups',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'foreign_table_where' => 'ORDER BY be_groups.title',
                'size' => 5,
                'maxitems' => 99,
            ],
        ],
    ],
];
```

**Step 3: Create language file** `Resources/Private/Language/locallang_db.xlf` with labels.

**Step 4: Commit**

```bash
git add ext_tables.sql Configuration/TCA/ Resources/Private/Language/
git commit -m "feat: add Template TCA record with SQL schema and language labels"
```

---

### Task 1.3: Template Domain Model

**Files:**
- Create: `Classes/Domain/Model/Template.php`
- Test: `Tests/Unit/Domain/Model/TemplateTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Domain\Model;

use Netresearch\NrLandingpage\Domain\Model\Template;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(Template::class)]
final class TemplateTest extends UnitTestCase
{
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $template = new Template(
            uid: 1,
            title: 'Event LP',
            identifier: 'event-lp',
            description: 'Event landing page',
            llmConfiguration: 5,
            systemPrompt: 'You create event pages',
            allowedCTypes: ['text', 'header', 'textmedia'],
            pageFields: ['seo_title', 'description'],
            referencePages: [10, 20],
            briefingMode: 'optional',
            publishMode: 'hidden',
            beGroups: [1, 2],
        );

        self::assertSame(1, $template->uid);
        self::assertSame('Event LP', $template->title);
        self::assertSame('event-lp', $template->identifier);
        self::assertSame('Event landing page', $template->description);
        self::assertSame(5, $template->llmConfiguration);
        self::assertSame('You create event pages', $template->systemPrompt);
        self::assertSame(['text', 'header', 'textmedia'], $template->allowedCTypes);
        self::assertSame(['seo_title', 'description'], $template->pageFields);
        self::assertSame([10, 20], $template->referencePages);
        self::assertSame('optional', $template->briefingMode);
        self::assertSame('hidden', $template->publishMode);
        self::assertSame([1, 2], $template->beGroups);
    }

    #[Test]
    public function isBriefingRequiredReturnsTrueForRequired(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'required');
        self::assertTrue($template->isBriefingRequired());
        self::assertFalse($template->isBriefingSkippable());
    }

    #[Test]
    public function isBriefingSkippableReturnsTrueForOptional(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'optional');
        self::assertTrue($template->isBriefingSkippable());
        self::assertFalse($template->isBriefingRequired());
    }

    #[Test]
    public function isBriefingDisabledReturnsTrueForNone(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'none');
        self::assertTrue($template->isBriefingDisabled());
    }

    #[Test]
    public function hasReferencePagesReturnsFalseWhenEmpty(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertFalse($template->hasReferencePages());
    }

    #[Test]
    public function hasReferencePagesReturnsTrueWhenSet(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', referencePages: [10]);
        self::assertTrue($template->hasReferencePages());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `Build/Scripts/runTests.sh -s unit`
Expected: FAIL – class Template not found

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Domain\Model;

final readonly class Template
{
    /**
     * @param list<string> $allowedCTypes
     * @param list<string> $pageFields
     * @param list<int> $referencePages
     * @param list<int> $beGroups
     */
    public function __construct(
        public int $uid,
        public string $title,
        public string $identifier,
        public string $description = '',
        public int $llmConfiguration = 0,
        public string $systemPrompt = '',
        public array $allowedCTypes = [],
        public array $pageFields = [],
        public array $referencePages = [],
        public string $briefingMode = 'optional',
        public string $publishMode = 'hidden',
        public array $beGroups = [],
    ) {}

    public function isBriefingRequired(): bool
    {
        return $this->briefingMode === 'required';
    }

    public function isBriefingSkippable(): bool
    {
        return $this->briefingMode === 'optional';
    }

    public function isBriefingDisabled(): bool
    {
        return $this->briefingMode === 'none';
    }

    public function hasReferencePages(): bool
    {
        return $this->referencePages !== [];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `Build/Scripts/runTests.sh -s unit`
Expected: PASS (6 tests)

**Step 5: Commit**

```bash
git add Classes/Domain/Model/Template.php Tests/Unit/Domain/Model/TemplateTest.php
git commit -m "feat: add Template readonly domain model with unit tests"
```

---

### Review-Checkpoint Phase 1

**Review 1: Statische Analyse**

```bash
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl
Build/Scripts/runTests.sh -s rector-dry
```

Alle Fehler beheben und committen.

**Review 2: Code Review**

- strict_types in allen PHP-Dateien?
- Template Model: readonly, keine Setter, kein Extbase AbstractEntity?
- TCA: korrekte Feld-Typen, Labels, TYPO3 v13 Syntax?
- composer.json: keine ueberfluessigen Dependencies?
- Quality-Tools lauffaehig?

---

## Phase 2: TemplateService

### Task 2.1: TemplateService – CType/PageField Providers (Unit)

**Files:**
- Create: `Classes/Service/TemplateService.php`
- Test: `Tests/Unit/Service/TemplateServiceTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(TemplateService::class)]
final class TemplateServiceTest extends UnitTestCase
{
    #[Test]
    public function getAvailableCTypesReturnsItemsFromTca(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] = [
            ['label' => 'Header', 'value' => 'header'],
            ['label' => 'Text', 'value' => 'text'],
            ['label' => '--div--', 'value' => '--div--'],
        ];

        $connectionPool = $this->createMock(ConnectionPool::class);
        $service = new TemplateService($connectionPool);

        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        self::assertCount(2, $params['items']);
        self::assertSame('header', $params['items'][0]['value']);
        self::assertSame('text', $params['items'][1]['value']);
    }

    #[Test]
    public function getAvailablePageFieldsExcludesSystemFields(): void
    {
        $GLOBALS['TCA']['pages']['columns'] = [
            'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            'uid' => ['label' => 'UID', 'config' => ['type' => 'input']],
            'pid' => ['label' => 'PID', 'config' => ['type' => 'input']],
        ];

        $connectionPool = $this->createMock(ConnectionPool::class);
        $service = new TemplateService($connectionPool);

        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertCount(1, $params['items']);
        self::assertSame('seo_title', $params['items'][0]['value']);
    }

    #[Test]
    public function getAvailablePageFieldsExcludesPassthroughFields(): void
    {
        $GLOBALS['TCA']['pages']['columns'] = [
            'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            'internal' => ['label' => 'Internal', 'config' => ['type' => 'passthrough']],
        ];

        $connectionPool = $this->createMock(ConnectionPool::class);
        $service = new TemplateService($connectionPool);

        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertCount(1, $params['items']);
    }
}
```

**Step 2: Run – expect FAIL**
**Step 3: Implement TemplateService (getAvailableCTypes, getAvailablePageFields)**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class TemplateService
{
    private const EXCLUDED_PAGE_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting',
        'perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody',
        'editlock', 'doktype', 'is_siteroot', 'mount_pid', 'mount_pid_ol',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 'l10n_parent', 'l10n_source',
        'sys_language_uid',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /** @param array<string, mixed> $params */
    public function getAvailableCTypes(array &$params): void
    {
        $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [];
        foreach ($items as $item) {
            $value = $item['value'] ?? '';
            if ($value === '' || $value === '--div--') {
                continue;
            }
            $params['items'][] = [
                'label' => $item['label'] ?? $value,
                'value' => $value,
            ];
        }
    }

    /** @param array<string, mixed> $params */
    public function getAvailablePageFields(array &$params): void
    {
        $columns = $GLOBALS['TCA']['pages']['columns'] ?? [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, self::EXCLUDED_PAGE_FIELDS, true)) {
                continue;
            }
            $type = $fieldConfig['config']['type'] ?? '';
            if ($type === 'passthrough' || $type === '') {
                continue;
            }
            $label = $fieldConfig['label'] ?? $fieldName;
            $params['items'][] = [
                'label' => $label . ' [' . $fieldName . ']',
                'value' => $fieldName,
            ];
        }
    }
}
```

**Step 4: Run – expect PASS**
**Step 5: Commit**

---

### Task 2.2: TemplateService – loadForUser (Functional)

**Files:**
- Modify: `Classes/Service/TemplateService.php`
- Test: `Tests/Functional/Service/TemplateServiceTest.php`
- Create: `Tests/Functional/Fixtures/templates.csv`
- Create: `Tests/Functional/Fixtures/be_users.csv`
- Create: `Tests/Functional/Fixtures/be_groups.csv`

CSV-Fixtures:
- 3 Templates: (1) ohne Gruppen-Restriction, (2) mit Gruppe 1, (3) mit Gruppe 2
- 2 BE-User: (1) in Gruppe 1, (2) Admin
- 2 BE-Gruppen

**Step 1: Write functional test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Functional\Service;

use Netresearch\NrLandingpage\Service\TemplateService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TemplateServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/templates.csv');
    }

    public function testLoadForUserReturnsOnlyPermittedTemplates(): void
    {
        // BE User 1 is in group 1 -> sees template 1 (no restriction) + template 2 (group 1)
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        $service = $this->get(TemplateService::class);
        $templates = $service->loadForUser();

        self::assertCount(2, $templates);
    }

    public function testLoadForUserAdminSeesAll(): void
    {
        $backendUser = $this->setUpBackendUser(2); // admin
        $GLOBALS['BE_USER'] = $backendUser;

        $service = $this->get(TemplateService::class);
        $templates = $service->loadForUser();

        self::assertCount(3, $templates);
    }
}
```

**Step 2: Run – expect FAIL**
**Step 3: Implement `loadForUser()` in TemplateService**

Methode: Query auf `tx_nrlandingpage_domain_model_template`, filtert nach `be_groups`
des aktuellen `$GLOBALS['BE_USER']`. Admin sieht alle. Ergebnis als `Template[]` zurueckgeben.

**Step 4: Run – expect PASS**
**Step 5: Commit**

---

### Review-Checkpoint Phase 2

**Review 1:** `runTests.sh -s unit && runTests.sh -s functional && runTests.sh -s phpstan && runTests.sh -s cgl`
**Review 2:** Code Review – DI korrekt? Keine $GLOBALS in Service (ausser TCA-Read in itemsProcFunc)?

---

## Phase 3: BriefingService

### Task 3.1: BriefingService – Prompt-Wrapping + JSON-Parsing (Unit)

**Files:**
- Create: `Classes/Service/BriefingService.php`
- Test: `Tests/Unit/Service/BriefingServiceTest.php`

**Step 1: Write failing tests**

3 Tests:
1. `generateQuestions` returns parsed form fields from mocked LLM response
2. Prompt contains JSON format instruction with schema
3. Returns empty array on LLM exception (graceful degradation)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(BriefingService::class)]
final class BriefingServiceTest extends UnitTestCase
{
    #[Test]
    public function generateQuestionsReturnsParsedFormFields(): void
    {
        $llmResponse = [
            ['id' => 'audience', 'label' => 'Zielgruppe', 'type' => 'text', 'required' => true, 'placeholder' => 'B2B'],
        ];
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($llmResponse);

        $template = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'Test');
        $service = new BriefingService($completionService);

        $questions = $service->generateQuestions($template);
        self::assertCount(1, $questions);
        self::assertSame('audience', $questions[0]['id']);
    }

    #[Test]
    public function promptContainsJsonFormatInstruction(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->expects(self::once())
            ->method('completeJson')
            ->with(self::callback(fn(string $p): bool =>
                str_contains($p, 'JSON-Array') && str_contains($p, '"id"')
            ))
            ->willReturn([]);

        $template = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'Prompt');
        (new BriefingService($completionService))->generateQuestions($template);
    }

    #[Test]
    public function returnsEmptyArrayOnLlmException(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')
            ->willThrowException(new InvalidArgumentException('fail'));

        $template = new Template(uid: 1, title: 'T', identifier: 't');
        $service = new BriefingService($completionService);

        self::assertSame([], $service->generateQuestions($template));
    }

    #[Test]
    public function validatesAndCapsQuestionsAtMaximum(): void
    {
        $questions = array_map(
            fn(int $i) => ['id' => "q$i", 'label' => "Q$i", 'type' => 'text', 'required' => false, 'placeholder' => ''],
            range(1, 15),
        );
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn($questions);

        $template = new Template(uid: 1, title: 'T', identifier: 't');
        $result = (new BriefingService($completionService))->generateQuestions($template);

        self::assertCount(8, $result); // MAX_QUESTIONS = 8
    }

    #[Test]
    public function skipsInvalidQuestionsInResponse(): void
    {
        $completionService = $this->createMock(CompletionService::class);
        $completionService->method('completeJson')->willReturn([
            ['id' => 'valid', 'label' => 'Valid', 'type' => 'text'],
            ['broken' => 'data'], // missing id, label, type
            'not-an-array',
        ]);

        $template = new Template(uid: 1, title: 'T', identifier: 't');
        $result = (new BriefingService($completionService))->generateQuestions($template);

        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]['id']);
    }
}
```

**Step 2: Run – expect FAIL**

**Step 3: Implement BriefingService**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class BriefingService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const MAX_QUESTIONS = 8;

    public function __construct(
        private readonly CompletionService $completionService,
    ) {}

    /** @return list<array{id: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}> */
    public function generateQuestions(Template $template): array
    {
        try {
            $response = $this->completionService->completeJson(
                $this->buildPrompt($template),
                ChatOptions::json(),
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Briefing generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->validateQuestions($response);
    }

    private function buildPrompt(Template $template): string
    {
        return <<<PROMPT
            {$template->systemPrompt}

            Basierend auf dem obigen Kontext: Stelle dem User die relevanten
            Fragen um eine Landing Page zu erstellen. Maximal {self::MAX_QUESTIONS} Fragen.

            Antworte ausschliesslich als JSON-Array:
            [
              {"id": "string", "label": "string", "type": "text|textarea|select",
               "required": true|false, "placeholder": "string",
               "options": ["nur bei type=select"]}
            ]
            PROMPT;
    }

    /** @return list<array{id: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}> */
    private function validateQuestions(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['id'], $item['label'], $item['type'])) {
                continue;
            }
            $validated[] = [
                'id' => (string)$item['id'],
                'label' => (string)$item['label'],
                'type' => in_array($item['type'], ['text', 'textarea', 'select'], true) ? $item['type'] : 'text',
                'required' => (bool)($item['required'] ?? false),
                'placeholder' => (string)($item['placeholder'] ?? ''),
                'options' => isset($item['options']) && is_array($item['options']) ? array_map(strval(...), $item['options']) : [],
            ];
        }

        return array_slice($validated, 0, self::MAX_QUESTIONS);
    }
}
```

**Step 4: Run – expect PASS**
**Step 5: Commit**

---

### Review-Checkpoint Phase 3

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – Prompt-Wrapping korrekt? Validierung robust? LoggerAware korrekt?

---

## Phase 4: ContentGeneratorService

### Task 4.1: Content-Generierung – Prompt + Parsing (Unit)

**Files:**
- Create: `Classes/Service/ContentGeneratorService.php`
- Test: `Tests/Unit/Service/ContentGeneratorServiceTest.php`

Tests:
1. Prompt enthaelt Template system_prompt + Briefing-Antworten + erlaubte CTypes + JSON-Format
2. Response korrekt geparst: Sektionen mit CType, header, bodytext
3. HTML-Sanitizing auf bodytext
4. Leere/ungueltige Response → leeres Array
5. Page-Feld-Generierung: Template page_fields + Briefing → JSON mit Feldwerten

**Step 1: Write failing tests**
**Step 2: Run – expect FAIL**
**Step 3: Implement ContentGeneratorService**
**Step 4: Run – expect PASS**
**Step 5: Commit**

### Task 4.2: Content-Generierung – Page-Felder (Unit)

**Files:**
- Modify: `Classes/Service/ContentGeneratorService.php`
- Modify: `Tests/Unit/Service/ContentGeneratorServiceTest.php`

Methode `generatePageFields(Template, briefingAnswers)`: generiert Vorschlaege fuer
am Template konfigurierte Page-Felder. Gleicher JSON-Wrapping-Ansatz.

**Step 1-5: TDD cycle**

---

### Review-Checkpoint Phase 4

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – Prompt-Templates sauber? Parsing defensiv? Keine XSS in bodytext?

---

## Phase 5: ImageSearchService

### Task 5.1: Keyword-Suche in FAL (Unit + Functional)

**Files:**
- Create: `Classes/Service/ImageSearchService.php`
- Test: `Tests/Unit/Service/ImageSearchServiceTest.php`
- Test: `Tests/Functional/Service/ImageSearchServiceTest.php`
- Create: `Tests/Functional/Fixtures/sys_file.csv`
- Create: `Tests/Functional/Fixtures/sys_file_metadata.csv`

Unit: Query-Building Logik, Keyword-Extraktion aus Section-Description.
Functional: LIKE-Query gegen Fixtures, korrekte sys_file UIDs zurueck, Fallback bei leerem Ergebnis.

**Step 1-5: TDD cycle**

---

### Review-Checkpoint Phase 5

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – SQL-Injection sicher (prepared statements)? Keine harte FAL-Abhaengigkeit?

---

## Phase 6: PageCreatorService

### Task 6.1: Seite + Content via DataHandler (Unit + Functional)

**Files:**
- Create: `Classes/Service/PageCreatorService.php`
- Test: `Tests/Unit/Service/PageCreatorServiceTest.php`
- Test: `Tests/Functional/Service/PageCreatorServiceTest.php`

Unit: DataHandler data-map korrekt aufgebaut, hidden-Flag je publish_mode, Workspace-Erkennung.
Functional: Echte DataHandler-Ausfuehrung: pages Record pruefen (title, slug, hidden, SEO-Felder),
tt_content Records pruefen (CType, header, bodytext, sorting, colPos).

**Step 1-5: TDD cycle**

### Task 6.2: Referenzseiten-Kloning (Functional)

**Files:**
- Modify: `Classes/Service/PageCreatorService.php`
- Test: `Tests/Functional/Service/PageCreatorServiceReferenceTest.php`
- Create: `Tests/Functional/Fixtures/reference_pages.csv`
- Create: `Tests/Functional/Fixtures/reference_content.csv`

Functional Test: Referenz-Content klonen via DataHandler copy, Texte ersetzen,
Container-Kinder pruefen (wenn b13/container in Test-Setup geladen).

**Step 1-5: TDD cycle**

### Task 6.3: PSR-14 Events (Unit)

**Files:**
- Create: `Classes/Event/BeforePageCreationEvent.php`
- Create: `Classes/Event/AfterContentGenerationEvent.php`
- Test: `Tests/Unit/Event/EventTest.php`
- Modify: `Classes/Service/PageCreatorService.php` (dispatch Events)

Einfache readonly DTOs. PageCreatorService dispatcht via EventDispatcher.

**Step 1-5: TDD cycle**

---

### Review-Checkpoint Phase 6

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – DataHandler korrekt genutzt? Workspace-Support? Events dispatched?

---

## Phase 7: Backend-Modul + Controller

### Task 7.1: Modul-Registrierung + Controller-Skeleton

**Files:**
- Create: `Configuration/Backend/Modules.php`
- Create: `Configuration/Backend/AjaxRoutes.php`
- Create: `Classes/Controller/Backend/LandingPageWizardController.php`
- Create: `Resources/Private/Templates/Backend/LandingPageWizard/Index.html`
- Create: `Resources/Public/Icons/module.svg`

```php
// Configuration/Backend/Modules.php
<?php

return [
    'nr_landingpage' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'iconIdentifier' => 'nr-landingpage-module',
        'labels' => 'EXT:nr_landingpage/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => \Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController::class . '::indexAction',
            ],
        ],
    ],
];
```

AJAX-Routen fuer jeden Wizard-Step. Controller delegiert an Services.

**Step 1-5: Registrierung + Skeleton + Commit**

### Task 7.2: Controller AJAX-Actions (Functional)

**Files:**
- Modify: `Classes/Controller/Backend/LandingPageWizardController.php`
- Test: `Tests/Functional/Controller/Backend/LandingPageWizardControllerTest.php`

Pro Action ein TDD-Zyklus:
1. `templatesAction` – gibt Templates als JSON zurueck (gefiltert)
2. `generateBriefingAction` – ruft BriefingService, gibt Fragen zurueck
3. `generatePageFieldsAction` – ruft ContentGeneratorService, gibt Feld-Vorschlaege zurueck
4. `generateContentAction` – ruft ContentGeneratorService, gibt Sektionen zurueck
5. `regenerateSectionAction` – regeneriert einzelne Sektion
6. `saveAction` – ruft PageCreatorService, gibt neue Page-UID zurueck

Jede Action: CSRF-Check, JSON-Response, Fehlerbehandlung.

**Step 1-5: TDD cycle pro Action**

---

### Review-Checkpoint Phase 7

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – CSRF-Protection? Controller duenn (delegiert an Services)? JSON-Responses korrekt?

---

## Phase 8: Kontextmenue

### Task 8.1: ContextMenu ItemProvider (Unit)

**Files:**
- Create: `Classes/ContextMenu/LandingPageItemProvider.php`
- Create: `Configuration/Backend/ContextMenu/ItemProviders.php`
- Test: `Tests/Unit/ContextMenu/LandingPageItemProviderTest.php`

Test: Item sichtbar wenn User Templates hat, versteckt wenn nicht. Link zum Wizard mit `parentPageId`.

**Step 1-5: TDD cycle**

---

### Review-Checkpoint Phase 8

**Review 1:** Alle Suites + PHPStan + CGL
**Review 2:** Code Review – TYPO3 v13 ContextMenu API korrekt? Berechtigungspruefung?

---

## Phase 9: Frontend JavaScript

### Task 9.1: Wizard ES6 Module

**Files:**
- Create: `Resources/Public/JavaScript/wizard.js` (Haupt-Einstiegspunkt)
- Create: `Resources/Public/JavaScript/wizard-state.js` (State-Manager)
- Create: `Resources/Public/JavaScript/steps/template-step.js`
- Create: `Resources/Public/JavaScript/steps/briefing-step.js`
- Create: `Resources/Public/JavaScript/steps/page-fields-step.js`
- Create: `Resources/Public/JavaScript/steps/content-step.js`
- Create: `Resources/Public/JavaScript/steps/placement-step.js`

ES6-Module, kein jQuery, kein RequireJS. TYPO3 Modal API + Notification API.
State client-seitig. Retry/Skip/Back Buttons pro Step.

**Step 1: State-Manager** – speichert Wizard-Daten, aktiven Step, Navigation
**Step 2: Template-Step** – AJAX-Call, Template-Liste, Briefing-Mode-Check
**Step 3: Briefing-Step** – AJAX fuer Fragen, dynamisches Formular, Skip-Option
**Step 4: Page-Fields-Step** – AJAX fuer Vorschlaege, Zeichenzaehler, editierbar
**Step 5: Content-Step** – AJAX fuer Sektionen, Vorschau, Regenerate pro Sektion
**Step 6: Placement-Step** – Page-Tree-Picker, Zusammenfassung, Generieren-Button
**Step 7: ESLint Setup + Pruefung**
**Step 8: Commit pro Step**

---

### Review-Checkpoint Phase 9

**Review 1:** ESLint + alle Backend-Tests
**Review 2:** Code Review – ES6 Syntax? TYPO3 APIs korrekt? Accessibility (ARIA)? Keine XSS?

---

## Phase 10: Architecture Tests

### Task 10.1: phpat Layer-Tests

**Files:**
- Create: `Tests/Architecture/LayerDependencyTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    public function testServicesShouldNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Controller'));
    }

    public function testNoDirectVaultAccess(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrVault'));
    }

    public function testNoGeneralUtilityInServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::classname(\TYPO3\CMS\Core\Utility\GeneralUtility::class));
    }

    public function testModelsShouldNotDependOnServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Domain'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'));
    }

    public function testEventsShouldBeStandalone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Event'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrLandingpage\Service'),
                Selector::inNamespace('Netresearch\NrLandingpage\Controller'),
            );
    }
}
```

**Step 1: Write architecture tests**
**Step 2: Run – fix any violations**
**Step 3: Commit**

---

### Review-Checkpoint Phase 10

**Review 1:** `runTests.sh -s architecture` + alle anderen Suites
**Review 2:** Code Review – Schichttrennung sauber? Keine Zirkularitaeten?

---

## Phase 11: E2E Tests

### Task 11.1: Playwright Setup + Wizard E2E

**Files:**
- Create: `Tests/E2E/playwright.config.ts`
- Create: `Tests/E2E/fixtures/`
- Create: `Tests/E2E/pages/WizardPage.ts` (Page Object Model)
- Create: `Tests/E2E/wizard.spec.ts`
- Create: `Tests/E2E/permissions.spec.ts`

Tests:
1. Kompletter Wizard-Flow (Template → Briefing → PageFields → Content → Generierung)
2. Kontextmenue-Einstiegspunkt
3. Berechtigungen: User sieht nur erlaubte Templates
4. Briefing-Modi: none/optional/required UI-Verhalten
5. Skip bei LLM-Fehler
6. Zurueck-Navigation ohne Datenverlust

Braucht DDEV-Setup mit TYPO3 + nr-llm + Mock-Provider.

**Step 1: Playwright Config + DDEV-Setup**
**Step 2: Page Object Model fuer Wizard**
**Step 3: Tests schreiben**
**Step 4: Run + Fix**
**Step 5: Commit**

---

### Review-Checkpoint Phase 11

**Review 1:** E2E Suite gruen + alle anderen Suites
**Review 2:** Code Review – Page Object Pattern? Stabile Selektoren? Keine flaky Tests?

---

## Phase 12: Documentation + CI + Release

### Task 12.1: README + Extension-Dokumentation

**Files:**
- Create: `README.md`
- Create: `Documentation/Index.rst`
- Create: `Documentation/Configuration/Index.rst`
- Create: `Documentation/Usage/Index.rst`
- Create: `CHANGELOG.md`

### Task 12.2: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

Matrix: TYPO3 13.4 + 14.x, PHP 8.2 + 8.3 + 8.4.

```yaml
jobs:
  cgl:        # PHP-CS-Fixer
  phpstan:    # PHPStan Level 10
  rector:     # Rector dry-run
  unit:       # Unit Tests (matrix: PHP x TYPO3)
  functional: # Functional Tests (matrix: PHP x TYPO3)
  architecture: # phpat
  e2e:        # Playwright (nightly / pre-release)
```

### Task 12.3: TER Release vorbereiten

- ext_emconf.php Version pruefen
- TER-Publishing Workflow (analog nr-llm)
- Tag v0.1.0

**Step 1-5: Docs + CI + Commit + Tag**

---

### Review-Checkpoint Phase 12 (Final)

**Review 1:** Komplette CI-Pipeline gruen
**Review 2:** Finales Code Review gegen Design-Dokument – alle Features umgesetzt? Keine offenen TODOs?

---

## Phasen-Uebersicht

| Phase | Beschreibung | Abhaengigkeiten | Parallelisierbar |
|-------|-------------|-----------------|------------------|
| 1 | Extension-Skeleton + Quality-Tools + Template Model | – | – |
| 2 | TemplateService | Phase 1 | ja (mit 3-5) |
| 3 | BriefingService | Phase 1 | ja (mit 2,4-5) |
| 4 | ContentGeneratorService | Phase 1 | ja (mit 2-3,5) |
| 5 | ImageSearchService | Phase 1 | ja (mit 2-4) |
| 6 | PageCreatorService + Events | Phase 1 | ja (mit 2-5) |
| 7 | Backend-Modul + Controller | Phase 2-6 | – |
| 8 | Kontextmenue | Phase 7 | ja (mit 9) |
| 9 | Frontend JavaScript | Phase 7 | ja (mit 8) |
| 10 | Architecture Tests | Phase 1-9 | – |
| 11 | E2E Tests | Phase 7-9 | – |
| 12 | Docs + CI + Release | Phase 1-11 | – |

**Phasen 2-6 sind unabhaengig** und koennen parallel entwickelt werden (z.B. via Subagents).
**Phasen 8-9 sind unabhaengig** und koennen parallel entwickelt werden.
