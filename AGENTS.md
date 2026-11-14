<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# AGENTS.md

> AI agent guide for the `nr_landingpage` TYPO3 extension

## Project Overview

This is a TYPO3 v13.4+ extension that generates landing pages via LLM using
an interactive step-by-step backend wizard. Editors select a template, answer
briefing questions, review AI-generated content with images, and place the
page in the page tree.

**Key characteristics:**
- PHP 8.2+ / TYPO3 v13.4 LTS / v14.3 LTS
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
│   ├── Command/                   # Console commands (import of the shipped templates)
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
│       ├── SectionNormalizer      # Typed sections for the wizard save and workspace drafts
│       ├── TemplateService        # Template CRUD + access control
│       └── Workspace/             # Content Workspace: drafts, share links, audit (ADR-0001)
├── Configuration/
│   ├── Backend/                   # Module registration, AJAX routes
│   ├── ShippedTemplates.json      # Template records that ship with the extension
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

The examples use PHP 8.4. The extension requires PHP ^8.2 (`composer.json`),
and CI tests PHP 8.2, 8.3 and 8.4 (`.github/workflows/ci.yml`).

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
- No JavaScript: scripts and event handler attributes are removed; animation
  comes from per-section metadata, as in structured mode
- Inline SVG only (no external images); `<img>` only as `data-image-slot` placeholder
- CSS resource functions (`url()`, `image-set()`, ...) and `@import` removed
- `CreativeHtmlSanitizer` (allowlist on `typo3/html-sanitizer`) and
  `CreativeCssFilter` implement these rules

When creative mode is selected, `allowed_ctypes` and `image_task` TCA fields
are hidden via `displayCond`.

## Security Considerations

- LLM responses are treated as untrusted — HTML is sanitized server-side
- **Structured mode**: `TYPO3\HtmlSanitizer` with whitelist (p, ul, ol, li, strong, em, a, h2-h4)
- **Creative mode**: `CreativeHtmlSanitizer` keeps only allowlisted HTML/SVG
  elements and attributes (no scripts, no event handlers, links limited to
  http(s)/mailto/tel/local), `CreativeCssFilter` removes CSS that loads resources
- **Endpoint access**: AJAX routes are reachable by every backend user, so each
  action checks `BackendAccessGuard` (module access, template edit rights,
  page permissions); FAL search returns only files the user may read
- Image generation errors return generic messages, details are logged only
- MIME type validation on AI-generated images before FAL storage
- TCA fieldInformation uses only TYPO3-allowed HTML tags (architecture test enforced)
- Modal content passed as DOM elements, never as HTML strings (Lit auto-escaping)

## Content Workspace

Drafts of landing page content that people without a TYPO3 login review by
share link, before the page is created. The design and the security
decisions are in [`docs/adr/0001-content-workspace-share-links.md`](docs/adr/0001-content-workspace-share-links.md);
read it before changing anything here.

- Tables `tx_nrlandingpage_workspace_*` have no TCA and are written only through the repositories in `Classes/Service/Workspace/`.
- A share link's token is shown once and never stored: only its prefix and SHA-256 hash (`ShareToken`). Never log it, put it in a rendered page or in an exception message.
- `DraftAccessGuard` decides who sees and shares a draft; a draft a user may not see is "not found".
- State-changing backend routes (`nr_landingpage_workspace_*`) are covered by `WorkspaceRouteTokenTest`; add a new route to its data provider.
- The review page (`ReviewPageMiddleware`, `Controller/Frontend/ReviewController`, `Service/Workspace/Review/`) answers `/nr-landingpage/review` before the site is resolved. Every refusal is the same 404; keep it that way when adding a check (`ReviewPageTest::everyKindOfRefusedEntryLooksTheSame`). Test through `executeFrontendSubRequest`; settings for a sub request are written to LocalConfiguration, and the rate limiters' state outlives a test, so each test uses its own source address.
- Approval (`ApprovalService`) and accepting a suggestion (`SuggestionService`) are backend-only; no link level reaches them. The page of an approval carries `tx_nrlandingpage_workspace_ref` (`<draft uid>:<approval_attempt>`) from its first insert, and finalising, reverting and resetting are all guarded by that attempt: keep every new step that ends an approval behind the same guard (`ApprovalTest`).
- An approval creates the owner's content with the approver's rights, so `ApprovalContentPolicy` limits it (ADR-0001, section 9): without template lists only `text`, `textmedia`, `textpic` and the title, description and SEO page fields. A new step that creates content from a draft goes through the same check, and every workspace AJAX route is POST-only.
- `workspace.*` extension configuration is read by `WorkspaceSettings` from `TYPO3_CONF_VARS`, not through `ExtensionConfiguration::get()`, which can write `LocalConfiguration` during a frontend request.

## Output Language

Generated content language is determined from the TYPO3 site's default
language via `SiteFinder`. The language instruction is injected into every
LLM prompt regardless of the prompt language.

## Changelog

- Changes are recorded in [`CHANGELOG.md`](CHANGELOG.md), [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), newest first, below `## [Unreleased]`. The sections up to 0.3.26 are the GitHub release notes, copied when the file was created.
- The release PR `chore(release): X.Y.Z` (branch `release/vX.Y.Z`) writes the version's section as `## [X.Y.Z] - YYYY-MM-DD` with `### Added`, `### Changed`, `### Fixed` as needed, adds its compare link at the end of the file and moves the `[Unreleased]` link to compare from the new tag.

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

- **Issues**: https://github.com/netresearch/nr-landingpage/issues
- **Dependency**: `netresearch/nr-llm` ^0.43.10 || ^0.44 || ^0.45 || ^0.46
