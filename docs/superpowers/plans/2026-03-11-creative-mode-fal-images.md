# Creative Mode FAL Images — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the LLM optionally place one image placeholder per creative-mode section; editors select a FAL image in the wizard; on save, the placeholder is resolved to a real URL or removed.

**Architecture:** The LLM provides `imageKeywords`/`imagePrompt` alongside the HTML. The sanitizer whitelists `<img data-image-slot="0">` without `src`. The wizard shows image selection UI for creative sections that have keywords. PageCreatorService resolves placeholders into inline `<img src="...">` (or removes them) instead of creating `sys_file_reference` records.

**Tech Stack:** PHP 8.2, TYPO3 v14, PHPUnit 11, vanilla JS (ES modules)

**Spec:** `docs/superpowers/specs/2026-03-11-creative-mode-fal-images-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `Classes/Service/ContentGeneratorService.php` | Update prompt + validation |
| Modify | `Classes/Service/CreativeHtmlSanitizer.php` | Whitelist `<img data-image-slot>`, block `<img src>` |
| Modify | `Classes/Service/PageCreatorService.php` | Resolve image placeholders for `html` CType |
| Modify | `Resources/Public/JavaScript/wizard.js` | Image UI for creative sections |
| Modify | `Tests/Unit/Service/CreativeHtmlSanitizerTest.php` | New + updated sanitizer tests |
| Modify | `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php` | Creative validation tests |
| Modify | `Tests/Unit/Service/PageCreatorServiceTest.php` | Placeholder resolution tests |

---

## Chunk 1: Backend — Sanitizer, Validation, Prompt

### Task 1: CreativeHtmlSanitizer — block `<img src>`, allow `<img data-image-slot>`

**Files:**
- Modify: `Classes/Service/CreativeHtmlSanitizer.php:24-44`
- Modify: `Tests/Unit/Service/CreativeHtmlSanitizerTest.php:430-437`

- [ ] **Step 1: Write the failing test — `allowsImagePlaceholderWithoutSrc`**

In `Tests/Unit/Service/CreativeHtmlSanitizerTest.php`, add after the `sanitizePreservesImgWithHttpSrc` test (around line 438):

```php
#[Test]
public function sanitizeAllowsImagePlaceholderWithoutSrc(): void
{
    $html = '<img data-image-slot="0" alt="Team photo">';
    $result = $this->subject->sanitize($html);

    self::assertStringContainsString('data-image-slot="0"', $result);
    self::assertStringContainsString('alt="Team photo"', $result);
}

#[Test]
public function sanitizeAllowsImagePlaceholderWithReorderedAttributes(): void
{
    $html = '<img alt="Hero" data-image-slot="0" class="hero-img">';
    $result = $this->subject->sanitize($html);

    self::assertStringContainsString('data-image-slot="0"', $result);
    self::assertStringContainsString('alt="Hero"', $result);
}
```

- [ ] **Step 2: Run tests to verify they pass (placeholders survive current sanitizer)**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/CreativeHtmlSanitizerTest.php --filter="sanitizeAllowsImagePlaceholder" -v`

Expected: PASS (current sanitizer does not strip `<img>` tags at all, so placeholders survive).

- [ ] **Step 3: Write the failing test — `blocksImgWithSrcAttribute`**

Update the existing `sanitizePreservesImgWithHttpSrc` test (lines 431-437) to expect **removal**:

```php
#[Test]
public function sanitizeBlocksImgWithSrcAttribute(): void
{
    $html = '<img src="https://example.com/photo.jpg" alt="Photo">';
    $result = $this->subject->sanitize($html);

    self::assertStringNotContainsString('<img', $result);
}

#[Test]
public function sanitizeBlocksImgWithDataUriSrc(): void
{
    $html = '<img src="data:image/png;base64,abc123" alt="Inline">';
    $result = $this->subject->sanitize($html);

    self::assertStringNotContainsString('<img', $result);
}
```

