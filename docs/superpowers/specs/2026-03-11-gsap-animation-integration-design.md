# GSAP Animation Integration — Design Spec

## Goal

Integrate GSAP (Core + ScrollTrigger + TextPlugin) into the landing page
generator to enable modern JavaScript-driven animations in both Creative
and Structured mode, lifting the visual quality of generated pages to the
level of premium SaaS landing pages (Stripe, Linear, Vercel, Apple).

## Background

Creative mode currently enforces CSS-only animations (no `<script>` tags
allowed). While CSS can do transitions and keyframes, the most impressive
landing page effects — scroll-triggered reveals, pinned sections,
staggered card entrances, typewriter text — require JavaScript. GSAP is
the industry standard animation library, well-known to LLMs, lightweight,
and free for commercial use.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│ Template Record                                         │
│  animation_enabled: bool (default: true)                │
├─────────────────────────────────────────────────────────┤
│ LLM Generation                                          │
│  Creative Mode: full GSAP via <script data-creative>    │
│  Structured Mode: animation object per section in JSON  │
├─────────────────────────────────────────────────────────┤
│ PageCreatorService                                      │
│  1st pass: page + content elements (DataHandler)        │
│  2nd pass: GSAP loader + animation script (DataHandler) │
├─────────────────────────────────────────────────────────┤
│ Frontend                                                │
│  GSAP loaded via html CType element (sorting=1)         │
│  Animations target #c{uid} selectors                    │
├─────────────────────────────────────────────────────────┤
│ CreativeHtmlSanitizer                                   │
│  <script data-creative>: allowlist check                │
│  <script> without attribute: stripped (unchanged)       │
└─────────────────────────────────────────────────────────┘
```

## 1. GSAP Vendor Bundle

### Files

```
Resources/Public/JavaScript/vendor/gsap/
└── 3/
    ├── gsap.min.js          (~30kb gzip)
    ├── ScrollTrigger.min.js (~12kb gzip)
    └── TextPlugin.min.js   (~3kb gzip)
```

### Versioning & Retention Policy

- Each GSAP **major** version lives in its own subdirectory (`3/`, `4/`).
  Minor/patch updates replace the files in-place within the major directory.
- Maximum **2 major versions** shipped simultaneously (current + previous).
- When bumping to GSAP N+2, version N is removed from the extension.
- The exact GSAP version string (e.g. `3.12.5`) is stored in a dedicated
  page metadata field so we always know which pages depend on which version.
- Example: extension ships with `3/` and `4/`. When `5/` arrives,
  `3/` is dropped. Pages generated with GSAP 3.x should be re-generated
  or manually verified.

### License

GSAP Core, ScrollTrigger, and TextPlugin are free for commercial use
under the GSAP Standard License. No Club membership required. The
license prohibits selling GSAP as part of a competing animation tool,
which does not apply here.

## 2. Template Option

New field on the template record (`tx_nrlandingpage_domain_model_template`):

| Field              | Type    | Default | Description                                |
|--------------------|---------|---------|--------------------------------------------|
| `animation_enabled`| boolean | `true`  | Enable GSAP animations for generated pages |

When disabled:
- No GSAP loader element is created
- Creative mode: `<script data-creative>` blocks are stripped by sanitizer
- Structured mode: `animation` objects in LLM response are ignored
- Prompt does not mention GSAP availability

Default is **enabled**. Users who want lightweight, JS-free pages
disable it per template.

The `animation_enabled` field **must** be included in the template's
`getConfigHash()` calculation so that toggling it flags pages as
potentially needing regeneration.

## 3. GSAP Loader Element

When `animation_enabled` is true, `PageCreatorService` creates an
additional `html` CType content element:

| Property      | Value                                              |
|---------------|----------------------------------------------------|
| `CType`       | `html`                                             |
| `header`      | `[Animation Library]`                              |
| `header_layout` | `100` (hidden, not rendered in frontend)         |
| `sorting`     | `1` (before first content element at 256)          |
| `colPos`      | `0`                                                |
| `bodytext`    | `<script>` tags loading GSAP + plugin registration |

This element is created **regardless of the template's allowed CTypes**.
The allowed content types setting only applies to LLM-generated content
elements. The loader is infrastructure, not content.

### Bodytext content

```html
<script src="/typo3conf/ext/nr_landingpage/Resources/Public/JavaScript/vendor/gsap/3/gsap.min.js" defer></script>
<script src="/typo3conf/ext/nr_landingpage/Resources/Public/JavaScript/vendor/gsap/3/ScrollTrigger.min.js" defer></script>
<script src="/typo3conf/ext/nr_landingpage/Resources/Public/JavaScript/vendor/gsap/3/TextPlugin.min.js" defer></script>
<script data-creative>
gsap.registerPlugin(ScrollTrigger, TextPlugin);
</script>
```

The `src` paths are resolved at save time using the current GSAP major
version directory. Scripts use `defer` to avoid blocking page rendering.

### GSAP Version Storage

No dedicated column. The GSAP major version is implicit in the
`<script src>` paths of the loader element's bodytext (e.g.
`.../vendor/gsap/3/gsap.min.js`). For rare queries like "which pages
use GSAP 3.x", a `LIKE '%/gsap/3/%'` on `tt_content.bodytext` suffices.

## 4. Creative Mode

### LLM Prompt Changes

The prompt tells the LLM:
- GSAP, ScrollTrigger, and TextPlugin are globally available
- All `<script>` blocks **must** carry the `data-creative` attribute
- Provides the JS allowlist so the LLM knows what APIs are permitted

### Script Authoring

The LLM writes `<script data-creative>` blocks within its HTML
fragments. Example:

```html
<style>.hero-x { opacity: 0; transform: translateY(40px); }</style>
<section class="hero-x">
  <h1>Headline</h1>
  <p>Subtext</p>
