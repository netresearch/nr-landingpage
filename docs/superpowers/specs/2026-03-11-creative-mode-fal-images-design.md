# Design: Optional FAL Images in Creative HTML Mode

**Date:** 2026-03-11
**Status:** Approved

## Problem

The Creative HTML mode generates self-contained HTML+CSS fragments with inline SVGs for visuals. Real photographs from TYPO3's FAL (File Abstraction Layer) are not available — unlike Structured mode, which supports FAL image search and AI image generation per section.

This limits visual quality: SVG illustrations work for decorative elements, but hero images, team photos, product shots, and testimonials benefit from real photography.

## Decision

The LLM decides per section whether a photograph would improve the content. If yes, it places exactly one `<img data-image-slot="0">` placeholder in the HTML and provides `imageKeywords` + `imagePrompt` for FAL search/AI generation. The editor selects a real image in the wizard. On save, the placeholder is replaced with the FAL public URL — or removed if no image was selected.

## Design

### 1. Prompt Change (`ContentGeneratorService::buildCreativePrompt`)

Update design rule 4 from:

> Erstelle Bilder als Inline-SVG [...] KEINE `<img>`-Tags mit src-Attribut.

To:

> Fuer dekorative Grafiken (Icons, Muster, abstrakte Formen) verwende Inline-SVG.
> Wenn ein Foto den Inhalt visuell bereichert (Hero-Bild, Teaser, Team-Portrait,
> Referenz-Foto), setze genau EIN `<img data-image-slot="0" alt="Beschreibung">`
> pro Section. Kein src-Attribut — das Bild wird spaeter aus der Mediathek zugeordnet.
> Nicht jede Section braucht ein Foto — verwende es nur wo es den Inhalt staerkt.

Extend JSON example to include optional image fields:

```json
{"section": "Name", "colPos": 0, "header": "Titel",
 "bodytext": "<style>...</style><section>...<img data-image-slot=\"0\" alt=\"...\">...</section>",
 "imageKeywords": ["keyword1", "keyword2"],
 "imagePrompt": "Detailed English image description..."}
```

Add instruction:

> Wenn du einen `<img data-image-slot="0">` Platzhalter setzt, liefere `imageKeywords`
> (3-5 englische Suchbegriffe fuer die Mediathek) und `imagePrompt` (detaillierter
> englischer Bild-Prompt). Ohne Platzhalter: leeres Array / leerer String.

### 2. Validation (`ContentGeneratorService::validateCreativeSections`)

Change from hardcoded empty values:

```php
'imageKeywords' => [],
'imagePrompt' => '',
```

To parsing from response (same logic as `validateSections`):

```php
$imageKeywords = [];
if (is_array($item['imageKeywords'] ?? null)) {
    foreach ($item['imageKeywords'] as $kw) {
        if (is_string($kw) && trim($kw) !== '') {
            $imageKeywords[] = trim($kw);
        }
    }
}

// ...
'imageKeywords' => $imageKeywords,
'imagePrompt' => is_string($item['imagePrompt'] ?? null) ? $item['imagePrompt'] : '',
```

### 3. Sanitizer (`CreativeHtmlSanitizer`)

Allow `<img>` tags that have `data-image-slot` but NO `src` attribute:

- `<img data-image-slot="0" alt="Team photo">` — ALLOWED (placeholder)
- `<img src="https://evil.com/x.jpg">` — BLOCKED (external resource)
- `<img src="data:image/png;base64,...">` — BLOCKED (data URI)

Implementation: In the tag processing logic, if the tag is `<img>` and has `data-image-slot` and does NOT have `src`, preserve it. Otherwise apply existing removal rules.

**Note:** The existing test `sanitizePreservesImgWithHttpSrc` asserts that `<img src="https://...">` is preserved. This test must be updated to expect removal when the sanitizer is changed.

### 4. Image Placeholder Resolution (`PageCreatorService`)

New private method:

```php
private function resolveImagePlaceholders(string $bodytext, int $imageUid): string
```

