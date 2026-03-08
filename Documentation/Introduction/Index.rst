.. include:: /Includes.rst.txt

============
Introduction
============

What Does It Do?
================

The Landing Page Generator extension provides a backend wizard that
allows TYPO3 editors to create complete landing pages powered by
Large Language Models (LLMs). Instead of manually creating pages and
content elements, editors walk through a guided process:

1.  Select a landing page template
2.  Answer optional briefing questions about the page topic
3.  Review AI-generated page fields (title, SEO metadata, etc.)
4.  Review and edit AI-generated content sections with images
5.  Choose placement in the page tree and save

The extension integrates with ``netresearch/nr-llm`` to communicate
with LLM backends such as OpenAI or Azure OpenAI.

Key Features
============

.. card-grid::

   .. card:: Template-Driven Generation

      Define reusable templates with system prompts, allowed content
      types, backend layouts, and page field configurations. Each
      template controls exactly what the AI produces.

   .. card:: Interactive Wizard

      Step-by-step backend module with live preview. Editors see
      generated content before saving and can regenerate individual
      sections.

   .. card:: Multi-Column Support

      Templates can reference backend layouts with multiple columns.
      The AI automatically distributes content across columns based
      on their purpose (main, sidebar, footer, etc.).

   .. card:: Image Integration

      Automatic image matching from the TYPO3 media library based on
      AI-generated keywords. Optional AI image generation as fallback.

   .. card:: Prompt Optimization

      Auto-optimize button analyzes template settings and generates
      tailored AI instructions. Preview button tests the full output
      without creating pages.

   .. card:: Re-Generation

      Previously generated pages store their template and briefing
      data. Re-generate to create updated variants with improved
      prompts or different answers.

   .. card:: Context Menu Integration

      Right-click any page in the page tree to create a landing page
      underneath it — no need to navigate to the backend module.

   .. card:: Access Control

      Restrict template visibility to specific backend user groups.
      Create department-specific templates for marketing, sales,
      support, etc.

   .. card:: PSR-14 Events

      Hook into page creation and content generation with standard
      TYPO3 PSR-14 events. Add custom fields, filter sections, or
      enforce business rules.

   .. card:: Automatic Language Detection

      Generated content language is determined from the TYPO3 site's
      default language. Write prompts in any language — output always
      matches the site language.

Architecture Overview
=====================

The extension consists of these main components:

**Backend module**
   The :guilabel:`Web > Landing Page` module with template overview
   and wizard launcher.

**Multi-step wizard**
   A TYPO3 MultiStepWizard modal with five steps (template,
   briefing, page fields, content preview, placement).

**Content generator**
   Service layer that communicates with the LLM to generate page
   fields and content sections. Includes HTML sanitization and
   validation.

**Image provider**
   Searches the TYPO3 FAL media library by AI-generated keywords
   and optionally generates images via LLM tasks.

**Page creator**
   Creates the page record and content elements via TYPO3 DataHandler,
   including generation metadata for re-generation support.
