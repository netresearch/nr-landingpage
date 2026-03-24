# TYPO3 Landing Page Generator (nr_landingpage)

A TYPO3 extension that generates landing pages via LLM using an interactive step-by-step backend wizard.
Editors select a template, answer optional briefing questions, review AI-generated content, and publish --
all without leaving the TYPO3 backend.

## Requirements

| Dependency       | Version           |
|------------------|-------------------|
| PHP              | ^8.2              |
| TYPO3            | 13.4 LTS / 14.x  |
| netresearch/nr-llm | >=0.4 <1.0     |

## Installation

```bash
composer require netresearch/nr-landingpage
```

Activate the extension in the TYPO3 Extension Manager or via CLI:

```bash
vendor/bin/typo3 extension:activate nr_landingpage
```

## Configuration

### Templates

Templates are managed as TCA records (`tx_nrlandingpage_domain_model_template`) and define:

- **Title / Identifier** -- human-readable name and machine identifier
- **System Prompt** -- the LLM prompt used for content generation
- **Allowed CTypes** -- which content element types the template may produce
- **Page Fields** -- which page-level fields (e.g. `og:title`, `description`) are generated
- **Reference Pages** -- existing pages used as style/structure reference for the LLM
- **Briefing Mode** -- `required`, `optional`, or `none`
- **Publish Mode** -- whether pages are created hidden or visible
- **Backend User Groups** -- restrict template visibility to specific groups

### LLM Configuration

The extension relies on `netresearch/nr-llm` for LLM interaction.
Configure your LLM backend (OpenAI, Azure OpenAI, etc.) according to the nr-llm documentation.
Each template can optionally reference a specific LLM configuration record.

## Usage

### Wizard Steps

1. **Template Selection** -- choose from available landing page templates
2. **Briefing** -- answer LLM-generated questions to guide content creation (optional/required per template)
3. **Page Fields** -- review and edit generated SEO and meta fields
4. **Content Preview** -- review generated content sections; regenerate individual sections as needed
5. **Placement & Save** -- choose parent page, set title/slug, and create the landing page

### Context Menu

Right-click any page in the page tree and select "Create Landing Page" to launch the wizard
with that page pre-selected as the parent.

### PSR-14 Events

The extension dispatches events that allow customization:

- `BeforePageCreationEvent` -- modify page data before the page record is written
- `AfterContentGenerationEvent` -- modify generated content sections before they are saved

## Development

### Setup

```bash
git clone <repository-url>
composer install
```

### Local TYPO3 Environment (DDEV)

A DDEV configuration is included for local development with a full TYPO3 installation:

```bash
ddev start
ddev install-v14
```

This installs TYPO3 v14 with the extension and all dependencies (including `nr-llm` and `nr-vault` via Composer).

- **Backend:** https://v14.nr-landingpage.ddev.site/typo3/
- **Credentials:** admin / Joh316!!

> **Traefik users:** If ports 80/443 are already in use (e.g. by an external Traefik proxy),
> DDEV will fall back to alternate ports. You can disable the DDEV router globally
> (`router: none` in `~/.ddev/global_config.yaml`) and route traffic through your
> existing Traefik by adding the `traefik` network and labels to
> `.ddev/docker-compose.web.yaml`. See the [DDEV documentation on router configuration](https://ddev.readthedocs.io/en/stable/users/configuration/config/#router).

### Quality Checks

```bash
# Run all checks
composer ci

# Individual checks
composer ci:phpstan      # PHPStan Level 10
composer ci:cgl          # PHP-CS-Fixer (dry-run)
composer ci:tests        # PHPUnit (unit + architecture tests)

# Fix code style
composer fix:cgl
```

### Test Suites

| Suite          | Directory            | Description                    |
|----------------|----------------------|--------------------------------|
| unit           | `Tests/Unit/`        | Unit tests (mocked services)   |
| functional     | `Tests/Functional/`  | Functional tests (TYPO3 framework) |
| architecture   | `Tests/Architecture/` | phpat layer dependency tests  |
| e2e            | `Tests/E2E/`         | Playwright E2E tests (skeleton) |

### E2E Tests (Playwright)

```bash
cd Tests/E2E
npm install
npx playwright install
npx playwright test
```

Requires a running TYPO3 instance. Set `TYPO3_BASE_URL` to override the default `https://nr-landingpage.ddev.site`.

### Architecture

```
Classes/
  Controller/Backend/    Wizard controller (AJAX endpoints)
  ContextMenu/           Page tree context menu provider
  Domain/Model/          Template value object
  Event/                 PSR-14 events
  Service/               Business logic (Template, Briefing, ContentGenerator, ImageSearch, PageCreator)
Configuration/
  Backend/               Module + AJAX route registration
  TCA/                   Template TCA
Resources/
  Public/JavaScript/     ES6 wizard modules
Tests/
  Unit/                  PHPUnit unit tests
  Architecture/          phpat architecture tests
  E2E/                   Playwright E2E test skeleton
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