Also update `sanitizeRemovesOnerrorAttribute` (line 105-113) — it currently asserts `src="missing.png"` is preserved, but our new rule removes all `<img>` with `src`. Replace:

```php
#[Test]
public function sanitizeRemovesOnerrorAttribute(): void
{
    $html = '<img src="missing.png" onerror="alert(1)" alt="image">';
    $result = $this->subject->sanitize($html);

    // The <img> is removed entirely because it has a src attribute
    self::assertStringNotContainsString('onerror', $result);
    self::assertStringNotContainsString('alert(1)', $result);
    self::assertStringNotContainsString('<img', $result);
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/CreativeHtmlSanitizerTest.php --filter="sanitizeBlocksImg" -v`

Expected: FAIL — current sanitizer preserves `<img src="...">`.

- [ ] **Step 5: Implement sanitizer — block `<img>` with `src`, allow without**

In `Classes/Service/CreativeHtmlSanitizer.php`, add a new step after the existing step 5 (line 42), before `return trim($html)`:

```php
// 6. Remove <img> tags that have a src attribute (external images).
//    Allow <img data-image-slot="0"> placeholders (no src) for FAL image slots.
$html = preg_replace('#<img\b[^>]*\bsrc\s*=[^>]*>#is', '', $html) ?? $html;
```

This removes any `<img>` with `src` while preserving `<img data-image-slot="0" alt="...">` (which has no `src`).

- [ ] **Step 6: Run all sanitizer tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/CreativeHtmlSanitizerTest.php -v`

Expected: ALL PASS.

- [ ] **Step 7: Commit**

```bash
git add Classes/Service/CreativeHtmlSanitizer.php Tests/Unit/Service/CreativeHtmlSanitizerTest.php
git commit -m "feat: sanitizer blocks <img src>, allows <img data-image-slot> placeholders"
```

---

### Task 2: validateCreativeSections — parse imageKeywords/imagePrompt

**Files:**
- Modify: `Classes/Service/ContentGeneratorService.php:542-551`
- Modify: `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php:264-276`

- [ ] **Step 1: Update existing test + add new tests**

In `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php`:

Replace `validateCreativeSectionsSetsEmptyImageFields` (lines 264-276) with:

```php
#[Test]
public function validateCreativeSectionsDefaultsEmptyImageFields(): void
{
    $response = [
        ['section' => 'Hero', 'colPos' => 0, 'bodytext' => '<p>Test</p>'],
    ];

    $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
    $result = $method->invoke($this->subject, $response, [0 => 'Main']);

    self::assertSame([], $result[0]['imageKeywords']);
    self::assertSame('', $result[0]['imagePrompt']);
}

#[Test]
public function validateCreativeSectionsReadsImageKeywords(): void
{
    $response = [
        [
            'section' => 'Hero',
            'colPos' => 0,
            'bodytext' => '<p>Test</p>',
            'imageKeywords' => ['team', 'office', 'modern'],
            'imagePrompt' => 'A modern office team working together',
        ],
    ];

    $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
    $result = $method->invoke($this->subject, $response, [0 => 'Main']);

    self::assertSame(['team', 'office', 'modern'], $result[0]['imageKeywords']);
    self::assertSame('A modern office team working together', $result[0]['imagePrompt']);
}