</section>
<script data-creative>
gsap.to('.hero-x', {
  scrollTrigger: '.hero-x',
  opacity: 1, y: 0, duration: 0.8
});
gsap.to('.hero-x h1', {
  scrollTrigger: '.hero-x',
  text: { value: 'Animated Headline' },
  duration: 1.5
});
</script>
```

### Sanitizer Changes

`CreativeHtmlSanitizer` is extended with a new code path. The existing
blanket regex (`#<script\b[^>]*>.*?</script>#is`) is replaced with a
two-branch approach:

- `<script>` **without** `data-creative` → stripped (unchanged behavior)
- `<script data-creative>` → **allowlist check** on script content:
  - If content passes allowlist → preserved
  - If content contains disallowed API → entire script block stripped

This is additive — existing creative mode pages have no scripts, so
backward compatibility is not affected.

## 5. Structured Mode (Two-Pass)

### LLM Response Extension

The LLM JSON response gains an optional `animation` field per section:

```json
{
  "section": "Features",
  "ctype": "textmedia",
  "header": "Why Choose Us",
  "bodytext": "...",
  "animation": {
    "type": "fade-up",
    "duration": 0.8,
    "delay": 0,
    "stagger": 0.15
  }
}
```

The `animation` field is **optional**. Not every section needs animation.
The LLM decides per section whether animation adds value.

### Animation Validation

- Unknown animation types (e.g. LLM hallucinates `"bounce-crazy"`) →
  ignored, no animation applied for that section
- Numeric fields clamped to reasonable ranges:
  - `duration`: 0.1 – 3.0 seconds
  - `delay`: 0.0 – 2.0 seconds
  - `stagger`: 0.05 – 0.5 seconds
- Missing/empty/null animation → silently skipped

### Two-Pass Save Flow

**Pass 1:** Standard DataHandler run — creates page record and all
content elements (text, textmedia, header, etc.). No animation data
in tt_content.

**Pass 2:** After `substNEWwithIDs` resolves real UIDs:
1. Create GSAP loader element (header `[Animation Library]`, sorting=1)
2. Build animation script targeting `#c{uid}` selectors
3. Create animation script element (header `[Animation Script]`,
   sorting=MAX+1, after all content)
4. Save both via second DataHandler run

