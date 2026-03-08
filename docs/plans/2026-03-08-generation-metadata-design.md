# Generation Metadata & Re-Generate Feature

## Goal

Store generation inputs (template, briefing, config hash) on created pages so editors can re-generate pages with pre-filled data. New pages are created as hidden drafts alongside the original.

## DB Fields (pages table)

```sql
CREATE TABLE pages (
    tx_nrlandingpage_template_uid int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_briefing_data text,
    tx_nrlandingpage_config_hash varchar(64) DEFAULT '',
    tx_nrlandingpage_generated_at int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_source_page_uid int(11) unsigned DEFAULT 0
);
```

Fields are NOT shown in TCA — purely for programmatic use.

- `template_uid`: Which template was used
- `briefing_data`: JSON with briefing answers and wizard inputs
- `config_hash`: SHA-256 of content-relevant template fields (for future staleness detection)
- `generated_at`: Unix timestamp of generation
- `source_page_uid`: Original page UID when re-generating (0 for first generation)

## Config Hash

SHA-256 over: `systemPrompt`, `allowedCTypes` (sorted), `pageFields` (sorted), `backendLayout`, `llmConfiguration`, `imageTask`.

NOT hashed: `title`, `description`, `briefingMode`, `publishMode`, `beGroups`, `promptOptimizerContext`, `promptOptimizerMetaPrompt`.

Method: `Template::getConfigHash(): string`

## GenerationContext Value Object

```php
final readonly class GenerationContext
{
    public function __construct(
        public array $briefingAnswers = [],
        public int $sourcePageUid = 0,
    ) {}
}
```

## Page Creation Changes

`PageCreatorService::createLandingPage()` gets new `GenerationContext` parameter. Writes the 5 fields into `$pageData` alongside existing fields. No separate DB write needed.

## Save Action Changes

`LandingPageWizardController::saveAction()` passes `briefingAnswers` and `sourcePageUid` from request body through to PageCreatorService.

JS `saveLandingPage()` includes `WizardState.getBriefingAnswers()` and optional `sourcePageUid` in the save request.

## New AJAX Endpoint

`GET generation-info?pageUid=X` returns:
```json
{
  "templateUid": 6,
  "briefingData": {"answers": {...}},
  "configHash": "abc123...",
  "generatedAt": 1741392000,
  "sourcePageUid": 0
}
```

## Re-Generate Flow

1. Context menu on pages with `tx_nrlandingpage_template_uid > 0`: "Re-Generate Landing Page"
2. Wizard opens in re-generate mode:
   - Template pre-selected (step 1 shows template info, no selection)
   - Briefing pre-filled from stored `briefing_data` (editable)
   - Content generation runs fresh (steps 3+4 normal)
3. On save: new hidden page created next to original
   - `source_page_uid` = original page UID
   - `hidden` = 1 always (regardless of template publishMode)
   - Same `parentPageId` as original

## Template Page Count

Show count of generated pages per template as `fieldInformation` in template TCA. Dynamic `COUNT(*)` query on `pages.tx_nrlandingpage_template_uid` — no cached counter field needed.

## Not In Scope

- TCA display of generation metadata on pages
- Hash staleness comparison in UI
- Automated re-generation via scheduler
- Version comparison UI