- Called in `createLandingPage` inside the content-element loop, **only when CType is `html`**
- If `$imageUid > 0`: resolve FAL file public URL via `ResourceFactory`, replace placeholder `<img ... data-image-slot="0" ...>` with `<img src="{publicUrl}" alt="...">`
- If `$imageUid === 0`: remove the placeholder `<img>` tag entirely
- Regex must handle `data-image-slot` appearing in any attribute position:
  ```
  /<img\b[^>]*\bdata-image-slot="0"[^>]*>/
  ```
- When extracting `alt`, use a sub-pattern: `alt="([^"]*)"`
- Error handling: if `ResourceFactory` cannot resolve the file UID (deleted/invalid), fall back to removing the placeholder (same as `$imageUid === 0`)

**CType upgrade guard** — the existing upgrade logic (lines 98-103) upgrades CType to `textpic` when an image is selected but the CType has no image field. For `html` CType, the image goes INTO the bodytext instead. Add an early guard:

```php
// For html CType, resolve image into bodytext instead of sys_file_reference
if ($ctype === 'html' && $imageUid > 0) {
    $element['bodytext'] = $this->resolveImagePlaceholders(
        (string)($element['bodytext'] ?? ''), $imageUid
    );
    // Skip sys_file_reference creation — image is inline
    $dataMap['tt_content'][$newContentId] = $element;
    $contentUidMap[] = $newContentId;
    continue;
}

if ($ctype === 'html' && $imageUid === 0) {
    // Remove unfilled placeholder from bodytext
    $element['bodytext'] = $this->resolveImagePlaceholders(
        (string)($element['bodytext'] ?? ''), 0
    );
}
```

This guard must appear **before** the existing `if ($imageUid > 0 && $imageField === '')` block (line 99).

### 5. Wizard Frontend

The **structured mode** wizard (`renderContentSections`, lines 623-835) renders image search/selection UI per section when `imageKeywords` is non-empty. The **creative mode** wizard (`renderCreativeContentSections`, lines 843-952) currently has **no image UI** — it only shows HTML preview iframes and source code editors.

Changes needed in `renderCreativeContentSections`:

- After the HTML preview card, check if `section.imageKeywords` is a non-empty array
- If yes, render the same image selection panel used in structured mode:
  - Image cards container (search results + AI-generated)
  - Search input pre-filled with `imageKeywords.join(' ')`
  - Search button (calls `searchImages` endpoint)
  - AI generation button (calls `generateImage` endpoint)
- Reuse `renderImageCards` (lines 963-1058) — it already handles deduplication, selection, and badges
- Store selected image UID in `WizardState.selectedImages[index]` (same as structured mode)

The image panel renders **below** the HTML preview iframe and **above** the source code toggle. It only appears for sections where the LLM provided `imageKeywords`.

### 6. Tests

| Test file | Test case | Asserts |
|-----------|-----------|---------|
| `CreativeHtmlSanitizerTest` | `allowsImagePlaceholderWithoutSrc` | `<img data-image-slot="0" alt="x">` preserved |
| `CreativeHtmlSanitizerTest` | `blocksImgWithSrcAttribute` | `<img src="...">` removed (existing) |
| `ContentGeneratorServiceValidationTest` | `creativeValidationReadsImageKeywords` | keywords parsed from response |
| `ContentGeneratorServiceValidationTest` | `creativeValidationDefaultsEmptyKeywords` | missing keywords → `[]` |
| `PageCreatorServiceTest` | `resolveImagePlaceholdersReplacesWithUrl` | placeholder → `<img src="...">` |
| `PageCreatorServiceTest` | `resolveImagePlaceholdersRemovesWhenNoImage` | placeholder removed |
| `PageCreatorServiceTest` | `resolveImagePlaceholdersHandlesReorderedAttributes` | `<img alt="x" data-image-slot="0">` → replaced |
| `PageCreatorServiceTest` | `htmlCtypeSkipsSysFileReference` | No `sys_file_reference` row for `html` CType |

## Out of Scope

- Multiple image slots per section (future enhancement)
- Image cropping/sizing in the wizard
- Automatic image position/layout suggestions