**Error handling:** Pass 2 failure is **non-fatal**. The page is saved
successfully without animations, and the error is logged. This matches
the "animation is optional" philosophy.

Example generated script:

```html
<script data-creative>
gsap.from('#c123', {
  scrollTrigger: '#c123',
  opacity: 0, y: 40, duration: 0.8
});
gsap.from('#c456 > *', {
  scrollTrigger: '#c456',
  opacity: 0, y: 20, stagger: 0.15, duration: 0.6
});
// #c789 — no animation (LLM decided none needed)
</script>
```

### Animation Data Storage

Animation configuration is stored in the **page generation metadata**
(alongside briefing data, template UID, config hash). It is NOT stored
in tt_content fields. This keeps content elements clean and animation
as a generation artifact.

## 6. Sanitizer Allowlist

### Threat Model

The allowlist is a **defense-in-depth** layer, not a sandbox. The
primary trust boundary is the LLM prompt (which is controlled by the
extension). The sanitizer catches accidental dangerous output and
provides a safety net against prompt injection attempts.

The allowlist operates via **substring matching** on the script content.
This is intentionally simple and conservative — if any blocked substring
appears anywhere in the script block, the entire block is stripped.

This approach has known limitations (e.g. bracket notation bypass via
`window['ev'+'al']`). For production environments requiring stronger
guarantees, a CSP `script-src` with nonces should be configured
alongside the sanitizer. See the CSP section below.

### Initial Allowlist

The following identifiers are allowed inside `<script data-creative>`:

**GSAP Core:**
`gsap`, `.to(`, `.from(`, `.fromTo(`, `.set(`, `.timeline(`,
`.registerPlugin(`, `.utils`, `.defaults(`

**ScrollTrigger:**
`ScrollTrigger`, `.create(`, `.batch(`, `.refresh(`

**TextPlugin:**
`TextPlugin`, `text:`

**DOM Access (read-only):**
`document.querySelector`, `document.querySelectorAll`

**JS Fundamentals:**
`const`, `let`, `var`, `function`, `=>`, `forEach`, `map`,
`setTimeout`, `setInterval`, `requestAnimationFrame`

### Blocklist (explicit safety net)

These are **explicitly** blocked — if any appears as substring, the
entire script block is stripped:

`fetch`, `XMLHttpRequest`, `eval`, `Function(`, `import(`,
`require(`, `document.cookie`, `document.write`, `localStorage`,
`sessionStorage`, `window.location`, `window.open`,
`navigator.sendBeacon`, `innerHTML`, `outerHTML`, `postMessage`,
`Worker(`, `ServiceWorker`, `WebSocket`, `globalThis`, `self[`,
`window[`, `top[`, `parent[`, `frames[`

### Future: User-Configurable Allowlist

A later iteration could allow template administrators to extend the
allowlist via a textarea field on the template record. This enables
power users to add custom libraries or APIs without an extension update.

## 7. Prompt Adjustments

### Creative Mode Prompt

Replace the current `KEIN JavaScript` rule with:

```
JAVASCRIPT-ANIMATIONEN:
GSAP (gsap), ScrollTrigger und TextPlugin sind global verfuegbar.
Nutze sie fuer Scroll-Animationen, Reveals, Typewriter-Effekte,
Parallax und alles was die Seite lebendig macht.
REGELN:
- Jeder <script>-Block MUSS das Attribut data-creative tragen.
- Erlaubte APIs: gsap.*, ScrollTrigger.*, TextPlugin.*,
  document.querySelector/All, Standard-JS (const, let, =>, forEach).
- VERBOTEN: fetch, XMLHttpRequest, eval, document.cookie,
  localStorage, window.location, innerHTML und alle Netzwerk-APIs.
- Verwende die CSS-Klassen-Praefixe der Section als Selektoren.
```

### Structured Mode Prompt

Add to the JSON response instructions:

```
Optional pro Section: "animation" Objekt.
Moegliche Typen: fade-up, fade-down, slide-left, slide-right,
zoom-in, scale-up, stagger-children, typewriter, parallax.
Nicht jede Section braucht Animation — setze sie gezielt ein.
Format: {"type": "fade-up", "duration": 0.8, "delay": 0, "stagger": 0.15}
Alle Felder ausser "type" sind optional.
```

