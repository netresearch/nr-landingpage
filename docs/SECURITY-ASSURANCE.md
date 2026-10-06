<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Architecture and security assurance

This document describes how `nr_landingpage` is built, who and what it talks to, what a site operator can
expect from it in terms of security, and where its limits are. Every statement refers to the code in this
repository; file paths are given so that each claim can be checked. Vulnerabilities are reported as described
in the [organisation security policy](https://github.com/netresearch/.github/blob/main/SECURITY.md), not in
public issues.

## Actors

| Actor | How it interacts with the extension |
|-------|-------------------------------------|
| Wizard user | A TYPO3 backend user who opens the wizard in the "Landing Pages" backend module (`Configuration/Backend/Modules.php`), directly or through the page tree context menu (`Classes/ContextMenu/LandingPageItemProvider.php`). The wizard talks to the AJAX routes in `Configuration/Backend/AjaxRoutes.php`. Runs the wizard, answers briefing questions, edits the generated content and saves the page. |
| Template editor | A backend user allowed to edit records of `tx_nrlandingpage_domain_model_template` (`Configuration/TCA/tx_nrlandingpage_domain_model_template.php`). Sets the system prompt, the allowed content types, page fields, generation mode, animation and the backend user groups of a template. |
| Integrator / administrator | Configures the LLM and image-generation providers in `netresearch/nr-llm`, the site settings for colours (`Configuration/Sets/NrLandingpage/`) and the TYPO3 permissions of editors. |
| LLM provider | External service reached through `netresearch/nr-llm`. Receives prompts and returns text. |
| Image-generation provider | External service reached through `netresearch/nr-llm` (`Classes/Service/ImageGeneratorRegistrar.php` registers the DALL-E and FAL.ai generators when nr-llm provides them). |
| Frontend visitor | Views the pages the wizard created. Does not interact with the extension's code; TYPO3 renders the stored records. |

## Components

| Component | Files | Responsibility |
|-----------|-------|----------------|
| Backend module and wizard UI | `Configuration/Backend/Modules.php`, `Resources/Private/Templates/Backend/LandingPageWizard/Index.html`, `Resources/Public/JavaScript/wizard.js`, `wizard-state.js` | Five-step wizard (template, briefing, page fields, content, placement) running in the TYPO3 backend. |
| AJAX endpoints | `Configuration/Backend/AjaxRoutes.php`, `Classes/Controller/Backend/LandingPageWizardController.php` | Eleven backend AJAX routes; the controller reads typed values from the request body and delegates to the services. |
| Template handling | `Classes/Service/TemplateService.php`, `Classes/Domain/Model/Template.php` | Loads templates, applies the backend user group restriction, provides TCA item lists. |
| Generation | `Classes/Service/BriefingService.php`, `ContentGeneratorService.php`, `PromptOptimizerService.php`, `LlmCompletionTrait.php` | Builds prompts, calls nr-llm, decodes and validates the JSON the LLM returns. |
| Output filtering | `Classes/Service/ContentGeneratorService.php` (structured mode), `Classes/Service/CreativeHtmlSanitizer.php` (creative mode) | Filters HTML produced by the LLM. |
| Images | `Classes/Service/ImageSearchService.php`, `ImageProviderService.php` | Searches FAL file metadata by keyword; stores AI-generated images in FAL. |
| Page creation | `Classes/Service/PageCreatorService.php`, `AnimationScriptBuilder.php`, `GsapService.php` | Writes the page, content elements, file references and animation elements through TYPO3's DataHandler. |
| Page module integration | `Classes/ContextMenu/LandingPageItemProvider.php`, `Classes/EventListener/*.php` | Context-menu entry, "Re-Generate" button and generation info in the Page module. |
| Template form helpers | `Classes/Form/FieldControl/*.php`, `Classes/Form/FieldInformation/*.php`, `Resources/Public/JavaScript/form-engine/field-control/*.js` | "Optimize prompt" and "Test generate" buttons and information blocks on the template record form. |
| Extension points | `Classes/Event/BeforePageCreationEvent.php`, `AfterContentGenerationEvent.php` | PSR-14 events before the page is written and after it was created. |

## Data flows

1. The wizard calls the AJAX routes. TYPO3's backend routing authenticates the request as the logged-in
   backend user; the extension adds no authentication of its own.
2. For briefing questions, page fields and content, the services build a prompt from the template's system
   prompt, the briefing answers, the allowed content types, the layout column names, the colours and the
   output language, and send it through nr-llm (`LlmCompletionTrait::completeJsonWithTemplate()`) to the LLM
   provider configured for the template or to nr-llm's default. The prompt optimizer additionally sends the
   template's structural settings, the reference page UIDs and the system prompt of the linked nr-llm
   configuration (`PromptOptimizerService`).
3. The LLM response is decoded as JSON and validated (`ContentGeneratorService::validateSections()`,
   `validateCreativeSections()`, `validatePageFields()`) before it goes back to the browser.
4. Images come either from a keyword search over `sys_file_metadata` and `sys_file`
   (`ImageSearchService`) or from an image-generation provider; generated images are stored in the folder
   `generated-landingpage/` of the default FAL storage (`ImageProviderService::storeGeneratedImage()`).
5. On save, `PageCreatorService::createLandingPage()` writes `pages`, `tt_content` and `sys_file_reference`
   records through DataHandler, then, when animation is enabled, an `html` content element that loads GSAP
   and, when at least one section carries a valid animation, a second one that runs the animation script
   (`PageCreatorService::createGsapElements()`). It stores generation metadata in the `pages` columns defined in
   `ext_tables.sql` (template UID, briefing answers as JSON, config hash, timestamp, source page).
6. TYPO3 renders the created page to frontend visitors. With animation enabled, the page loads
   `Resources/Public/JavaScript/vendor/gsap/3/*.min.js` from the extension.

## Trust boundaries

- **Browser to TYPO3 backend.** Everything in an AJAX request body is input from the wizard user. The
  controller accepts only scalar or array values of the expected type (`extractIntFromBody()`,
  `extractStringFromBody()`, `extractArrayFromBody()`).
- **TYPO3 to the LLM and image providers.** Responses are treated as untrusted input: they are decoded,
  type-checked and filtered before use.
- **Backend to frontend.** Records the wizard creates are rendered to anonymous visitors.

## What you can expect

- **Endpoint access.** Every wizard AJAX action answers 403 unless the backend user has access to the
  landing page module; "Optimize prompt" and "Test generate" answer 403 unless the user may modify template
  records (`BackendAccessGuard`, checked at the start of each action in `LandingPageWizardController`).
  Actions that work below a page require read access to that page, and saving requires the right to create
  pages below the parent page. Generation info is returned only for pages the user may read, and the
  source-page chain is not followed into pages the user may not read. Tests:
  `Tests/Unit/Controller/Backend/LandingPageWizardControllerTest.php`.
- **Media library access.** Image search and the recent-images fallback return only files the backend user
  may read under the storage's file mounts and file permissions, and saving rejects an image the user may
  not read (`ImageSearchService`). Tests: `Tests/Unit/Service/ImageSearchServiceTest.php`.
- **Template restriction.** A template with backend user groups is loaded only for admins and members of
  one of those groups (`TemplateService::isTemplateAccessible()`); every AJAX action that works on a
  template loads it through `TemplateService::loadByUid()` and answers 400 when it is not accessible. Tests:
  `Tests/Unit/Service/TemplateServiceTest.php`.
- **Record permissions.** Pages, content elements and file references are written through DataHandler as
  the current backend user (`PageCreatorService::createDataHandler()`), so TYPO3's page, table, field and
  workspace permissions apply to these records.
- **Page fields.** The page-field values of the request never set a field listed in
  `PageCreatorService::RESERVED_PAGE_FIELDS`; `title` and `slug` come only from the request's dedicated
  `title` and `slug` values. When a template lists page fields, only those fields are written
  (`PageCreatorService::buildPageData()`).
- **Content types and positions.** In structured mode a content type outside the template's allowed list
  is replaced by `text`, both when the LLM answers (`ContentGeneratorService::validateSections()`) and when
  the page is saved (`LandingPageWizardController::saveAction()`). When the LLM answers, column positions
  are limited to the columns of the backend layout and image orientation to a fixed list.
- **Structured-mode HTML from the LLM.** Body text is filtered by the TYPO3 HTML sanitizer with an
  allowlist of `p`, `br`, `ul`, `ol`, `li`, `strong`, `em`, `h2` to `h4` and `a` with `href` values starting
  with `http://`, `https://`, `/`, `mailto:` or `tel:` (`ContentGeneratorService::getSanitizer()`). Tests:
  `Tests/Unit/Service/ContentGeneratorServiceValidationTest.php`.
- **Creative-mode HTML.** `CreativeHtmlSanitizer::sanitize()` runs on the LLM response and again on save.
  It is an allowlist built on `typo3/html-sanitizer`: elements and attributes that are not listed are
  removed, so the output contains no `script` element and no event handler attribute. Links accept
  `http`, `https`, `mailto`, `tel` and local targets; inline SVG keeps only SVG elements, and its references may only point into the same
  document; `img` is kept only as a placeholder with `data-image-slot` and without `src`. CSS in `style`
  elements and attributes passes `CreativeCssFilter`, which decodes CSS escapes and removes `url()`, the
  other resource functions and `@import`. Tests: `Tests/Unit/Service/CreativeHtmlSanitizerTest.php`.
- **Animation script.** The animation script is generated by the extension, not by the LLM: animation
  types come from a fixed map, durations, delays and staggers are clamped, and the selectors use integer
  content element UIDs (`AnimationScriptBuilder`, `ContentGeneratorService::validateAnimation()`).
- **Generated images.** The downloaded file must have an `image/*` MIME type detected from its content and
  an extension listed in `$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']`
  (`ImageProviderService::storeGeneratedImage()`). A failed generation returns a generic message to the
  browser and logs the details.
- **Previews.** Generated body text is previewed in an `iframe` with `sandbox="allow-same-origin"` and
  without `allow-scripts`, both in the wizard (`Resources/Public/JavaScript/wizard.js`) and in the
  "Test generate" preview, which builds the rest of its markup with DOM methods
  (`Resources/Public/JavaScript/form-engine/field-control/test-generate.js`,
  `Tests/Unit/Security/GeneratedContentRenderingTest.php`).
- **Backend JavaScript.** Modal content is passed as DOM elements, not HTML strings
  (`Tests/Unit/Security/ModalContentComplianceTest.php`); JavaScript and Fluid templates are checked for
  patterns that a Content Security Policy would block (`Tests/Unit/Security/CspComplianceTest.php`); the
  HTML of TCA field information uses only the tags TYPO3 allows there
  (`Tests/Unit/Form/FieldInformation/FieldInformationHtmlComplianceTest.php`).
- **Database access.** Queries use the TYPO3 QueryBuilder with named parameters.

## What you cannot expect

- **Authentication, sessions and CSRF protection** come from TYPO3's backend, not from this extension.
- **Creative-mode pages contain HTML that TYPO3 outputs as is.** Content is stored as `html` content
  elements. Grant creative-mode templates only to editors who may also create `html` content elements, and
  review the generated HTML before the page is made visible.
- **Structured-mode body text is not filtered again on save.** The wizard lets the editor change the text,
  and it is stored like text entered in TYPO3's record editor, within the editor's permissions.
- **Animation needs scripts on the frontend.** Pages with animation load GSAP with `<script src>` and run
  an inline animation script generated by the extension. A frontend Content Security Policy must allow them;
  `Configuration/ContentSecurityPolicies.php` only extends the backend policy (`worker-src blob:` for the
  colour picker).
- **Prompts reach an external provider.** Briefing answers, template prompts, colours and layout names are
  sent to the LLM provider configured in nr-llm. Do not enter data in a briefing that this provider may not
  receive.
- **The LLM can be steered by its input.** Briefing answers and system prompts are inserted into the prompt
  as written. The controls above apply to what comes back, not to what the model decides to write.
- **Raw LLM responses may be written to disk.** When a response is not valid JSON, it is stored in
  `var/log/llm_response_<template>_<timestamp>.txt` (`LlmCompletionTrait::dumpLlmResponse()`).
- **Error details.** Apart from image generation, a failed AJAX action returns the exception message to the
  backend user and logs the trace (`LandingPageWizardController::errorResponse()`).
- **Templates without backend user groups carry no group restriction** (`TemplateService::isTemplateAccessible()`).
- **Visibility of new pages** depends on the template's publish mode; re-generated pages are always created
  hidden (`PageCreatorService::addGenerationMetadata()`).

## Common weaknesses and how they are countered

| Weakness | Counter-measure in this repository |
|----------|------------------------------------|
| CWE-79 Cross-site scripting | HTML allowlist for structured mode; `CreativeHtmlSanitizer` for creative mode; sandboxed preview iframe; DOM-element modal content; tests listed above. |
| CWE-89 SQL injection | QueryBuilder with named parameters in the controller and services. |
| CWE-862 / CWE-863 Missing or incorrect authorisation | Group check for templates in `TemplateService`; DataHandler permission checks for the page, content element and file reference records the wizard creates. |
| CWE-434 Unrestricted upload of dangerous file type | Content-based MIME check and extension allowlist for generated images. |
| CWE-20 Improper input validation | Typed extraction of request values; CType, column, orientation and animation values restricted to known sets or ranges. |

## Secure design principles applied

- **Least privilege:** pages, content elements and file references are written as the current backend
  user through DataHandler; generated images go to FAL and unparseable LLM responses to `var/log`, as
  described above.
- **Fail closed:** an inaccessible or missing template leads to an error response, not to a fallback
  template.
- **Defence in depth:** creative HTML is filtered when the LLM answers and again when the page is saved.
- **Separation of layers:** the phpat rules in `Tests/Architecture/LayerDependencyTest.php` (run by PHPStan)
  keep the domain, service, event and controller layers apart.

## Third-party code in the repository

| Path | Origin | Licence |
|------|--------|---------|
| `Resources/Public/JavaScript/vendor/gsap/3/gsap.min.js`, `ScrollTrigger.min.js`, `TextPlugin.min.js` | GSAP 3.14.2 by GreenSock (<https://gsap.com>), version recorded in `GsapService::VERSION` | Terms at <https://gsap.com/standard-license>, as stated in each file's header |
| `Build/phpunit/FunctionalTestsBootstrap.php` | Same code as `Resources/Core/Build/FunctionalTestsBootstrap.php` of `typo3/testing-framework` | GPL-2.0-or-later (typo3/testing-framework) |
| `Build/Scripts/runTests.sh` | Based on the test runner of TYPO3BestPractices/tea and the netresearch typo3-testing-skill template, as its header states | See the header of the file |
