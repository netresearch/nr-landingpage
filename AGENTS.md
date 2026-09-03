# AGENTS.md

> AI agent guide for the `nr_landingpage` TYPO3 extension

## Project Overview

This is a TYPO3 v13.4+ extension that generates landing pages via LLM using
an interactive step-by-step backend wizard. Editors select a template, answer
briefing questions, review AI-generated content with images, and place the
page in the page tree.

**Key characteristics:**
- PHP 8.2+ / TYPO3 v13.4+ / v14.x
- Depends on `netresearch/nr-llm` for LLM communication
- Backend wizard (MultiStepWizard modal) with five steps
- Template-driven generation with configurable content types
- FAL image search + AI image generation
- Re-generation support via stored metadata

## Directory Structure

```
nr_landingpage/
├── Classes/
│   ├── ContextMenu/               # Page tree context menu integration
│   ├── Controller/Backend/        # Backend module + wizard AJAX controller
│   ├── Domain/Model/              # Template, GenerationContext
│   ├── EventListener/             # PSR-14 event listeners
│   ├── Form/                      # TCA form elements (fieldControl, fieldInformation)
│   └── Service/                   # Business logic
│       ├── BackendLayoutService   # Column map resolution
│       ├── BriefingService        # Briefing question generation
│       ├── ContentGeneratorService # LLM content generation (structured + creative)
│       ├── CreativeHtmlSanitizer  # HTML sanitizer for creative mode
│       ├── CTypeMetadataService   # Content type metadata
│       ├── ImageProviderService   # FAL search + AI image orchestration
│       ├── ImageSearchService     # FAL metadata search
│       ├── LandingPageDetectionService # Check if page is generated
│       ├── PageCreatorService     # DataHandler page/content creation
│       ├── PromptOptimizerService # AI prompt optimization
│       └── TemplateService        # Template CRUD + access control
├── Configuration/
│   ├── Backend/                   # Module registration, AJAX routes
│   ├── TCA/                       # Template record TCA
│   └── Services.yaml              # Dependency injection
├── Documentation/                 # RST documentation (TYPO3 docs standard)
├── Resources/
│   ├── Private/
│   │   ├── Language/              # XLF translations (en, de)
│   │   └── Templates/             # Fluid templates
│   └── Public/
│       └── JavaScript/            # ES modules (wizard, form controls)
├── Tests/
│   ├── Unit/                      # Unit tests (PHPUnit 11)
│   ├── Functional/                # TYPO3 functional tests
│   └── Architecture/              # PHPat architecture tests
└── Build/
    └── Scripts/runTests.sh        # Docker-based test runner
```

## Coding Standards

### PHP Style
- **PSR-12** with TYPO3 conventions via PHP-CS-Fixer
- **Strict types**: All files must have `declare(strict_types=1);`
- **Typed properties**: All class properties must be typed
- **Return types**: All methods must have return type declarations

### JavaScript
- ES modules (no AMD/RequireJS)
- TYPO3 module imports via `@typo3/` prefix
- Localization via `TYPO3.lang['key']` with English fallback

### Architecture Rules
- Services are the primary business logic layer
- Controller handles HTTP only — delegates to services
- No direct DB queries in controllers
- TCA fieldControl/fieldInformation must use only allowed HTML tags:
  `<a><br><div><em><i><p><strong><span><code>`

## Testing

Tests run in Docker containers via `Build/Scripts/runTests.sh`.

| Test Type | Location | Command |
|-----------|----------|---------|
| Unit | `Tests/Unit/` | `./Build/Scripts/runTests.sh -p 8.4 -s unit` |
| Functional | `Tests/Functional/` | `./Build/Scripts/runTests.sh -p 8.4 -s functional` |
| PHPStan | - | `./Build/Scripts/runTests.sh -p 8.4 -s phpstan` |
| CGL | - | `./Build/Scripts/runTests.sh -p 8.4 -s cgl` |

**Important:** Always use `-p 8.4` flag — the extension requires PHP 8.4+.

## Key Files

