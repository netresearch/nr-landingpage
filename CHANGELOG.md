<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The sections for 0.1.0 to 0.3.26 are the notes of the GitHub releases, copied
without rewording; their headings are the ones the release notes used, one
level lower.

## [Unreleased]

## [0.6.1] - 2026-11-13

### Changed

- nr-llm 0.46 is accepted beside 0.43.10 and later.

## [0.6.0] - 2026-11-13

### Added

- The briefing step has an optional *Source text* field. The page fields and the content are written from the pasted plain text, in structured and creative mode; the text is material, not an instruction, it is cut at 12000 characters and it is not stored with the page ([#127](https://github.com/netresearch/nr-landingpage/pull/127) by @CybotTM).
- A template field *Rules per content element* takes one rule per line as `ctype: rule`; the rules of the allowed types go into the structured content prompt as binding constraints, also when a section is regenerated. Product Launch, Event and SaaS Feature ship with rules ([#128](https://github.com/netresearch/nr-landingpage/pull/128) by @CybotTM).
- The placement step checks the generated page against SEO rules and lists notes on the title length, the meta description length, the order of the headings across the sections and missing alternative texts. The check only reports and never stops the save ([#129](https://github.com/netresearch/nr-landingpage/pull/129) by @CybotTM).
- A template sets the size of generated images (1024x1024, 1536x1024, 1024x1536, 1792x1024 or 1024x1792) and the folder they are stored in, instead of the fixed 1024x1024 and `generated-landingpage/`. The size also reaches fal.ai, which ignored it before. A chosen folder that cannot be used stores no image and is logged ([#130](https://github.com/netresearch/nr-landingpage/pull/130) by @CybotTM).

### Changed

- New columns `ctype_rules`, `image_size` and `image_folder` on the template table: run `extension:setup` (or the database schema update) after the update.

## [0.5.0] - 2026-11-13

### Added

- Four template records (Product Launch, Event, Creative One-Pager, SaaS Feature) appear in the Landing Page module right after extension setup. An installation that already had the extension gets them with its next `extension:setup`, and `nr-landingpage:import-templates` creates the missing ones again. A template an editor edited or deleted is never overwritten or brought back ([#124](https://github.com/netresearch/nr-landingpage/pull/124) by @CybotTM).
- In the structured content step of the wizard, sections can be moved up and down and removed; the page is created with the sections in the order shown. Images, image errors and guardrail hints move with their section, and Regenerate keeps regenerating the section's own answer after a move or removal ([#125](https://github.com/netresearch/nr-landingpage/pull/125) by @CybotTM).
- A *Preview page* button in the structured content step opens a frame that shows the page title and the sections in their current order with the chosen images and their alternative texts, and follows edits, moves, removals and image choices while it is open ([#126](https://github.com/netresearch/nr-landingpage/pull/126) by @CybotTM).

### Changed

- nr-llm 0.45 is accepted beside 0.43 and 0.44 (`^0.43.10 || ^0.44 || ^0.45`), so the extension installs together with nr-llm 0.45.0.

## [0.4.1] - 2026-11-12

### Changed

- nr-llm 0.44 is accepted beside 0.43 (`^0.43.10 || ^0.44`), so the extension installs together with nr-llm 0.44.0 and the nr-vault 1.5 it requires.

## [0.4.0] - 2026-11-12

### Added

- The wizard shows the notes of nr-llm's rule-set judge in report mode next to the generated text: each rule the text breaks, or that it could not be checked. They appear below the intro of the briefing, page-fields and content steps, in the card of a section regenerated on its own, above the preview of the template's full page generation and below the field of the prompt optimizer. The log keeps a refusal of nr-llm's guardrails without the passage it quotes. Needs the nr-llm release with the rule-set judge; before it nothing changes ([#118](https://github.com/netresearch/nr-landingpage/pull/118) by @CybotTM).

### Fixed

- `Build/Scripts/runTests.sh` mounts the git directory of a git worktree into its containers, so git works there when the extension is checked out as a worktree, for example of a bare repository; before, every git call in a container failed with `fatal: not a git repository`. A plain clone gets no extra mount ([#116](https://github.com/netresearch/nr-landingpage/pull/116) by @CybotTM).

## [0.3.26] - 2026-11-04

### Highlights

- The template's LLM configuration select says what the chosen configuration is for and which model and provider it runs on; the image task select says what the chosen task does and which configuration it runs on. The text follows the choice, also with the arrow keys, and screen readers read it. Next to the selects, buttons create a configuration and open nr-llm's list of configurations or tasks, each shown only to users who may use it ([#111](https://github.com/netresearch/nr-landingpage/pull/111) by @CybotTM).
- A wizard step that fails to load keeps Next locked and offers Retry, so a failed content step can no longer lead to a page without content elements ([#110](https://github.com/netresearch/nr-landingpage/pull/110) by @CybotTM).
- Requires nr-llm 0.43.10.

## [0.3.25] - 2026-11-02

### Highlights

- The template field that switches AI images on offers image prompts only: the nr-llm tasks marked "Landing page: image prompt", in their own group after "Media Library only", which stays first and the default. A template keeps a task it already uses; while no task is marked, every task stays available. The field's label and description say what the choice does: it switches generation on and supplies a fallback image prompt; the image model comes from nr-llm, not from the task ([#108](https://github.com/netresearch/nr-landingpage/pull/108) by @CybotTM).
- The wizard's LLM calls run on the configuration the parent page's site names in `nrllm.configuration` when the template uses "Use Default"; a configuration chosen on the template still wins. AI image generation keeps the installation's settings ([#106](https://github.com/netresearch/nr-landingpage/pull/106) by @CybotTM).
- Requires nr-llm 0.43.5.

## [0.3.24] - 2026-11-02

### Highlights

- The wizard's parent page is chosen in TYPO3's page browser instead of typed as a number, on TYPO3 13.4 and 14.3. The page selected in the page tree is preselected, and a page the editor may not use (outside the page tree, no permission to create pages there, or a page type without subpages) is refused with the reason ([#104](https://github.com/netresearch/nr-landingpage/pull/104) by @CybotTM).
- After OK the wizard shows that the landing page is being created and locks Generate, so a double click cannot create it twice; a failed save says what happened, whether the page may exist anyway, what to do and whom to ask ([#104](https://github.com/netresearch/nr-landingpage/pull/104) by @CybotTM).
- The template field that selects an LLM configuration is labelled "LLM Configuration" instead of "AI Model"; the template form has one Access tab, and the default items of the configuration and image selects carry an icon ([#103](https://github.com/netresearch/nr-landingpage/pull/103) by @CybotTM).

## [0.3.23] - 2026-11-01

### Highlights

- A guardrail stop reaches the editor with its reason ([#99](https://github.com/netresearch/nr-landingpage/pull/99) by @CybotTM).
- A site can show an AI notice on generated pages, off by default ([#100](https://github.com/netresearch/nr-landingpage/pull/100), [#101](https://github.com/netresearch/nr-landingpage/pull/101) by @CybotTM).
- nr-llm 0.43 is accepted ([#102](https://github.com/netresearch/nr-landingpage/pull/102) by @CybotTM).

## [0.3.22] - 2026-11-01

### Highlights

nr-landingpage 0.3.22 accepts nr-llm 0.42, so an installation can move to [nr-llm 0.42.0](https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.42.0) (#98 by @CybotTM).

- `composer.json` adds `^0.42` to the `netresearch/nr-llm` requirement and `ext_emconf.php` declares `0.34.0-0.42.99`; the README, `AGENTS.md` and the installation guide state the same range. nr-llm 0.42 adds `defer()` to `AgentRuntimeInterface`, which this extension does not implement.
- Since 0.3.21 the repository otherwise received only CI changes.

## [0.3.21] - 2026-10-20

### Highlights

nr-landingpage 0.3.21 accepts nr-llm 0.41, so an installation can move to nr-llm 0.41.0.

- `composer.json` adds `^0.41` to the `netresearch/nr-llm` requirement and `ext_emconf.php` declares `0.34.0-0.41.99`; the README, `AGENTS.md` and the installation guide state the same range. nr-llm 0.41 adds members to three interfaces this extension does not implement, and every other changed signature adds an optional parameter — by @CybotTM in #92.
- Since 0.3.20 the repository otherwise received only Renovate updates of CI actions and Playwright.

## [0.3.20] - 2026-10-08

### Highlights

nr-landingpage 0.3.20 accepts nr-llm 0.40, so an installation can move to nr-llm 0.40.0.

- `composer.json` adds `^0.40` to the `netresearch/nr-llm` requirement and `ext_emconf.php` declares `0.34.0-0.40.99`; the README, `AGENTS.md` and the installation guide state the same range. nr-llm 0.40 changes no class or method this extension calls or implements — by @CybotTM in #87.
- `composer.json` accepts three `enshrined/svg-sanitize` advisories published on 2026-10-08 (GHSA-9rjx-3jch-6vjf, GHSA-m9xh-6747-9r6f, GHSA-v383-3rw5-q8rf). They are fixed only in 1.0.0, which `typo3/cms-core` 13.4 and 14.3 (`~0.22`) do not admit; each ignore says to remove it once core allows the fix (#87).

## [0.3.19] - 2026-10-07

### Highlights

nr-landingpage 0.3.19 accepts nr-llm 0.39, so an installation can move to nr-llm 0.39.0.

- `composer.json` adds `^0.39` to the `netresearch/nr-llm` requirement and `ext_emconf.php` declares `0.34.0-0.39.99`; the README, `AGENTS.md` and the installation guide state the same range. The breaking changes of nr-llm 0.39 touch no class or method this extension calls or implements — by @CybotTM in #86.
- The extension is called "Landing Page Generator" in `ext_emconf.php`, the Composer description and the README (#85).

## [0.3.18] - 2026-10-07

### Highlights

The extension no longer ships the GSAP library. GSAP is distributed under the GSAP Standard License, which is not OSI-approved; the extension is GPL-2.0-or-later. Animations now come from a small runtime of the extension. `composer require netresearch/nr-landingpage:^0.3` picks it up; requirements are unchanged.

#### Animations

- Generated pages animate with `Resources/Public/JavaScript/frontend/animations.js`, configured through a JSON data block. The animation types stay the same. Pages need no inline script, so a Content Security Policy that allows scripts from the site's own origin is enough. The runtime writes no inline style, does nothing when the visitor prefers reduced motion, and shows every section when a page is printed (#83 by @CybotTM).
- `GsapService` is removed; `AnimationLoaderService` takes its place in `PageCreatorService`'s constructor (#83 by @CybotTM).

#### Pages generated with GSAP

- These pages keep their animation elements, "[Animation Library]" and "[Animation Script]". The files they load are now a small stand-in of the extension. It keeps the text of the extension's own animation script, including typewriter sections, and never changes styles (#83 by @CybotTM).
- Creative pages also keep the scripts the LLM wrote into their sections. These scripts are not emulated: content they hide and expect GSAP to show again stays hidden. **Re-generate creative pages created with GSAP**, and check them before that. For structured pages, re-generating or deleting the two animation elements is enough. The chapter "Pages Generated With GSAP" in the usage documentation has the details.

#### Maintenance

- The organisation's typo3-extension workflow template is adopted. Pull requests now run Composer Audit, Opengrep, Dependency Review, the licence check, CodeQL, Betterleaks and zizmor. AGENTS.md points to this repository's issue tracker (#83 by @CybotTM).

## [0.3.17] - 2026-10-06

### Highlights

A security release. Generated creative content passes a real HTML allowlist filter, and the wizard's backend actions check module access, page permissions and file mounts. `composer require netresearch/nr-landingpage:^0.3` picks it up; see *Upgrading* for the new `typo3/html-sanitizer` requirement.

#### Security

- Creative HTML is filtered with an allowlist built on `typo3/html-sanitizer`. Scripts, event handlers and external resources are no longer kept in generated content, and the test-generate preview builds its markup without inserting HTML into the backend page (#81 by @CybotTM).
- Every wizard action checks access to the landing page module. Actions that name a page check the page permissions, saving checks the right to create pages below the parent, image search returns only files the user may read, and the template tools (optimize prompt, test generate) require modify rights on the template table; they answer 403 otherwise (#81 by @CybotTM).

#### Behaviour changes

- `<script data-creative>` blocks are no longer kept in generated creative content. Creative pages with animation enabled get their animation from the per-section presets (for example `fade-up`) instead of a generated script (#81 by @CybotTM).
- `CreativeHtmlSanitizer::sanitize()` no longer takes the `$allowScripts` parameter (#81 by @CybotTM).

#### Upgrading

- The filter applies when content is generated. Creative content stored by earlier versions is not filtered again: review or re-generate creative landing pages created before 0.3.17.
- `typo3/html-sanitizer ^2.3.2` is now a direct requirement. TYPO3 core up to 13.4.30 and 14.3.2 requires `^2.2.0`; a project on such a core version with that package locked below 2.3.2 updates with `composer update -w netresearch/nr-landingpage`, or with `-W` when its own `composer.json` requires `typo3/html-sanitizer`.

#### Maintenance

- OpenSSF documentation, SPDX notices and a JavaScript lint fix (#79 by @CybotTM).

## [0.3.16] - 2026-09-29

### Highlights

A patch release of fixes to the backend module and the generation wizard: the extension now follows the TYPO3 light and dark colour scheme, the wizard's keyboard and focus behaviour is corrected, and answers and replies no longer get lost or land in the wrong place. No breaking changes; `composer require netresearch/nr-landingpage:^0.3` picks it up.

#### Light and dark scheme, keyboard use

- The landing page module, the wizard, the preview dialog and the FormEngine and page-module helpers follow TYPO3's light and dark scheme on 13.4 and 14.3. Badges, icons, muted text, buttons, the selected template card and the loading spinners use core classes and tokens instead of Bootstrap classes that keep one colour or have no rules in the 14.3 backend (#69 by @CybotTM).
- The template cards in step 1 are a radio group: arrow keys move the selection, Space and Enter select, and screen readers announce each card's description. Arrow keys inside a step no longer slide the wizard past a locked Next button (#69 by @CybotTM).
- Enter that a control inside the step has already handled no longer advances the wizard: Enter in the image search only searches, and Enter on a template or image card only selects it (#72 by @CybotTM).
- After Next, Enter or the automatic advance in re-generate mode, focus moves to the first field of the new step, or to Next, Previous or Cancel when the step has no field, instead of staying on the page body (#78 by @CybotTM, fixes #77 reported by @CybotTM).

#### Answers and replies stay where they belong

- Going Back and Next again keeps the briefing title and answers; a title typed and cleared stays empty (#69 by @CybotTM).
- In re-generate mode, Back to the template step no longer jumps forward again and keeps the briefing edits (#74 by @CybotTM, fixes #73 reported by @CybotTM).
- A reply that arrives after the wizard was closed and reopened, or for a step or section the editor has already left, is dropped: it no longer moves, locks, fills or saves into the open wizard. Focus stays on Back after going back (#76 by @CybotTM, fixes #75 reported by @CybotTM).

#### Maintenance

- Both content-step renderers share one image section and intro; the wizard's behaviour is unchanged and a new end-to-end test pins the image section in structured and creative mode (#71 by @CybotTM).
- `Build/Scripts/runTests.sh -s functional` runs the 22 functional tests instead of reporting success with no test executed, and an empty test selection now fails the run. CI was not affected (#70 by @CybotTM, addresses #64 reported by @CybotTM).

## [0.3.15] - 2026-09-27

nr_landingpage 0.3.15 accepts nr-llm [v0.38.0](https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.38.0) and installs only on the TYPO3 long-term-support releases 13.4 and 14.3.

### Changed

- nr-llm `^0.34 || ^0.35 || ^0.36 || ^0.37 || ^0.38`: an installation with this extension can update to nr-llm 0.38. On a 0.x version, `^0.37` does not admit 0.38.0. (#68, @CybotTM)
- **Breaking for TYPO3 14.0 to 14.2:** the extension requires TYPO3 13.4 LTS or 14.3 LTS. Installations on the non-LTS releases 14.0, 14.1 and 14.2 get no new versions; update TYPO3 to 14.3 first. (#67, @CybotTM)
- Dependency updates for GitHub Actions now come from Renovate only; the Dependabot configuration is removed. (#66, @CybotTM)

**Full changelog:** https://github.com/netresearch/nr-landingpage/compare/v0.3.14...v0.3.15

## [0.3.14] - 2026-09-24

### Changes

Accepts nr-llm 0.37, in #65 by @CybotTM. `composer.json` requires `netresearch/nr-llm` at `^0.34 || ^0.35 || ^0.36 || ^0.37`, and `ext_emconf.php` declares `nr_llm 0.34.0-0.37.99` to match. On a 0.x version `^0.36` does not admit 0.37.0, so 0.3.13 kept an installation from moving to [nr-llm 0.37.0](https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.37.0). Nothing else changes.

## [0.3.13] - 2026-09-23

### Highlights

nr-landingpage is licensed under GPL-2.0-or-later throughout, and it accepts `netresearch/nr-llm` 0.36.

### Licence

The `LICENSE` file now holds the GNU GPL version 2 text instead of MIT, so the repository states one licence: GPL-2.0-or-later, the grant `composer.json` and `CONTRIBUTING.md` already declared. The code was previously published under MIT, which permits redistribution under GPL-2.0-or-later. Changed in #61 by @CybotTM, closing #60.

### Dependencies

`netresearch/nr-llm` is accepted at `^0.34 || ^0.35 || ^0.36` (`ext_emconf.php`: `0.34.0-0.36.99`). The range is widened, not moved: installations on nr-llm 0.34 or 0.35 keep working. Changed in #63 by @CybotTM.

### Repository

`.bestpractices.json` provides evidence-backed answers for the OpenSSF Best Practices badge, including the test suites CI runs (#59, #62 by @CybotTM).

## [0.3.12] - 2026-09-18

Install this wherever `netresearch/contexts` is installed too. Until now that combination made every landing-page template record unopenable in the backend: `/typo3/record/edit?edit[tx_nrlandingpage_domain_model_template][…]=edit` answered 500, not for the field that broke but for the whole record.

### What was wrong

`RuntimeException 1483890332` — *"Field controls must return an iconIdentifier"* — from `FieldControl.php:93`. The two controls on `system_prompt` were never the cause: both hardcode an icon and both return an empty array for a new record, which the caller skips before the check. The renderType simply never resolved to them, and `NodeFactory` has one fallback for a renderType it does not know — `UnknownElement`, whose result is non-empty and carries no icon.

`$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry']` is a plain array keyed by integer. This extension used the placeholder block `1700000001`–`1700000005`; `netresearch/contexts` uses `1700000001`–`1700000003`. Two keys belonged to both, the `ext_localconf.php` loaded later overwrote the earlier entry, and nothing reported it — no exception, no log line, no deprecation. Measured on a real installation, `contexts` writes last, so `optimizePrompt` and `testGenerate` were gone before FormEngine looked for them.

Note the asymmetry: `contexts` registers its three as *element* renderTypes, so had it lost the collision the result would have been a warning box in its own forms. Losing it on the field-control side is an outage.

### What changed

Five keys, fixed by @CybotTM in [#57](https://github.com/netresearch/nr-landingpage/pull/57): the four `nodeRegistry` entries and the `ContextMenu` provider now use registration timestamps, and the file says why they are not round numbers. Node names, priorities and classes are untouched, so anything reading the registry by name is unaffected — the keys are indices nothing looks up.

The `ContextMenu` key moved although nothing collides with it today. It sat three lines above the ones that did, in the same placeholder block, and leaving it there would have left the next reader a round number to copy.

### Upgrading

`composer update netresearch/nr-landingpage`. No configuration change, no migration, no database update.

## [0.3.11] - 2026-09-17

### Changes
- Merge pull request #56 from netresearch/release/v0.3.11
- chore(release): 0.3.11
- Merge pull request #55 from netresearch/chore/nr-llm-0.35
- docs: state the nr-llm range the extension now accepts
- fix(deps): widen the nr_llm range in ext_emconf.php too
- chore(deps): allow nr-llm 0.35 alongside 0.34
- chore(deps): update dependency phpat/phpat to ^0.11 || ^0.12 (#52)
- chore(deps): update dependency `@playwright`/test to v1.63.0 (#51)
- Merge pull request #1 from netresearch/renovate/configure
- Add renovate.json
- Merge pull request #50 from netresearch/fix/ignore-test-runner-artifacts
- fix: ignore the var/ directory the test runner leaves behind

## [0.3.10] - 2026-09-03

### Requires nr-llm 0.34

The `netresearch/nr-llm` floor moves to `^0.34`, and `ext_emconf.php` with it — its upper bound is now `0.34.99` rather than `0.99.99`, so the composer and TER install paths no longer disagree about everything above 0.34. By @CybotTM in #47.

[nr-llm 0.34.0](https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.34.0) has one breaking change: its backend modules left Administration for a shared `AI` section, and the container URL `/module/nrllm` is gone with no successor (ADR-183). This extension registers no module under that container and links to no nr-llm route, so the move reaches nothing here. Checked against the released 0.34.0 before the floor was raised: `phpstan` clean, 528 unit tests.

### The documented requirement was never the real one

`README.md`, `AGENTS.md` and `Documentation/Installation/Index.rst` named `netresearch/nr-llm` `^0.4.0` — which means `>=0.4.0 <0.5.0` and has therefore excluded the actual dependency for several releases, `^0.33` included. Nothing checks prose against `composer.json`, which is how it drifted. All three now state `^0.34`.

`docs/plans/2026-03-06-*` still say `0.4.0` on purpose: they are dated design records of what was planned that day, not statements about what the extension requires now.

## [0.3.9] - 2026-08-21

### Changes
- Merge pull request #46 from netresearch/release/0.3.9
- chore(release): 0.3.9
- Merge pull request #45 from netresearch/chore/nr-llm-033
- build: require netresearch/nr-llm ^0.33

## [0.3.8] - 2026-08-21

### Changes
- Merge pull request #44 from netresearch/release/0.3.8
- chore(release): 0.3.8
- Merge pull request #43 from netresearch/chore/nr-llm-032
- build: require netresearch/nr-llm ^0.32
- Merge pull request #42 from netresearch/feat/caller-source
- feat: name this extension as caller source on every nr-llm call

## [0.3.7] - 2026-08-20

### Highlights

**Accepts nr-llm 0.31**, the release whose Analytics module breaks usage and cost down per calling extension. Landing-page generation is one of the more expensive things an editor can trigger — a briefing turns into a full page of content elements — so being able to see that spend separated from everything else on the installation is worth having. Note that this extension does not yet name itself on its calls, so its usage currently lands in the *Unattributed* bucket of that breakdown; annotating it is a follow-up (@CybotTM).

#### Upgrading

A patch release: a dependency's minor is not this extension's minor. No behaviour change, no API change. Requires nr-llm ^0.31.

## [0.3.6] - 2026-08-19

### Highlights

**Accepts nr-llm 0.30.** The previous constraint stopped at `^0.29`, which meant an installation that wanted nr-llm 0.30 — for its caller-source attribution, or because another extension required it — could not have this extension at the same time. A caret constraint on a 0.x version excludes the next minor, so each nr-llm minor needs this step; here it is a raise rather than a widening, so 0.28 and 0.29 are no longer accepted (@CybotTM).

Nothing this extension consumes changed in nr-llm 0.30: the wizard templates, the generation flow and the SEO fields behave exactly as in 0.3.5.

#### Upgrading

A patch release. A dependency's minor is not this extension's minor — there is no behaviour change and no API change here. Requires nr-llm ^0.30.

## [0.3.5] - 2026-08-13

### Changes
- Merge pull request #32 from netresearch/release/0.3.5
- chore(release): 0.3.5
- Merge pull request #31 from netresearch/fix/wizard-first-step-empty
- Merge pull request #30 from netresearch/fix/root-css-as-visible-content
- fix(wizard): seed only this realm's modal, not top's
- fix(wizard): seed the modal handle core reads before it assigns it
- test(wizard): force the race instead of hoping for it
- test(wizard): pin the empty first step when the wizard is opened twice
- fix(generator): stop asking structured mode for a CSS rule it cannot place
- Merge pull request #29 from netresearch/ci/use-shared-e2e
- ci(e2e): call the shared workflow instead of standing TYPO3 up here
- Merge pull request #28 from netresearch/fix/export-ignore
- build: keep development paths out of the distributed package

## [0.3.4] - 2026-08-13

### Changes
- Merge pull request #27 from netresearch/release/0.3.4
- chore(release): 0.3.4
- Merge pull request #25 from netresearch/ci/run-e2e-suite
- docs(e2e): correct the prerequisites the suite actually has
- ci(e2e): follow the symlinks under _assets
- Merge pull request #24 from netresearch/fix/lint-excludes-build
- ci(e2e): write the report the artifact promises, and surface the server log
- ci(e2e): serve static files as files, and run extension:setup
- ci(e2e): pass --server-type to typo3 setup
- ci(e2e): pin the actions and drop npx from the suite run
- ci: run the Playwright suite
- fix(build): actually exclude .Build from the lint suite

## [0.3.3] - 2026-08-12

### Changes
- Merge pull request #23 from netresearch/release/0.3.3
- chore(release): 0.3.3
- Merge pull request #21 from netresearch/fix/modal-state-reset
- fix(wizard): start from an empty slide stack on every open

## [0.3.2] - 2026-08-12

### Changes
- Merge pull request #19 from netresearch/release/0.3.2
- chore(release): 0.3.2
- Merge pull request #18 from netresearch/fix/json-object-array-contract
- fix(llm): accept the response shapes json_object can actually produce

## [0.3.1] - 2026-08-10

### Changes
- Merge pull request #17 from netresearch/release/0.3.1
- chore(release): 0.3.1
- Merge pull request #16 from netresearch/build/require-nr-llm-0-28
- build(deps): require nr-llm ^0.28

## [0.3.0] - 2026-08-07

### Highlights

A dependency release: requires `netresearch/nr-llm ^0.26` (was `^0.25`), bumped by @CybotTM in #14.

Every `Netresearch\NrLlm\*` symbol this package references — ten of them — resolves against 0.26 unchanged, and the release's only removals (`CapabilityPermissionService` and its interface) are not used here, so no code change was needed.

nr-llm 0.26 in turn requires nr-vault `^0.14`, which replaces nr-vault's admin-only model with grantable operation permissions. Backend users who reach an API key through nr-llm need `tx_nrvault:secret.use`, and `secret.create` to store one.

Also corrects `Documentation/guides.xml`, whose `version` attribute had been left behind at `0.1` while `release` moved on to `0.2.0`.

## [0.2.0] - 2026-07-24

### Changes
- Merge pull request #13 from netresearch/feat/migrate-nr-llm-0.25
- feat(deps): migrate to nr-llm ^0.25 (0.2.0)

## [0.1.3] - 2026-07-22

### Changes
- Merge pull request #12 from netresearch/release/v0.1.3
- fix: allow netresearch/nr-llm ^0.23 (release v0.1.3)
- Merge pull request #11 from netresearch/ci/use-shared-reusables
- ci: run the CI graph once per commit
- ci: call shared typo3-ci-workflows ci.yml instead of inline actions

## [0.1.2] - 2026-07-22

This patch release repairs the extension documentation build. The `Documentation/guides.xml` file was corrupted and is now restored to a valid configuration, so the docs render correctly again. A dedicated docs-render CI job has been added to catch any future breakage of the documentation build before it lands.

No functional or runtime changes: the backend icon rework already shipped in v0.1.1. This is an additive 0.x patch, so any downstream project depending on `^0.1` picks it up automatically.

## [0.1.1] - 2026-07-21

### What changed

- **Icons**: the module and extension icons are redrawn in the TYPO3 v14 3-color style with v13 legacy tiles, replacing the flat teal-tile placeholders.

(Release infrastructure: TER publishing is disabled until the extension is registered on extensions.typo3.org — see [#9](https://github.com/netresearch/nr-landingpage/pull/9).)

Additive/non-breaking 0.x patch. See [#7](https://github.com/netresearch/nr-landingpage/pull/7).

## [0.1.0] - 2026-03-24

### Changes
Initial release

[Unreleased]: https://github.com/netresearch/nr-landingpage/compare/v0.6.1...HEAD
[0.6.1]: https://github.com/netresearch/nr-landingpage/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/netresearch/nr-landingpage/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/netresearch/nr-landingpage/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/netresearch/nr-landingpage/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/netresearch/nr-landingpage/compare/v0.3.26...v0.4.0
[0.3.26]: https://github.com/netresearch/nr-landingpage/compare/v0.3.25...v0.3.26
[0.3.25]: https://github.com/netresearch/nr-landingpage/compare/v0.3.24...v0.3.25
[0.3.24]: https://github.com/netresearch/nr-landingpage/compare/v0.3.23...v0.3.24
[0.3.23]: https://github.com/netresearch/nr-landingpage/compare/v0.3.22...v0.3.23
[0.3.22]: https://github.com/netresearch/nr-landingpage/compare/v0.3.21...v0.3.22
[0.3.21]: https://github.com/netresearch/nr-landingpage/compare/v0.3.20...v0.3.21
[0.3.20]: https://github.com/netresearch/nr-landingpage/compare/v0.3.19...v0.3.20
[0.3.19]: https://github.com/netresearch/nr-landingpage/compare/v0.3.18...v0.3.19
[0.3.18]: https://github.com/netresearch/nr-landingpage/compare/v0.3.17...v0.3.18
[0.3.17]: https://github.com/netresearch/nr-landingpage/compare/v0.3.16...v0.3.17
[0.3.16]: https://github.com/netresearch/nr-landingpage/compare/v0.3.15...v0.3.16
[0.3.15]: https://github.com/netresearch/nr-landingpage/compare/v0.3.14...v0.3.15
[0.3.14]: https://github.com/netresearch/nr-landingpage/compare/v0.3.13...v0.3.14
[0.3.13]: https://github.com/netresearch/nr-landingpage/compare/v0.3.12...v0.3.13
[0.3.12]: https://github.com/netresearch/nr-landingpage/compare/v0.3.11...v0.3.12
[0.3.11]: https://github.com/netresearch/nr-landingpage/compare/v0.3.10...v0.3.11
[0.3.10]: https://github.com/netresearch/nr-landingpage/compare/v0.3.9...v0.3.10
[0.3.9]: https://github.com/netresearch/nr-landingpage/compare/v0.3.8...v0.3.9
[0.3.8]: https://github.com/netresearch/nr-landingpage/compare/v0.3.7...v0.3.8
[0.3.7]: https://github.com/netresearch/nr-landingpage/compare/v0.3.6...v0.3.7
[0.3.6]: https://github.com/netresearch/nr-landingpage/compare/v0.3.5...v0.3.6
[0.3.5]: https://github.com/netresearch/nr-landingpage/compare/v0.3.4...v0.3.5
[0.3.4]: https://github.com/netresearch/nr-landingpage/compare/v0.3.3...v0.3.4
[0.3.3]: https://github.com/netresearch/nr-landingpage/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/netresearch/nr-landingpage/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/netresearch/nr-landingpage/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/netresearch/nr-landingpage/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/netresearch/nr-landingpage/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/netresearch/nr-landingpage/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/netresearch/nr-landingpage/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/netresearch/nr-landingpage/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/netresearch/nr-landingpage/releases/tag/v0.1.0