#[Test]
public function validateCreativeSectionsFiltersInvalidKeywords(): void
{
    $response = [
        [
            'section' => 'Hero',
            'colPos' => 0,
            'bodytext' => '<p>Test</p>',
            'imageKeywords' => ['valid', '', 42, '  trimmed  '],
            'imagePrompt' => 'Prompt text',
        ],
    ];

    $method = new ReflectionMethod($this->subject, 'validateCreativeSections');
    $result = $method->invoke($this->subject, $response, [0 => 'Main']);

    self::assertSame(['valid', 'trimmed'], $result[0]['imageKeywords']);
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/ContentGeneratorServiceValidationTest.php --filter="validateCreativeSections" -v`

Expected: `validateCreativeSectionsReadsImageKeywords` FAILS (current code hardcodes `[]`), `validateCreativeSectionsFiltersInvalidKeywords` FAILS.

- [ ] **Step 3: Implement — parse imageKeywords/imagePrompt in validateCreativeSections**

In `Classes/Service/ContentGeneratorService.php`, replace lines 550-551:

```php
'imageKeywords' => [],
'imagePrompt' => '',
```

With:

```php
'imageKeywords' => $imageKeywords,
'imagePrompt' => is_string($item['imagePrompt'] ?? null) ? $item['imagePrompt'] : '',
```

And add the keyword parsing before the `$validated[] = [` block (before line 543):

```php
$imageKeywords = [];
if (is_array($item['imageKeywords'] ?? null)) {
    foreach ($item['imageKeywords'] as $kw) {
        if (is_string($kw) && trim($kw) !== '') {
            $imageKeywords[] = trim($kw);
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/ContentGeneratorServiceValidationTest.php --filter="validateCreativeSections" -v`

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add Classes/Service/ContentGeneratorService.php Tests/Unit/Service/ContentGeneratorServiceValidationTest.php
git commit -m "feat: parse imageKeywords/imagePrompt in creative section validation"
```

---

### Task 3: buildCreativePrompt — update LLM instructions

**Files:**
- Modify: `Classes/Service/ContentGeneratorService.php:481-504`

- [ ] **Step 1: Write a test for the updated prompt**

In `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php`, add:

```php
#[Test]
public function buildCreativePromptContainsImagePlaceholderInstructions(): void
{
    $layout = $this->createMock(BackendLayout::class);
    $layout->method('getUsedColumns')->willReturn([0 => 'Main']);
    $this->dataProviderCollection->method('getBackendLayout')->willReturn($layout);

    $template = new \Netresearch\NrLandingpage\Domain\Model\Template(
        uid: 1,
        title: 'Test',
        identifier: 'test',
        systemPrompt: 'Create a page',
        generationMode: 'creative',
    );

    $method = new ReflectionMethod($this->subject, 'buildCreativePrompt');
    $prompt = $method->invoke($this->subject, $template, [], 'de', [0 => 'Main']);

    self::assertStringContainsString('data-image-slot', $prompt);
    self::assertStringContainsString('imageKeywords', $prompt);
    self::assertStringContainsString('imagePrompt', $prompt);
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/ContentGeneratorServiceValidationTest.php --filter="buildCreativePromptContainsImagePlaceholder" -v`

Expected: FAIL — current prompt has no mention of `data-image-slot` or `imageKeywords`.

- [ ] **Step 3: Update buildCreativePrompt**

In `Classes/Service/ContentGeneratorService.php`, replace design rule 4 (lines 486-487):

```
            4. Erstelle Bilder als Inline-SVG mit sinnvollen, dekorativen Grafiken.
               KEINE externen Bild-URLs, KEINE <img>-Tags mit src-Attribut.
```

With:

```
            4. Fuer dekorative Grafiken (Icons, Muster, abstrakte Formen) verwende Inline-SVG.
               Wenn ein Foto den Inhalt visuell bereichert (Hero-Bild, Teaser, Team-Portrait,
               Referenz-Foto), setze genau EIN <img data-image-slot="0" alt="Beschreibung">
               pro Section. Kein src-Attribut — das Bild wird spaeter aus der Mediathek zugeordnet.
               Nicht jede Section braucht ein Foto — verwende es nur wo es den Inhalt staerkt.
```

And update the JSON example (lines 501-504) to include image fields:

```
            [
              {"section": "Name", "colPos": 0, "header": "Titel",
               "bodytext": "<style>.hero { ... }</style><section class='hero'>...<img data-image-slot=\"0\" alt=\"...\">...</section>",
               "imageKeywords": ["keyword1", "keyword2"],
               "imagePrompt": "Detailed English image description"}
            ]
```

And add after line 506 (`Das bodytext-Feld enthaelt...`), before `PROMPT;`:

```
            Wenn du einen <img data-image-slot="0"> Platzhalter setzt, liefere imageKeywords
            (3-5 englische Suchbegriffe fuer die Mediathek) und imagePrompt (detaillierter
            englischer Bild-Prompt). Ohne Platzhalter: leeres Array / leerer String.
```

- [ ] **Step 4: Run tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/ContentGeneratorServiceValidationTest.php -v`

Expected: ALL PASS.

- [ ] **Step 5: Run PHPStan**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpstan analyse Classes/Service/ContentGeneratorService.php --level=6`

Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/ContentGeneratorService.php Tests/Unit/Service/ContentGeneratorServiceValidationTest.php
git commit -m "feat: update creative prompt with image placeholder instructions"
```

---

## Chunk 2: Backend — PageCreatorService Placeholder Resolution

### Task 4: PageCreatorService — resolveImagePlaceholders method

**Files:**
- Modify: `Classes/Service/PageCreatorService.php`
- Modify: `Tests/Unit/Service/PageCreatorServiceTest.php`

- [ ] **Step 1: Update `createService` helper to support ResourceFactory**

The existing `createService` method (line 36) creates an anonymous subclass. It must be updated to accept and forward `ResourceFactory`. Add imports at the top of the file:

```php
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use ReflectionMethod;
```

Update `createService` (lines 36-59) to accept an optional `ResourceFactory`:

```php
private function createService(
    DataHandler $dataHandler,
    ?EventDispatcherInterface $eventDispatcher = null,
    int $workspaceId = 0,
    ?ResourceFactory $resourceFactory = null,
): PageCreatorService {
    $dispatcher = $eventDispatcher ?? $this->createPassthroughDispatcher();
    $factory = $resourceFactory ?? $this->createMock(ResourceFactory::class);

    return new class ($dispatcher, $factory, $dataHandler, $workspaceId) extends PageCreatorService {
        public function __construct(
            EventDispatcherInterface $eventDispatcher,
            ResourceFactory $resourceFactory,
            private readonly DataHandler $mockDataHandler,
            private readonly int $mockWorkspaceId,
        ) {
            parent::__construct($eventDispatcher, $resourceFactory);
        }

        protected function createDataHandler(): DataHandler
        {
            return $this->mockDataHandler;
        }

        protected function getCurrentWorkspaceId(): int
        {
            return $this->mockWorkspaceId;
        }
    };
}
```

This change is backwards-compatible — all existing tests pass `null` for `$resourceFactory` and get a default mock.

- [ ] **Step 2: Write failing tests for resolveImagePlaceholders**

Add these tests to `PageCreatorServiceTest.php`:

```php
#[Test]
public function resolveImagePlaceholdersReplacesWithUrl(): void
{
    $file = $this->createMock(File::class);
    $file->method('getPublicUrl')->willReturn('/fileadmin/team-photo.jpg');

    $resourceFactory = $this->createMock(ResourceFactory::class);
    $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $subject = $this->createService($dh, resourceFactory: $resourceFactory);

    $method = new ReflectionMethod($subject, 'resolveImagePlaceholders');
    $result = $method->invoke(
        $subject,
        '<section><img data-image-slot="0" alt="Team"></section>',
        42,
    );

    self::assertStringContainsString('src="/fileadmin/team-photo.jpg"', $result);
    self::assertStringContainsString('alt="Team"', $result);
    self::assertStringNotContainsString('data-image-slot', $result);
}

#[Test]
public function resolveImagePlaceholdersRemovesWhenNoImage(): void
{
    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $subject = $this->createService($dh);

    $method = new ReflectionMethod($subject, 'resolveImagePlaceholders');
    $result = $method->invoke(
        $subject,
        '<section><img data-image-slot="0" alt="Team"><p>Text</p></section>',
        0,
    );

    self::assertStringNotContainsString('<img', $result);
    self::assertStringContainsString('<p>Text</p>', $result);
}

#[Test]
public function resolveImagePlaceholdersHandlesReorderedAttributes(): void
{
    $file = $this->createMock(File::class);
    $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

    $resourceFactory = $this->createMock(ResourceFactory::class);
    $resourceFactory->method('getFileObject')->with(7)->willReturn($file);

    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $subject = $this->createService($dh, resourceFactory: $resourceFactory);

    $method = new ReflectionMethod($subject, 'resolveImagePlaceholders');
    $result = $method->invoke(
        $subject,
        '<img alt="Hero" data-image-slot="0" class="hero-img">',
        7,
    );

    self::assertStringContainsString('src="/fileadmin/hero.jpg"', $result);
    self::assertStringContainsString('alt="Hero"', $result);
    self::assertStringNotContainsString('data-image-slot', $result);
}

#[Test]
public function resolveImagePlaceholdersFallsBackOnInvalidFile(): void
{
    $resourceFactory = $this->createMock(ResourceFactory::class);
    $resourceFactory->method('getFileObject')->willThrowException(new \InvalidArgumentException('File not found'));

    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $subject = $this->createService($dh, resourceFactory: $resourceFactory);

    $method = new ReflectionMethod($subject, 'resolveImagePlaceholders');
    $result = $method->invoke(
        $subject,
        '<section><img data-image-slot="0" alt="X"><p>Text</p></section>',
        999,
    );

    // Falls back to removal when file can't be resolved
    self::assertStringNotContainsString('<img', $result);
    self::assertStringContainsString('<p>Text</p>', $result);
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/PageCreatorServiceTest.php --filter="resolveImagePlaceholders" -v`

Expected: FAIL — method does not exist.

- [ ] **Step 3: Add ResourceFactory dependency to PageCreatorService**

In `Classes/Service/PageCreatorService.php`, add import:

```php
use TYPO3\CMS\Core\Resource\ResourceFactory;
```

Update constructor (line 46-48):

```php
public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ResourceFactory $resourceFactory,
) {}
```

- [ ] **Step 4: Implement resolveImagePlaceholders**

Add this private method to `PageCreatorService`:

```php
/**
 * Replace <img data-image-slot="0"> placeholder with a real image URL,
 * or remove it if no image was selected.
 */
private function resolveImagePlaceholders(string $bodytext, int $imageUid): string
{
    $pattern = '/<img\b[^>]*\bdata-image-slot="0"[^>]*>/i';

    if ($imageUid <= 0) {
        return preg_replace($pattern, '', $bodytext) ?? $bodytext;
    }

    try {
        $file = $this->resourceFactory->getFileObject($imageUid);
        $publicUrl = $file->getPublicUrl();
    } catch (\Throwable) {
        // File deleted or invalid — remove placeholder
        return preg_replace($pattern, '', $bodytext) ?? $bodytext;
    }

    if ($publicUrl === null || $publicUrl === '') {
        return preg_replace($pattern, '', $bodytext) ?? $bodytext;
    }

    return preg_replace_callback(
        $pattern,
        static function (array $matches) use ($publicUrl): string {
            $tag = $matches[0];

            // Extract alt attribute if present
            $alt = '';
            if (preg_match('/\balt="([^"]*)"/', $tag, $altMatch)) {
                $alt = $altMatch[1];
            }

            return '<img src="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8')
                . '" alt="' . $alt . '">';
        },
        $bodytext,
    ) ?? $bodytext;
}
```

- [ ] **Step 5: Run tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/PageCreatorServiceTest.php --filter="resolveImagePlaceholders" -v`

Expected: ALL PASS.

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/PageCreatorService.php Tests/Unit/Service/PageCreatorServiceTest.php
git commit -m "feat: add resolveImagePlaceholders to PageCreatorService"
```

---

### Task 5: PageCreatorService — html CType guard in createLandingPage

**Files:**
- Modify: `Classes/Service/PageCreatorService.php:86-119`
- Modify: `Tests/Unit/Service/PageCreatorServiceTest.php`

- [ ] **Step 1: Write failing tests — html CType skips sys_file_reference**

Uses the existing `DataHandler::start` callback assertion pattern (same as `textCTypeWithImageIsUpgradedToTextpic` at line 496):

```php
#[Test]
public function htmlCtypeResolvesImageIntoBodytextInsteadOfSysFileReference(): void
{
    $file = $this->createMock(File::class);
    $file->method('getPublicUrl')->willReturn('/fileadmin/hero.jpg');

    $resourceFactory = $this->createMock(ResourceFactory::class);
    $resourceFactory->method('getFileObject')->with(42)->willReturn($file);

    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $dh->expects(self::once())->method('start')
        ->with(self::callback(function (array $dataMap): bool {
            $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
            // CType stays html (not upgraded to textpic)
            if (($element['CType'] ?? '') !== 'html') {
                return false;
            }
            // bodytext contains resolved src URL
            if (!str_contains($element['bodytext'] ?? '', 'src="/fileadmin/hero.jpg"')) {
                return false;
            }
            // No data-image-slot placeholder remaining
            if (str_contains($element['bodytext'] ?? '', 'data-image-slot')) {
                return false;
            }
            // No sys_file_reference created
            return !isset($dataMap['sys_file_reference']);
        }), []);

    $service = $this->createService($dh, resourceFactory: $resourceFactory);
    $service->createLandingPage($this->createTemplate(), 1, 'T', '/t', [], [
        ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '',
         'bodytext' => '<section><img data-image-slot="0" alt="Hero shot"></section>', 'imageUid' => 42],
    ]);
}

#[Test]
public function htmlCtypeRemovesPlaceholderWhenNoImageSelected(): void
{
    $dh = $this->createMockDataHandler(['NEW_page' => 1, 'NEW_content_0' => 10]);
    $dh->expects(self::once())->method('start')
        ->with(self::callback(function (array $dataMap): bool {
            $element = $dataMap['tt_content']['NEW_content_0'] ?? [];
            // CType stays html
            if (($element['CType'] ?? '') !== 'html') {
                return false;
            }
            // Placeholder removed, text preserved
            $body = $element['bodytext'] ?? '';
            return !str_contains($body, '<img') && str_contains($body, '<p>Text</p>');
        }), []);

    $service = $this->createService($dh);
    $service->createLandingPage($this->createTemplate(), 1, 'T', '/t', [], [
        ['section' => 'Hero', 'ctype' => 'html', 'header' => 'H', 'subheader' => '',
         'bodytext' => '<section><img data-image-slot="0" alt="Hero"><p>Text</p></section>', 'imageUid' => 0],
    ]);
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/PageCreatorServiceTest.php --filter="htmlCtype" -v`

Expected: FAIL — current code upgrades html to textpic when imageUid > 0.

- [ ] **Step 3: Implement the html CType guard**

In `Classes/Service/PageCreatorService.php`, in the content element loop (after line 94 `$ctype = ...`), add before the existing upgrade logic at line 98:

```php
// For html CType, resolve image placeholder into bodytext
// instead of creating a sys_file_reference record
if ($ctype === 'html') {
    $element['bodytext'] = $this->resolveImagePlaceholders(
        (string) ($element['bodytext'] ?? ''),
        $imageUid,
    );
    // Reset imageUid so the upgrade logic below is skipped
    $imageUid = 0;
}
```

This resets `$imageUid` to 0 so the existing upgrade block (`if ($imageUid > 0 && $imageField === '')`) is naturally skipped.

- [ ] **Step 4: Run all PageCreatorService tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/Service/PageCreatorServiceTest.php -v`

Expected: ALL PASS.

- [ ] **Step 5: Run PHPStan**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpstan analyse Classes/Service/PageCreatorService.php --level=6`

Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/PageCreatorService.php Tests/Unit/Service/PageCreatorServiceTest.php
git commit -m "feat: html CType resolves image placeholders into bodytext"
```

---

## Chunk 3: Frontend — Wizard Image UI for Creative Sections

### Task 6: Wizard — image selection panel for creative sections

**Files:**
- Modify: `Resources/Public/JavaScript/wizard.js:843-952`

- [ ] **Step 1: Add image panel to renderCreativeContentSections**

In `Resources/Public/JavaScript/wizard.js`, inside the `sections.forEach((section, index) => {` loop in `renderCreativeContentSections` (line 860), insert the image selection panel **between** the preview and source code blocks — after `cardBody.appendChild(preview)` (line 931) and **before** `cardBody.appendChild(source)` (line 947). This places it below the HTML preview and above the source code toggle, matching the spec:

```javascript
// Image selection (only for sections with imageKeywords)
const keywords = section.imageKeywords || [];
if (keywords.length > 0) {
    const imageSection = document.createElement('div');
    imageSection.className = 'mt-3 border-top pt-3';

    const imageLabel = document.createElement('small');
    imageLabel.className = 'text-body-secondary d-block mb-2';
    imageLabel.textContent = this.label('wizard.content.imageSuggestions');
    imageSection.appendChild(imageLabel);

    // Show image generation error if present
    const imageError = (WizardState.imageErrors || [])[index];
    if (imageError) {
        const errorAlert = document.createElement('div');
        errorAlert.className = 'alert alert-warning alert-sm py-1 px-2 mb-2';
        errorAlert.style.fontSize = '0.85em';
        errorAlert.textContent = this.label('wizard.content.imageGenerationError') + ' ' + imageError;
        imageSection.appendChild(errorAlert);
    }

    const imageList = document.createElement('div');
    imageList.className = 'd-flex gap-2 flex-wrap mb-2';

    const images = WizardState.getImages();
    const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];
    this.renderImageCards(imageList, sectionImages, index);

    // Show info when automatic search found no images
    if (sectionImages.length === 0 && keywords.length > 0) {
        const emptyInfo = document.createElement('div');
        emptyInfo.className = 'alert alert-info py-2 px-3 mb-2';
        emptyInfo.style.fontSize = '0.85em';
        emptyInfo.textContent = this.label('wizard.content.imageAutoSearchEmpty', keywords.join(', '));
        imageSection.appendChild(emptyInfo);
    }

    imageSection.appendChild(imageList);

    // Search input pre-filled with AI keywords
    const searchRow = document.createElement('div');
    searchRow.className = 'd-flex gap-2 align-items-center flex-wrap';

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control form-control-sm';
    searchInput.placeholder = this.label('wizard.content.imageSearchPlaceholder');
    searchInput.style.maxWidth = '250px';
    searchInput.value = keywords.join(' ');

    const searchBtn = this.createIconButton(
        'actions-search',
        this.label('wizard.content.imageSearchButton'),
        'btn btn-sm btn-outline-secondary',
        async () => {
            const query = searchInput.value.trim();
            if (!query) return;
            searchBtn.disabled = true;
            try {
                const result = await this.fetchJson(this.getAjaxUrl('searchImages'), { query });
                const found = result.images || [];
                if (found.length === 0) {
                    Notification.info(this.label('wizard.content.imageSearchEmpty'));
                } else {
                    this.renderImageCards(imageList, found, index);
                }
            } catch (err) {
                Notification.error(this.label('wizard.error.imageSearch'), err.message);
            } finally {
                searchBtn.disabled = false;
            }
        },
    );

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchBtn.click();
        }
    });

    searchRow.appendChild(searchInput);
    searchRow.appendChild(searchBtn);

    // AI Generate button
    const aiAvailable = WizardState.aiGenerationAvailable || false;
    const hasImageTask = WizardState.hasImageTask || false;
    if (aiAvailable && hasImageTask) {
        const generateBtn = this.createIconButton(
            'actions-bolt',
            this.label('wizard.content.imageGenerateButton'),
            'btn btn-sm btn-outline-warning',
            async () => {
                generateBtn.disabled = true;
                generateBtn.textContent = this.label('wizard.content.imageGenerating');
                try {
                    const template = WizardState.getTemplate();
                    const sectionData = WizardState.getContentSections()[index] || {};
                    const result = await this.fetchJson(this.getAjaxUrl('generateImage'), {
                        templateUid: template.uid,
                        imagePrompt: sectionData.imagePrompt || '',
                        sectionHeader: sectionData.header || sectionData.section || '',
                    });
                    const img = result.image;
                    if (img) {
                        this.renderImageCards(imageList, [img], index);
                        Notification.success(this.label('wizard.content.imageGenerated'));
                    }
                } catch (err) {
                    Notification.error(this.label('wizard.error.imageGenerate'), err.message);
                } finally {
                    generateBtn.disabled = false;
                    this.setIconButtonLabel(generateBtn, this.label('wizard.content.imageGenerateButton'));
                }
            },
        );
        searchRow.appendChild(generateBtn);
    }

    imageSection.appendChild(searchRow);
    cardBody.appendChild(imageSection);
}
```

**Note on label keys:** The image panel reuses the same label keys as structured mode (`wizard.content.imageSuggestions`, `wizard.content.imageSearchPlaceholder`, etc.). These already exist in `locallang.xlf` and `de.locallang.xlf`. No new labels needed.

- [ ] **Step 2: Verify `renderCreativeContentSections` receives images**

Check that the call site (line 604-605) passes images to `renderCreativeContentSections`. Currently it does not pass `images`:

```javascript
// Line 604-605 currently:
if (generationMode === 'creative') {
    this.renderCreativeContentSections(container, sections);
```

The image data is available in `WizardState.getImages()` (set at line 598), so the image panel code uses `WizardState.getImages()` directly — no method signature change needed.

- [ ] **Step 3: Manual test in browser**

1. Open DDEV site, create a landing page with the LP-Fastlane template (creative mode)
2. Verify the wizard shows HTML previews as before
3. For sections where the LLM provides `imageKeywords`, verify the image search/selection panel appears
4. Select an image, finish the wizard, verify the page is created with the image in bodytext

- [ ] **Step 4: Commit**

```bash
git add Resources/Public/JavaScript/wizard.js
git commit -m "feat: add image selection UI to creative mode wizard"
```

---

## Chunk 4: Integration Test & Cleanup

### Task 7: Full integration verification

- [ ] **Step 1: Run all unit tests**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpunit Tests/Unit/ -v`

Expected: ALL PASS.

- [ ] **Step 2: Run PHPStan on all modified files**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/phpstan analyse Classes/Service/ContentGeneratorService.php Classes/Service/CreativeHtmlSanitizer.php Classes/Service/PageCreatorService.php --level=6`

Expected: No errors.

- [ ] **Step 3: Run PHP-CS-Fixer**

Run: `cd /srv/projects/nr-landingpage && vendor/bin/php-cs-fixer fix --dry-run --diff`

Expected: No issues or only minor formatting.

- [ ] **Step 4: Manual end-to-end test**

1. Generate a creative-mode landing page
2. Verify some sections have image placeholders, others don't
3. Select images for sections that have them
4. Save the page
5. View the page in frontend — images should appear inline in the HTML
6. Generate again without selecting any image — placeholder should be removed cleanly

- [ ] **Step 5: Update documentation if needed**

Check `Documentation/` and `README.md` for any mentions of creative mode limitations regarding images. Update if the docs claim creative mode cannot use images.