| File | Purpose |
|------|---------|
| `ext_tables.sql` | DB schema (template table + pages columns) |
| `ext_localconf.php` | Extension bootstrap, node registry |
| `Configuration/Services.yaml` | DI container configuration |
| `Configuration/TCA/tx_nrlandingpage_domain_model_template.php` | Template record TCA |
| `Configuration/Backend/AjaxRoutes.php` | Wizard AJAX endpoints |
| `Configuration/Backend/Modules.php` | Backend module registration |
| `Resources/Public/JavaScript/wizard.js` | Main wizard logic |
| `Resources/Public/JavaScript/wizard-state.js` | Wizard state management |

## Wizard Architecture

The wizard is a TYPO3 MultiStepWizard modal with five steps:

1. **Template** — Select a landing page template
2. **Briefing** — Answer optional questions about the page topic
3. **Page Fields** — Review AI-generated title, SEO metadata, slug
4. **Content** — Review/edit AI-generated sections with images
5. **Placement** — Choose parent page and save

Communication between wizard (JS) and backend is via AJAX routes
defined in `AjaxRoutes.php`, handled by
`LandingPageWizardController`.

## Generation Metadata & Re-Generation

Generated pages store metadata in custom `pages` columns:
- `tx_nrlandingpage_template_uid` — Template used
- `tx_nrlandingpage_briefing_data` — Serialized briefing answers
- `tx_nrlandingpage_config_hash` — Template config hash at generation time
- `tx_nrlandingpage_generated_at` — Generation timestamp
- `tx_nrlandingpage_source_page_uid` — Source page for re-generation

This enables re-generation: the wizard opens with pre-filled data from
the original page.

## Image Handling

`ImageProviderService` orchestrates image sourcing:
1. Search TYPO3 FAL by AI-generated keywords
2. Optionally generate images via configured nr-llm image task
3. Store generated images in FAL for reuse

**Pending:** FAL output persistence will move to nr-llm
(see [t3x-nr-llm#107](https://github.com/netresearch/t3x-nr-llm/issues/107)).
Once implemented, nr-landingpage will simplify to: trigger task → FAL search.

## Generation Modes

Templates support two generation modes via the `generation_mode` field:

**Structured** (default): AI generates standard TYPO3 content elements (text,
textmedia, etc.). Each section becomes a separate `tt_content` record.

**Creative HTML**: AI generates self-contained HTML+CSS+SVG per layout column.
Content is stored as `html` CType elements. Key constraints:
- CSS-only (no JavaScript, no `<script>` tags)
- Inline SVG only (no external images)
- CSS `url()` blocked (no external resources)
- `CreativeHtmlSanitizer` enforces all security rules

When creative mode is selected, `allowed_ctypes` and `image_task` TCA fields
are hidden via `displayCond`.

## Security Considerations

- LLM responses are treated as untrusted — HTML is sanitized server-side
- **Structured mode**: `TYPO3\HtmlSanitizer` with whitelist (p, ul, ol, li, strong, em, a, h2-h4)
- **Creative mode**: `CreativeHtmlSanitizer` strips `<script>`, event handlers,
  `javascript:` protocols, `data:` URIs, CSS `url()`, and dangerous tags
  (`<iframe>`, `<object>`, `<embed>`, `<form>`)
- Image generation errors return generic messages, details are logged only
- MIME type validation on AI-generated images before FAL storage
- TCA fieldInformation uses only TYPO3-allowed HTML tags (architecture test enforced)
- Modal content passed as DOM elements, never as HTML strings (Lit auto-escaping)

## Output Language

Generated content language is determined from the TYPO3 site's default
language via `SiteFinder`. The language instruction is injected into every
LLM prompt regardless of the prompt language.

## Useful Commands

```bash
# Testing (Docker-based, recommended)
./Build/Scripts/runTests.sh -p 8.4 -s unit         # Unit tests
./Build/Scripts/runTests.sh -p 8.4 -s functional    # Functional tests
./Build/Scripts/runTests.sh -p 8.4 -s phpstan       # Static analysis
./Build/Scripts/runTests.sh -p 8.4 -s cgl -n        # PHP-CS-Fixer (dry-run)
./Build/Scripts/runTests.sh -p 8.4 -s cgl           # PHP-CS-Fixer (fix)

# Documentation
docker run --rm -v $(pwd):/project \
  ghcr.io/typo3-documentation/render-guides:latest \
  --config=/project/Documentation
```

## Contact

- **Issues**: https://github.com/netresearch/t3x-nr-llm/issues
- **Dependency**: `netresearch/nr-llm` ^0.34
