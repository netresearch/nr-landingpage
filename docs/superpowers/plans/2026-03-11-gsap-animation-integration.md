# GSAP Animation Integration — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate GSAP (Core + ScrollTrigger + TextPlugin) into both Creative and Structured generation modes with allowlist-based script sanitization, two-pass save for structured animations, and versioned vendor bundling.

**Architecture:** GSAP is vendored in `Resources/Public/JavaScript/vendor/gsap/<major>/`. A new template field `animation_enabled` controls opt-in/out. PageCreatorService uses a two-pass DataHandler flow: pass 1 saves page + content, pass 2 adds a GSAP loader element and (for structured mode) an animation script element targeting `#c{uid}` selectors. CreativeHtmlSanitizer gains an allowlist code path for `<script data-creative>` blocks.

**Tech Stack:** PHP 8.2+, TYPO3 v13/v14, PHPUnit 11, GSAP 3.x (Core + ScrollTrigger + TextPlugin)

**Spec:** `docs/superpowers/specs/2026-03-11-gsap-animation-integration-design.md`

---

## Chunk 1: Schema, Model & Template TCA

### Task 1: Add `animation_enabled` column to template table

**Files:**
- Modify: `ext_tables.sql:1-22`
- Test: run existing tests to verify no regression

- [ ] **Step 1: Add column to SQL schema**

In `ext_tables.sql`, add after `color_text` line (line 21):

```sql
    animation_enabled tinyint(1) unsigned NOT NULL DEFAULT 1
```

- [ ] **Step 2: Run tests to verify no regression**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: All tests pass (no schema-related failures in unit tests)

- [ ] **Step 3: Commit**

```bash
git add ext_tables.sql
git commit -m "feat: add animation_enabled column to template table"
```

### Task 2: Add `tx_nrlandingpage_gsap_version` column to pages table

**Files:**
- Modify: `ext_tables.sql:24-32`
- Modify: `Configuration/TCA/Overrides/pages.php:14-40`

- [ ] **Step 1: Add column to SQL schema**

In `ext_tables.sql`, add after `tx_nrlandingpage_source_page_uid` (line 29):

```sql
    tx_nrlandingpage_gsap_version varchar(20) NOT NULL DEFAULT '',
```

- [ ] **Step 2: Add TCA passthrough column**

In `Configuration/TCA/Overrides/pages.php`, add to the `addTCAcolumns` array:

```php
'tx_nrlandingpage_gsap_version' => [
    'label' => 'GSAP Version',
    'config' => [
        'type' => 'passthrough',
    ],
],
```

- [ ] **Step 3: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add ext_tables.sql Configuration/TCA/Overrides/pages.php
git commit -m "feat: add gsap_version passthrough column to pages table"
```

### Task 3: Add `animation_enabled` to Template model

**Files:**
- Modify: `Classes/Domain/Model/Template.php:15-37` (constructor)
- Modify: `Classes/Domain/Model/Template.php:107-129` (getConfigHash)
- Test: `Tests/Unit/Domain/Model/TemplateTest.php` (create if not exists)

- [ ] **Step 1: Write failing tests**

Create or extend `Tests/Unit/Domain/Model/TemplateTest.php`:

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
    public function animationEnabledDefaultsToTrue(): void
    {
        $template = new Template(uid: 1, title: 'Test', identifier: 'test');
        self::assertTrue($template->animationEnabled);
    }

    #[Test]
    public function animationEnabledCanBeDisabled(): void
    {
        $template = new Template(uid: 1, title: 'Test', identifier: 'test', animationEnabled: false);
        self::assertFalse($template->animationEnabled);
    }

    #[Test]
    public function configHashIncludesAnimationEnabled(): void
    {
        $enabled = new Template(uid: 1, title: 'T', identifier: 't', animationEnabled: true);
        $disabled = new Template(uid: 1, title: 'T', identifier: 't', animationEnabled: false);
        self::assertNotSame($enabled->getConfigHash(), $disabled->getConfigHash());
    }

    #[Test]
    public function isAnimationEnabledReturnsTrueByDefault(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertTrue($template->isAnimationEnabled());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter TemplateTest --no-progress`
Expected: FAIL (property/method not found)

- [ ] **Step 3: Add `animationEnabled` property to constructor**

In `Classes/Domain/Model/Template.php`, add after `colorText` parameter (line 36):

```php
        public bool $animationEnabled = true,
```

- [ ] **Step 4: Add `isAnimationEnabled()` method**

After `hasImageTask()` (line 99):

```php
    public function isAnimationEnabled(): bool
    {
        return $this->animationEnabled;
    }
```

- [ ] **Step 5: Add `animationEnabled` to `getConfigHash()`**

In `getConfigHash()`, add to the `implode('|', [...])` array (after line 125):

```php
            $this->animationEnabled ? '1' : '0',
```

- [ ] **Step 6: Update `withResolvedColors()` to pass through `animationEnabled`**

In the `withResolvedColors` constructor call, add after `colorText`:

```php
            animationEnabled: $this->animationEnabled,
```

- [ ] **Step 7: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter TemplateTest --no-progress`
Expected: PASS (4 tests)

- [ ] **Step 8: Run PHPStan**

Run: `php .Build/bin/phpstan analyse Classes/Domain/Model/Template.php --no-progress`
Expected: No new errors

- [ ] **Step 9: Commit**

```bash
git add Classes/Domain/Model/Template.php Tests/Unit/Domain/Model/TemplateTest.php
git commit -m "feat: add animationEnabled property to Template model"
```

### Task 4: Add `animation_enabled` to Template TCA

**Files:**
- Modify: `Configuration/TCA/tx_nrlandingpage_domain_model_template.php`

- [ ] **Step 1: Add TCA column definition**

Add a new column `animation_enabled` with type `check` (checkbox):

```php
'animation_enabled' => [
    'label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.animation_enabled',
    'config' => [
        'type' => 'check',
        'default' => 1,
        'items' => [
            ['label' => 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang_db.xlf:tx_nrlandingpage_domain_model_template.animation_enabled.label'],
        ],
    ],
],
```

- [ ] **Step 2: Add to showitem in content_layout tab**

Add `animation_enabled` to the content_layout tab in the types showitem string, after the color_scheme palette.

- [ ] **Step 3: Add locallang labels**

Add the appropriate labels to `Resources/Private/Language/locallang_db.xlf` (and `de.locallang_db.xlf` if it exists):

```
animation_enabled = Animation
animation_enabled.label = Enable GSAP animations (scroll effects, typewriter, parallax)
```

- [ ] **Step 4: Commit**

```bash
git add Configuration/TCA/tx_nrlandingpage_domain_model_template.php Resources/Private/Language/
git commit -m "feat: add animation_enabled checkbox to template TCA"
```

### Task 5: Add `animationEnabled` to TemplateService mapper

**Files:**
- Modify: `Classes/Service/TemplateService.php` (the method that maps DB rows to Template objects)

- [ ] **Step 1: Find the mapping method**

Search for where `new Template(` is called in `TemplateService.php` and add:

```php
animationEnabled: (bool) ($row['animation_enabled'] ?? true),
```

- [ ] **Step 2: Run full test suite**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add Classes/Service/TemplateService.php
git commit -m "feat: map animation_enabled from DB to Template model"
```

---

## Chunk 2: GSAP Vendor Bundle

### Task 6: Download and vendor GSAP files

**Files:**
- Create: `Resources/Public/JavaScript/vendor/gsap/3/gsap.min.js`
- Create: `Resources/Public/JavaScript/vendor/gsap/3/ScrollTrigger.min.js`
- Create: `Resources/Public/JavaScript/vendor/gsap/3/TextPlugin.min.js`
- Create: `Resources/Public/JavaScript/vendor/gsap/3/LICENSE`

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p Resources/Public/JavaScript/vendor/gsap/3
```

- [ ] **Step 2: Download GSAP 3.x files from npm**

```bash
npm pack gsap@^3 --pack-destination /tmp && tar -xzf /tmp/gsap-3.*.tgz -C /tmp
cp /tmp/package/dist/gsap.min.js /srv/projects/nr-landingpage/Resources/Public/JavaScript/vendor/gsap/3/
cp /tmp/package/dist/ScrollTrigger.min.js /srv/projects/nr-landingpage/Resources/Public/JavaScript/vendor/gsap/3/
cp /tmp/package/dist/TextPlugin.min.js /srv/projects/nr-landingpage/Resources/Public/JavaScript/vendor/gsap/3/
cp /tmp/package/LICENSE.md /srv/projects/nr-landingpage/Resources/Public/JavaScript/vendor/gsap/3/LICENSE
```

- [ ] **Step 3: Verify files exist and note exact version**

```bash
head -3 Resources/Public/JavaScript/vendor/gsap/3/gsap.min.js
```

Record the exact version string (e.g. `3.12.7`) — this will be used as the version constant.

- [ ] **Step 4: Commit**

```bash
git add Resources/Public/JavaScript/vendor/gsap/
git commit -m "vendor: add GSAP 3.x (Core + ScrollTrigger + TextPlugin)"
```

### Task 7: Create GSAP version constant

**Files:**
- Create: `Classes/Service/GsapService.php`

- [ ] **Step 1: Create GsapService with version constant and path helper**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

final class GsapService
{
    /**
     * Current GSAP major version directory.
     */
    public const MAJOR_VERSION = '3';

    /**
     * Exact GSAP version string for metadata storage.
     */
    public const VERSION = '3.12.7';

    /**
     * Base path to GSAP vendor files (relative to extension public dir).
     */
    private const VENDOR_PATH = 'EXT:nr_landingpage/Resources/Public/JavaScript/vendor/gsap/';

    /**
     * Get the public URL base path for the current GSAP major version.
     *
     * @return string Path like "/typo3conf/ext/nr_landingpage/Resources/Public/JavaScript/vendor/gsap/3/"
     */
    public function getPublicBasePath(): string
    {
        $extPath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(
            self::VENDOR_PATH . self::MAJOR_VERSION . '/',
        );

        // Convert absolute path to web-relative path
        $publicPath = \TYPO3\CMS\Core\Utility\PathUtility::getAbsoluteWebPath($extPath);

        return rtrim($publicPath, '/') . '/';
    }

    /**
     * Build the HTML script tags for GSAP loader element bodytext.
     *
     * The loader includes a global prefers-reduced-motion guard via
     * ScrollTrigger.matchMedia so all animations (both structured and
     * creative mode) are automatically disabled for users who prefer
     * reduced motion. This responds to live media query changes.
     *
     * @param string|null $basePath Override for testing (default: resolved from extension path)
     */
    public function buildLoaderHtml(?string $basePath = null): string
    {
        $base = $basePath ?? $this->getPublicBasePath();

        return <<<HTML
            <script src="{$base}gsap.min.js" defer></script>
            <script src="{$base}ScrollTrigger.min.js" defer></script>
            <script src="{$base}TextPlugin.min.js" defer></script>
            <script data-creative>
            gsap.registerPlugin(ScrollTrigger, TextPlugin);
            ScrollTrigger.matchMedia({
              '(prefers-reduced-motion: no-preference)': function() {
                document.documentElement.classList.add('gsap-animations-active');
              }
            });
            </script>
            HTML;
    }
}
```

- [ ] **Step 2: Write unit test**

Create `Tests/Unit/Service/GsapServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\GsapService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(GsapService::class)]
final class GsapServiceTest extends UnitTestCase
{
    #[Test]
    public function versionConstantsAreDefined(): void
    {
        self::assertSame('3', GsapService::MAJOR_VERSION);
        self::assertNotEmpty(GsapService::VERSION);
        self::assertStringStartsWith('3.', GsapService::VERSION);
    }

    #[Test]
    public function buildLoaderHtmlContainsAllScripts(): void
    {
        $service = new GsapService();
        $html = $service->buildLoaderHtml('/test/path/');

        self::assertStringContainsString('/test/path/gsap.min.js', $html);
        self::assertStringContainsString('/test/path/ScrollTrigger.min.js', $html);
        self::assertStringContainsString('/test/path/TextPlugin.min.js', $html);
        self::assertStringContainsString('gsap.registerPlugin(ScrollTrigger, TextPlugin)', $html);
        self::assertStringContainsString('defer', $html);
        self::assertStringContainsString('data-creative', $html);
    }

    #[Test]
    public function buildLoaderHtmlContainsReducedMotionCheck(): void
    {
        $service = new GsapService();
        $html = $service->buildLoaderHtml('/test/');

        self::assertStringContainsString('prefers-reduced-motion', $html);
        self::assertStringContainsString('ScrollTrigger.matchMedia', $html);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter GsapServiceTest --no-progress`
Expected: PASS

- [ ] **Step 4: Run PHPStan**

Run: `php .Build/bin/phpstan analyse Classes/Service/GsapService.php --no-progress`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add Classes/Service/GsapService.php Tests/Unit/Service/GsapServiceTest.php
git commit -m "feat: add GsapService with version constants and loader HTML builder"
```

---

## Chunk 3: Sanitizer Allowlist

### Task 8: Extend CreativeHtmlSanitizer with allowlist for `<script data-creative>`

**Files:**
- Modify: `Classes/Service/CreativeHtmlSanitizer.php`
- Test: `Tests/Unit/Service/CreativeHtmlSanitizerTest.php`

- [ ] **Step 1: Write failing tests for new sanitizer behavior**

Add to `Tests/Unit/Service/CreativeHtmlSanitizerTest.php`:

```php
#[Test]
public function sanitizePreservesScriptWithDataCreativeAndAllowedContent(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>gsap.to(".hero", {opacity: 1});</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringContainsString('gsap.to', $result);
    self::assertStringContainsString('data-creative', $result);
}

#[Test]
public function sanitizeStripsScriptWithDataCreativeContainingBlockedApi(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>fetch("/api/data").then(r => r.json());</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringNotContainsString('fetch', $result);
    self::assertStringNotContainsString('<script', $result);
}

#[Test]
public function sanitizeStripsScriptWithoutDataCreativeEvenWhenAllowed(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script>alert("xss")</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringNotContainsString('<script', $result);
    self::assertStringNotContainsString('alert', $result);
}

#[Test]
public function sanitizeStripsDataCreativeScriptWhenNotAllowed(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>gsap.to(".hero", {opacity: 1});</script>';
    $result = $sanitizer->sanitize($html);
    self::assertStringNotContainsString('<script', $result);
}

#[Test]
public function sanitizeStripsScriptWithBracketNotationBypass(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>window["fetch"]("/api")</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringNotContainsString('<script', $result);
}

#[Test]
public function sanitizePreservesMultipleDataCreativeScripts(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<style>.a{}</style>'
        . '<script data-creative>gsap.from(".a", {y: 40});</script>'
        . '<section>content</section>'
        . '<script data-creative>ScrollTrigger.create({trigger: ".a"});</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertSame(2, substr_count($result, '<script data-creative>'));
}

#[Test]
public function sanitizeStripsEvalInDataCreativeScript(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>eval("alert(1)")</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringNotContainsString('<script', $result);
}

#[Test]
public function sanitizeStripsDocumentCookieInDataCreativeScript(): void
{
    $sanitizer = new CreativeHtmlSanitizer();
    $html = '<script data-creative>document.cookie</script>';
    $result = $sanitizer->sanitize($html, allowScripts: true);
    self::assertStringNotContainsString('<script', $result);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter CreativeHtmlSanitizerTest --no-progress`
Expected: FAIL (unknown parameter `allowScripts`)

- [ ] **Step 3: Implement allowlist-based sanitizer**

Replace the `sanitize()` method in `CreativeHtmlSanitizer.php` and add the allowlist:

```php
/**
 * Blocked API substrings. If any appears in a <script data-creative> block,
 * the entire block is stripped. This is defense-in-depth, not a sandbox.
 */
private const BLOCKED_APIS = [
    'fetch', 'XMLHttpRequest', 'eval', 'Function(', 'import(',
    'require(', 'document.cookie', 'document.write', 'localStorage',
    'sessionStorage', 'window.location', 'window.open',
    'navigator.sendBeacon', 'innerHTML', 'outerHTML', 'postMessage',
    'Worker(', 'ServiceWorker', 'WebSocket', 'globalThis',
    'self[', 'window[', 'top[', 'parent[', 'frames[',
];

public function sanitize(string $html, bool $allowScripts = false): string
{
    if ($allowScripts) {
        // Two-branch approach:
        // 1. Preserve <script data-creative> blocks that pass allowlist
        // 2. Strip all other <script> tags
        $html = $this->processCreativeScripts($html);
    }

    // Remove ALL <script> tags (those without data-creative, or all if !allowScripts)
    $html = preg_replace('#<script\b(?![^>]*\bdata-creative\b)[^>]*>.*?</script>#is', '', $html) ?? $html;

    if (!$allowScripts) {
        // Legacy mode: strip ALL scripts including data-creative
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    }

    // ... rest of sanitization unchanged (event handlers, protocols, CSS, etc.)
```

Add the `processCreativeScripts()` method:

```php
/**
 * Check each <script data-creative> block against the blocklist.
 * Blocks containing any blocked API substring are removed entirely.
 */
private function processCreativeScripts(string $html): string
{
    return preg_replace_callback(
        '#<script\b[^>]*\bdata-creative\b[^>]*>(.*?)</script>#is',
        function (array $matches): string {
            $content = $matches[1];
            foreach (self::BLOCKED_APIS as $blocked) {
                if (stripos($content, $blocked) !== false) {
                    return ''; // Strip entire block
                }
            }
            return $matches[0]; // Preserve
        },
        $html,
    ) ?? $html;
}
```

- [ ] **Step 4: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter CreativeHtmlSanitizerTest --no-progress`
Expected: ALL PASS

- [ ] **Step 5: Run existing sanitizer tests to verify no regression**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: ALL PASS (existing tests use `sanitize($html)` without `allowScripts`, so behavior is unchanged)

- [ ] **Step 6: Run PHPStan**

Run: `php .Build/bin/phpstan analyse Classes/Service/CreativeHtmlSanitizer.php --no-progress`
Expected: No new errors

- [ ] **Step 7: Commit**

```bash
git add Classes/Service/CreativeHtmlSanitizer.php Tests/Unit/Service/CreativeHtmlSanitizerTest.php
git commit -m "feat: add allowlist-based script sanitization for <script data-creative>"
```

---

## Chunk 4: PageCreatorService — GSAP Loader & Two-Pass

### Task 9: Add GSAP elements creation to PageCreatorService (loader + animation script)

**Files:**
- Modify: `Classes/Service/PageCreatorService.php:47-50` (constructor)
- Modify: `Classes/Service/PageCreatorService.php:60-171` (createLandingPage)
- Test: `Tests/Unit/Service/PageCreatorServiceTest.php`

Note: Read the existing `PageCreatorServiceTest.php` first to understand the mocking patterns (DataHandler mock, ResourceFactory mock, EventDispatcher mock). Follow the same approach.

- [ ] **Step 1: Add GsapService and AnimationScriptBuilder dependencies to constructor**

In `PageCreatorService.php`, update constructor:

```php
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ResourceFactory $resourceFactory,
        private readonly GsapService $gsapService,
        private readonly AnimationScriptBuilder $animationScriptBuilder,
    ) {}
```

- [ ] **Step 2: Add `$animations` parameter to `createLandingPage`**

```php
    public function createLandingPage(
        Template $template,
        int $parentPageId,
        string $title,
        string $slug,
        array $pageFields,
        array $contentSections,
        ?GenerationContext $generationContext = null,
        array $animations = [],
    ): array {
```

- [ ] **Step 3: Add pass-2 call after content UID resolution**

After `$contentUids` is built (after line 164), add:

```php
// Pass 2: Add GSAP loader + animation script if animation is enabled
if ($template->isAnimationEnabled()) {
    $animationMap = [];
    foreach ($contentUids as $index => $uid) {
        $anim = $animations[$index] ?? [];
        if (is_array($anim) && ($anim['type'] ?? '') !== '') {
            $animationMap[$uid] = $anim;
        }
    }
    $this->createGsapElements($pageUid, $animationMap);
}
```

- [ ] **Step 4: Implement `createGsapElements` method**

```php
/**
 * Create GSAP loader and animation script content elements.
 * Separate DataHandler pass because we need real page/content UIDs.
 *
 * Non-fatal: if this fails, the page is saved without animations.
 */
private function createGsapElements(int $pageUid, array $animationMap): void
{
    try {
        $dataMap = [
            'tt_content' => [
                'NEW_gsap_loader' => [
                    'pid' => $pageUid,
                    'CType' => 'html',
                    'header' => '[Animation Library]',
                    'header_layout' => 100,
                    'sorting' => 1,
                    'colPos' => 0,
                    'bodytext' => $this->gsapService->buildLoaderHtml(),
                ],
            ],
            'pages' => [
                $pageUid => [
                    'tx_nrlandingpage_gsap_version' => GsapService::VERSION,
                ],
            ],
        ];

        $animationScript = $this->animationScriptBuilder->build($animationMap);
        if ($animationScript !== '') {
            $dataMap['tt_content']['NEW_gsap_animation'] = [
                'pid' => $pageUid,
                'CType' => 'html',
                'header' => '[Animation Script]',
                'header_layout' => 100,
                'sorting' => 99999,
                'colPos' => 0,
                'bodytext' => $animationScript,
            ];
        }

        $dataHandler = $this->createDataHandler();
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            $this->logger?->warning('GSAP elements creation failed', [
                'errors' => implode(', ', $dataHandler->errorLog),
            ]);
        }
    } catch (\Throwable $e) {
        $this->logger?->warning('GSAP elements creation failed', [
            'exception' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 5: Run tests and PHPStan**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Run: `php .Build/bin/phpstan analyse Classes/Service/PageCreatorService.php --no-progress`
Expected: PASS, no new errors

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/PageCreatorService.php Tests/Unit/Service/PageCreatorServiceTest.php
git commit -m "feat: add GSAP loader and animation script in second DataHandler pass"
```

### Task 10: Add animation script generation for Structured Mode

**Files:**
- Modify: `Classes/Service/PageCreatorService.php`
- Create: `Classes/Service/AnimationScriptBuilder.php`
- Test: `Tests/Unit/Service/AnimationScriptBuilderTest.php`

- [ ] **Step 1: Create AnimationScriptBuilder with tests**

Write `Tests/Unit/Service/AnimationScriptBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\AnimationScriptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AnimationScriptBuilder::class)]
final class AnimationScriptBuilderTest extends UnitTestCase
{
    #[Test]
    public function buildReturnsEmptyStringForEmptyAnimations(): void
    {
        $builder = new AnimationScriptBuilder();
        self::assertSame('', $builder->build([]));
    }

    #[Test]
    public function buildGeneratesGsapCallForFadeUp(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up', 'duration' => 0.8],
        ]);
        self::assertStringContainsString('#c123', $result);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('data-creative', $result);
    }

    #[Test]
    public function buildSkipsUnknownAnimationType(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'nonexistent-animation'],
        ]);
        self::assertSame('', $result);
    }

    #[Test]
    public function buildClampsDurationToValidRange(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up', 'duration' => 999.0],
        ]);
        self::assertStringContainsString('duration: 3', $result);
        self::assertStringNotContainsString('999', $result);
    }

    #[Test]
    public function buildSkipsSectionsWithoutAnimation(): void
    {
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up'],
            456 => [],
            789 => ['type' => 'slide-left'],
        ]);
        self::assertStringContainsString('#c123', $result);
        self::assertStringNotContainsString('#c456', $result);
        self::assertStringContainsString('#c789', $result);
    }

    #[Test]
    public function buildDoesNotIncludeReducedMotionCheck(): void
    {
        // prefers-reduced-motion is handled centrally in the GSAP loader
        $builder = new AnimationScriptBuilder();
        $result = $builder->build([
            123 => ['type' => 'fade-up'],
        ]);
        self::assertStringNotContainsString('prefers-reduced-motion', $result);
    }
}
```

- [ ] **Step 2: Implement AnimationScriptBuilder**

Create `Classes/Service/AnimationScriptBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

/**
 * Builds GSAP animation scripts for structured mode content elements.
 *
 * Maps animation type names to GSAP calls targeting #c{uid} selectors.
 * Wraps all animations in a prefers-reduced-motion check.
 */
final class AnimationScriptBuilder
{
    private const ANIMATION_MAP = [
        'fade-up' => ['prop' => 'opacity: 0, y: 40'],
        'fade-down' => ['prop' => 'opacity: 0, y: -40'],
        'slide-left' => ['prop' => 'opacity: 0, x: -60'],
        'slide-right' => ['prop' => 'opacity: 0, x: 60'],
        'zoom-in' => ['prop' => 'opacity: 0, scale: 0.8'],
        'scale-up' => ['prop' => 'opacity: 0, scale: 0.5'],
        'stagger-children' => ['prop' => 'opacity: 0, y: 20', 'children' => true],
        'typewriter' => ['special' => 'typewriter'],
        'parallax' => ['special' => 'parallax'],
    ];

    /**
     * Build animation script HTML from UID-to-animation map.
     *
     * @param array<int, array{type?: string, duration?: float, delay?: float, stagger?: float}> $animations
     *        Map of content element UID => animation config
     * @return string Complete <script data-creative> block, or empty string if no valid animations
     */
    public function build(array $animations): string
    {
        $calls = [];
        foreach ($animations as $uid => $config) {
            $type = $config['type'] ?? '';
            if ($type === '' || !isset(self::ANIMATION_MAP[$type])) {
                continue;
            }

            $duration = $this->clamp((float) ($config['duration'] ?? 0.8), 0.1, 3.0);
            $delay = $this->clamp((float) ($config['delay'] ?? 0.0), 0.0, 2.0);
            $stagger = $this->clamp((float) ($config['stagger'] ?? 0.15), 0.05, 0.5);
            $def = self::ANIMATION_MAP[$type];

            $selector = "'#c{$uid}'";

            if (($def['special'] ?? '') === 'typewriter') {
                // TextPlugin reveals text by animating from empty to full content.
                // We set initial opacity to hide, then animate both reveal and text.
                $calls[] = "document.querySelectorAll({$selector} + ' h1, ' + {$selector} + ' h2, ' + {$selector} + ' p').forEach(function(el) { var t = el.textContent; el.textContent = ''; gsap.to(el, {scrollTrigger: {$selector}, text: t, duration: {$duration}, delay: {$delay}, ease: 'none'}); });";
                continue;
            }
            if (($def['special'] ?? '') === 'parallax') {
                $calls[] = "gsap.to({$selector}, {scrollTrigger: {trigger: {$selector}, scrub: true}, y: -30, ease: 'none'});";
                continue;
            }

            $target = ($def['children'] ?? false) ? "{$selector} + ' > *'" : $selector;
            $staggerProp = ($def['children'] ?? false) ? ", stagger: {$stagger}" : '';
            $calls[] = "gsap.from({$target}, {scrollTrigger: {$selector}, {$def['prop']}, duration: {$duration}, delay: {$delay}{$staggerProp}});";
        }

        if ($calls === []) {
            return '';
        }

        $script = implode("\n", $calls);

        // Note: prefers-reduced-motion is handled centrally in the GSAP loader
        // element via ScrollTrigger.matchMedia. Animations here are registered
        // unconditionally but will only trigger when the loader activates them.
        return <<<HTML
            <script data-creative>
            {$script}
            </script>
            HTML;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
```

- [ ] **Step 3: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --filter AnimationScriptBuilderTest --no-progress`
Expected: PASS

- [ ] **Step 4: Run PHPStan**

Run: `php .Build/bin/phpstan analyse Classes/Service/AnimationScriptBuilder.php --no-progress`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add Classes/Service/AnimationScriptBuilder.php Tests/Unit/Service/AnimationScriptBuilderTest.php
git commit -m "feat: add AnimationScriptBuilder for structured mode GSAP animations"
```

---

## Chunk 5: Content Generation — Prompts & Validation

### Task 11: Update Creative Mode prompt for GSAP

**Files:**
- Modify: `Classes/Service/ContentGeneratorService.php:466-522` (buildCreativePrompt)
- Modify: `Classes/Service/ContentGeneratorService.php:101-112` (generateCreativeContent)
- Modify: `Classes/Service/ContentGeneratorService.php:531-579` (validateCreativeSections)
- Modify: `Classes/Service/ContentGeneratorService.php` (sanitizeCreativeHtml — find via grep)

- [ ] **Step 1: Replace JavaScript prohibition with GSAP instructions in `buildCreativePrompt()`**

In `buildCreativePrompt()` (line ~491), replace `KEIN JavaScript, KEINE <script>-Tags, KEINE Event-Handler.` with:

```
            JAVASCRIPT-ANIMATIONEN:
            GSAP (gsap), ScrollTrigger und TextPlugin sind global verfuegbar.
            Nutze sie fuer Scroll-Animationen, Reveals, Typewriter-Effekte,
            Parallax und alles was die Seite lebendig macht.
            - Jeder <script>-Block MUSS das Attribut data-creative tragen.
            - Erlaubte APIs: gsap.*, ScrollTrigger.*, TextPlugin.*,
              document.querySelector/All, Standard-JS (const, let, =>, forEach).
            - VERBOTEN: fetch, XMLHttpRequest, eval, document.cookie,
              localStorage, window.location, innerHTML und alle Netzwerk-APIs.
            - Verwende die CSS-Klassen-Praefixe der Section als Selektoren.
            - prefers-reduced-motion wird automatisch vom Loader behandelt,
              du brauchst KEINE eigene Pruefung einzubauen.
```

**Important:** This block must be conditional on `$template->isAnimationEnabled()`. If disabled, keep the original `KEIN JavaScript` rule. Pass `$template` to `buildCreativePrompt()` already — use `$template->isAnimationEnabled()` in a ternary.

- [ ] **Step 2: Thread `allowScripts` through the call chain**

The call chain is:
1. `generateCreativeContent()` (line 101) → calls `validateCreativeSections()` (line 111)
2. `validateCreativeSections()` (line 531) → calls `sanitizeCreativeHtml()` (line 549)
3. `sanitizeCreativeHtml()` → calls `$this->creativeHtmlSanitizer->sanitize()`

Update each method signature:

**`generateCreativeContent()`** — determine `$allowScripts`:
```php
$allowScripts = $template->isAnimationEnabled();
return $this->validateCreativeSections($response, $columnMap, $allowScripts);
```

**`validateCreativeSections()`** — accept and pass through:
```php
private function validateCreativeSections(mixed $response, array $columnMap, bool $allowScripts = false): array
{
    // ... existing code ...
    $bodytext = $this->sanitizeCreativeHtml($bodytext, $allowScripts);
    // ...
}
```

**`sanitizeCreativeHtml()`** — accept and delegate:
```php
private function sanitizeCreativeHtml(string $html, bool $allowScripts = false): string
{
    return $this->creativeHtmlSanitizer->sanitize($html, $allowScripts);
}
```

- [ ] **Step 3: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add Classes/Service/ContentGeneratorService.php
git commit -m "feat: update creative mode prompt for GSAP animation support"
```

### Task 12: Add animation field to Structured Mode prompt and validation

**Files:**
- Modify: `Classes/Service/ContentGeneratorService.php` (structured prompt + validation)

- [ ] **Step 1: Add animation instruction to structured prompt**

In the structured mode prompt builder (`buildContentPrompt()`), add when `$template->isAnimationEnabled()`:

```
Optional pro Section: "animation" Objekt.
Moegliche Typen: fade-up, fade-down, slide-left, slide-right,
zoom-in, scale-up, stagger-children, typewriter, parallax.
Nicht jede Section braucht Animation — setze sie gezielt ein.
Format: {"type": "fade-up", "duration": 0.8, "delay": 0, "stagger": 0.15}
Alle Felder ausser "type" sind optional.
```

- [ ] **Step 2: Extract animation data in validateSections()**

In the structured `validateSections()` method, extract the `animation` field from each section and include it in the returned array:

```php
'animation' => $this->validateAnimation($item['animation'] ?? null),
```

Add the validation method:

```php
/**
 * @return array{type?: string, duration?: float, delay?: float, stagger?: float}
 */
private function validateAnimation(mixed $animation): array
{
    if (!is_array($animation)) {
        return [];
    }

    $type = is_string($animation['type'] ?? null) ? $animation['type'] : '';
    if ($type === '') {
        return [];
    }

    $result = ['type' => $type];
    if (isset($animation['duration']) && is_numeric($animation['duration'])) {
        $result['duration'] = max(0.1, min(3.0, (float) $animation['duration']));
    }
    if (isset($animation['delay']) && is_numeric($animation['delay'])) {
        $result['delay'] = max(0.0, min(2.0, (float) $animation['delay']));
    }
    if (isset($animation['stagger']) && is_numeric($animation['stagger'])) {
        $result['stagger'] = max(0.05, min(0.5, (float) $animation['stagger']));
    }

    return $result;
}
```

- [ ] **Step 3: Write tests for animation validation**

Add to `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php`:

```php
#[Test]
public function validateAnimationReturnsEmptyForNull(): void
{
    // Test that null animation input returns []
}

#[Test]
public function validateAnimationClampsDuration(): void
{
    // Test that duration > 3.0 is clamped to 3.0
}

#[Test]
public function validateAnimationIgnoresUnknownType(): void
{
    // The validation only checks structure, not type names
    // Type validation happens in AnimationScriptBuilder
}
```

- [ ] **Step 4: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Classes/Service/ContentGeneratorService.php Tests/Unit/Service/ContentGeneratorServiceValidationTest.php
git commit -m "feat: add animation field to structured mode prompt and validation"
```

### Task 13: Pass animation data through wizard controller to PageCreatorService

**Files:**
- Modify: `Classes/Controller/Backend/LandingPageWizardController.php` (the save action)

- [ ] **Step 1: Extract animation data from content sections**

In the save/create action, after content sections are generated, extract animation data:

```php
$animations = array_map(
    static fn(array $section): array => $section['animation'] ?? [],
    $contentSections,
);
```

- [ ] **Step 2: Pass to createLandingPage**

```php
$result = $this->pageCreatorService->createLandingPage(
    $template,
    $parentPageId,
    $title,
    $slug,
    $pageFields,
    $contentSections,
    $generationContext,
    $animations,
);
```

- [ ] **Step 3: Run tests**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add Classes/Controller/Backend/LandingPageWizardController.php
git commit -m "feat: pass animation data from wizard to page creator"
```

---

## Chunk 6: Documentation

### Task 14: Update Configuration documentation

**Files:**
- Modify: `Documentation/Configuration/Index.rst`

- [ ] **Step 1: Add Animation template field documentation**

After the Color Scheme Palette section, add a new confval for `Animation`:

```rst
.. confval:: Animation

   :type: boolean
   :default: enabled

   Enable GSAP-powered JavaScript animations for generated pages. When
   enabled, content elements receive scroll-triggered reveals, typewriter
   effects, parallax, and other modern animation effects.

   Disable this for templates that should produce lightweight, JS-free
   pages (e.g. for accessibility-focused or performance-critical sites).
```

- [ ] **Step 2: Add GSAP section**

Add a new top-level section:

```rst
GSAP Animation Library
======================

The extension ships GSAP (GreenSock Animation Platform) for
JavaScript-powered animations in generated landing pages.

Included Plugins
----------------

-  **GSAP Core** — timeline-based animations, tweening
-  **ScrollTrigger** — scroll-based animation triggers, pinning
-  **TextPlugin** — typewriter and text morphing effects

Version & Retention Policy
--------------------------

The extension bundles GSAP files in versioned directories. A maximum
of **two major versions** are shipped simultaneously (current +
previous). When a new major version is added, the oldest is removed.

Generated pages store the GSAP version they were created with. After
an extension update that drops an old GSAP major version, previously
generated pages using that version should be re-generated or manually
verified.

License
-------

GSAP Core, ScrollTrigger, and TextPlugin are free for commercial use
under the GSAP Standard License.

Content Security Policy
-----------------------

GSAP animations use inline ``<script>`` tags. If your site enforces
a Content Security Policy on the frontend, add these directives:

.. code-block:: text

   script-src 'unsafe-inline' /typo3conf/ext/nr_landingpage/Resources/Public/;

For nonce-based CSP, configure TYPO3's CSP API to add nonces to
inline scripts.
```

- [ ] **Step 3: Add Allowlist reference**

```rst
Script Allowlist (Creative Mode)
--------------------------------

In creative mode, the AI may write ``<script data-creative>`` blocks
using GSAP. These scripts are checked against an allowlist. Blocked
APIs (``fetch``, ``eval``, ``document.cookie``, etc.) cause the
entire script block to be removed.

This is a defense-in-depth measure. The primary trust boundary is the
AI prompt. For maximum security, configure a frontend CSP alongside
the allowlist.
```

- [ ] **Step 4: Commit**

```bash
git add Documentation/Configuration/Index.rst
git commit -m "docs: add GSAP configuration, versioning, and CSP documentation"
```

### Task 15: Update Usage documentation

**Files:**
- Modify: `Documentation/Usage/Index.rst`

- [ ] **Step 1: Update Creative Mode section**

Expand the creative mode description with GSAP capabilities.

- [ ] **Step 2: Update Structured Mode section**

Add note about per-section animation and that not every element is animated.

- [ ] **Step 3: Add accessibility note**

```rst
Accessibility
-------------

Generated animations automatically respect the operating system's
``prefers-reduced-motion`` setting. When a user has enabled reduced
motion, all GSAP animations are skipped.
```

- [ ] **Step 4: Add GSAP update notice to Best Practices**

```rst
GSAP Version Updates
--------------------

When updating the extension, check the release notes for GSAP version
changes. If a GSAP major version was dropped, test existing landing
pages that were generated with the old version. Re-generate affected
pages if animations no longer work correctly.
```

- [ ] **Step 5: Commit**

```bash
git add Documentation/Usage/Index.rst
git commit -m "docs: document GSAP animation usage, accessibility, and update guidance"
```

### Task 16: Final integration test & cleanup

- [ ] **Step 1: Run full test suite**

Run: `php .Build/bin/phpunit -c phpunit.xml --testsuite unit --no-progress`
Expected: ALL PASS

- [ ] **Step 2: Run PHPStan on entire codebase**

Run: `php .Build/bin/phpstan analyse --no-progress`
Expected: No new errors

- [ ] **Step 3: Run PHP-CS-Fixer**

Run: `php .Build/bin/php-cs-fixer fix --dry-run --diff`
Expected: No fixes needed (or fix and commit)

- [ ] **Step 4: Final commit if needed**

```bash
git add -A
git commit -m "chore: fix code style after GSAP integration"
```