## 8. Accessibility: `prefers-reduced-motion`

All generated animation scripts must respect the user's OS-level
motion preference. The GSAP loader element includes a global
`matchMedia` configuration:

```js
ScrollTrigger.matchMedia({
  '(prefers-reduced-motion: no-preference)': function() {
    // animations are registered only when motion is allowed
  }
});
```

For creative mode, the prompt instructs the LLM to wrap GSAP calls
in a `prefers-reduced-motion` check. For structured mode, the
generated animation script includes the check automatically.

This satisfies WCAG 2.3.3 (Animation from Interactions).

## 9. Content Security Policy (CSP)

The GSAP loader and animation scripts use inline `<script>` tags
and external `<script src="...">` tags. This has CSP implications:

- **Inline scripts** (`<script data-creative>`) require either
  `'unsafe-inline'` or nonce-based CSP in the frontend
- **External scripts** (`<script src="...">`) require the extension's
  public resource path in `script-src`

The extension does NOT automatically modify frontend CSP. The
documentation must include a CSP configuration section explaining
which directives are needed when CSP is active on the frontend.

For sites without frontend CSP (common in TYPO3), no action needed.

## 10. Testing

### Unit Tests

**Sanitizer:**
- `<script data-creative>` with allowed GSAP calls → preserved
- `<script data-creative>` with blocked API (e.g. `fetch(`) → stripped
- `<script data-creative>` with mixed allowed/blocked → stripped (fail-safe)
- `<script>` without `data-creative` → stripped (unchanged behavior)
- Bracket-notation bypass attempts (`window['fetch']`) → stripped
- Edge cases: encoded entities, multiline, nested quotes

**PageCreatorService:**
- GSAP loader element created with correct header, sorting, script paths
- Loader element NOT created when `animation_enabled=false`
- Loader element created regardless of allowed CTypes setting
- Two-pass: animation script contains correct `#c{uid}` selectors
- Two-pass: sections without animation are skipped in script
- Two-pass: pass-2 failure is non-fatal (page saved, error logged)
- GSAP version implicit in loader element's script src paths

**ContentGeneratorService:**
- `animation` object in LLM response validated (optional, correct structure)
- Missing/empty/null animation tolerated (no error)
- Invalid animation type defaults to no animation
- Numeric values clamped to valid ranges
- Animation field ignored when `animation_enabled=false`

**Template:**
- `animation_enabled` defaults to `true`
- Field correctly read from template record
- `animation_enabled` included in config hash calculation

### Functional Tests

- Full flow: creative mode page with GSAP → loader + content + scripts saved
- Full flow: structured mode with animations → two-pass saves correct UIDs
- Versioning: GSAP path contains correct major version, exact version in page metadata
- Disabled animation: no loader, no scripts, clean content-only output

## 11. Documentation

### Configuration/Index.rst

- **Template field: Animation** — new confval documenting the boolean
  toggle, its default (enabled), and when to disable it
- **GSAP section** — which version is shipped, which plugins are included
  (Core + ScrollTrigger + TextPlugin), retention policy (max 2 major
  versions), license info
- **Allowlist reference** — which JS APIs are permitted in
  `<script data-creative>`, which are blocked, rationale
- **CSP configuration** — required CSP directives for sites with
  frontend Content Security Policy
- **Breaking change advisory** — GSAP major version updates may affect
  previously generated pages, recommendation to test after extension update

### Usage/Index.rst

- **Creative Mode** — expanded with GSAP capabilities: scroll animations,
  typewriter effects, section pinning, stagger, parallax. Example of
  `<script data-creative>` usage.
- **Structured Mode** — animation per section: LLM decides which sections
  benefit from animation, not every element must be animated
- **Animation template option** — how to disable for lightweight pages
- **Accessibility** — `prefers-reduced-motion` is automatically respected
- **GSAP update notice** — what to expect when the extension updates GSAP,
  how to verify existing pages still work
