.. include:: /Includes.rst.txt

=====
Usage
=====

.. contents:: On this page
   :local:
   :depth: 2

Creating a Landing Page
=======================

There are three ways to start the landing page wizard:

1.  **Backend module** — Navigate to :guilabel:`Web > Landing Page`
    and click :guilabel:`Create Landing Page`

2.  **Template card** — On the module page, each template card has a
    :guilabel:`Landing Page erstellen` button that starts the wizard
    with that template pre-selected (skipping the template selection
    step)

3.  **Context menu** — Right-click any page in the page tree and
    select :guilabel:`Create Landing Page`. The clicked page becomes
    the parent page automatically.

The Wizard
==========

The wizard guides you through five steps. You can navigate back to
previous steps at any time.

Step 1: Template Selection
--------------------------

Choose a landing page template. Each card shows the template name,
description, and badges indicating:

-  **Briefing mode** (none / optional / required)
-  **Number of allowed content types**
-  **Prompt status** (configured or missing)
-  **Whether reference pages are set**

.. tip::

   If you started from a template card on the module page, this step
   is skipped and the selected template is used directly.

Step 2: Briefing
----------------

If the template has briefing mode ``optional`` or ``required``, the
AI generates contextual questions about your landing page topic.
Answer them to help the AI produce more targeted content.

**Examples of generated questions:**

-  "What is the main product or service?"
-  "Who is the target audience?"
-  "What action should visitors take?"
-  "Are there specific keywords to include?"

When briefing mode is ``none``, this step is skipped entirely.

Step 3: Page Fields
-------------------

The AI generates page-level metadata based on your briefing answers
and template configuration:

-  **Page title** — main title for the page record
-  **URL slug** — auto-generated from the title, editable
-  **SEO title** — optimized for search engines (max 60 characters)
-  **Meta description** — for search result snippets (120–160
   characters)
-  **OG title / description** — for social media sharing

All fields are editable. Character counters help you stay within
recommended limits.

Step 4: Content Preview
-----------------------

The AI generates content sections based on your template settings.
Each section is displayed as a card showing:

-  **Section name** and **content type** (badge)
-  **Header** and **subheader**
-  **Body text** — rendered as HTML preview
-  **Image keywords** — search terms for the media library
-  **Image prompt** — description for AI image generation
-  **Matched images** — from the media library or AI-generated

You can:

-  **Regenerate** individual sections using the refresh button on
   each card
-  **Search for alternative images** using the search field
-  **Generate AI images** (if configured) using the image generation
   button
-  **Edit** section content inline

Step 5: Placement & Save
-------------------------

Configure where and how the page is created:

-  **Parent page** — select or confirm the parent page in the tree
-  **Page title** — review the final page title
-  **URL slug** — review the final URL path

Click :guilabel:`Save` to create the landing page with all content
elements in the TYPO3 page tree.

.. note::

   If the template's publish mode is "Hidden", the page is created
   but not visible in the frontend. Navigate to the page in the
   Page module to review and enable it.

Re-generating a Landing Page
============================

Pages created by the wizard store their generation metadata (template,
briefing answers, configuration hash). To re-generate a page:

1.  Open the **Page** module and select a previously generated landing
    page
2.  Click the :guilabel:`Re-generate` button in the document header
3.  The wizard opens with the original template pre-selected and
    briefing answers pre-filled
4.  Modify answers if needed and complete the wizard
5.  A **new page** is created alongside the original (the original is
    not overwritten)

.. tip::

   Re-generation is useful when you want to create a variant of an
   existing landing page or when the template has been updated with
   improved prompts.

Backend Module
==============

The :guilabel:`Web > Landing Page` module serves as the central hub:

-  **Create Landing Page button** — starts the wizard (top of page)
-  **Template cards** — overview of all available templates with
   quick-create buttons and edit links
-  **Documentation section** — quick reference for configuration and
   usage

Context Menu Integration
========================

Right-click any page in the page tree to find the
:guilabel:`Create Landing Page` option. This opens the wizard with:

-  The clicked page pre-selected as parent page
-  Auto-start enabled (wizard opens immediately)

This is the fastest way to create a landing page under a specific
page without navigating to the backend module first.

PSR-14 Events
=============

The extension dispatches two PSR-14 events that allow you to hook
into the generation process.

BeforePageCreationEvent
-----------------------

Dispatched before the page record is written to the database. Use
this to modify page properties, add custom fields, or enforce
business rules.

.. code-block:: php
   :caption: EXT:my_extension/Classes/EventListener/CustomPageFields.php

   use Netresearch\NrLandingpage\Event\BeforePageCreationEvent;
   use TYPO3\CMS\Core\Attribute\AsEventListener;

   #[AsEventListener]
   final class CustomPageFields
   {
       public function __invoke(BeforePageCreationEvent $event): void
       {
           $pageData = $event->getPageData();
           $pageData['custom_field'] = 'value';
           $event->setPageData($pageData);
       }
   }

AfterContentGenerationEvent
----------------------------

Dispatched after content sections have been generated by the LLM
but before they are saved as content elements. Use this to filter,
transform, or enrich sections.

.. code-block:: php
   :caption: EXT:my_extension/Classes/EventListener/FilterSections.php

   use Netresearch\NrLandingpage\Event\AfterContentGenerationEvent;
   use TYPO3\CMS\Core\Attribute\AsEventListener;

   #[AsEventListener]
   final class FilterSections
   {
       public function __invoke(
           AfterContentGenerationEvent $event,
       ): void {
           $sections = $event->getSections();
           // Remove sections without body text
           $sections = array_filter(
               $sections,
               static fn(array $s): bool
                   => ($s['bodytext'] ?? '') !== '',
           );
           $event->setSections(array_values($sections));
       }
   }

Best Practices
==============

Writing Effective System Prompts
--------------------------------

-  **Be specific** — "Write in formal German, max 150 words per
   section" is better than "Write well"
-  **Define the audience** — "Target: IT managers aged 35–50" helps
   the AI choose appropriate vocabulary
-  **Set constraints** — "No superlatives, no exclamation marks,
   always include data or statistics"
-  **Use the Auto-optimize button** — it analyzes your template
   settings and generates a tailored prompt
-  **Test with Preview** — always verify the output before creating
   real pages

Choosing Reference Pages
-------------------------

-  Select 2–3 pages that represent your desired quality
-  Choose pages with similar structure to what you want generated
-  Avoid selecting pages with very different styles — the AI may
   produce inconsistent results

Template Organization
---------------------

-  Create separate templates for different use cases (product pages,
   event pages, blog posts)
-  Use descriptive names and descriptions so editors know which
   template to choose
-  Use access control to show relevant templates to relevant teams
-  Start with briefing mode "optional" and switch to "required" once
   you know which questions improve the output
