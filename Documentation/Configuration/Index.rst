.. include:: /Includes.rst.txt

=============
Configuration
=============

The extension is configured entirely through **template records** in the
TYPO3 backend. There are no global extension settings. Each template
defines how landing pages are generated — from the AI model and prompt
to allowed content types and page layout.

.. contents:: On this page
   :local:
   :depth: 2

Prerequisites
=============

Before creating templates, ensure that the **nr_llm** extension is
installed and at least one LLM configuration exists:

1.  Navigate to :guilabel:`System > LLM Configurations`
2.  Create a configuration record with your API credentials (e.g.
    OpenAI API key, Azure endpoint)
3.  Verify the connection works by using the test button

.. tip::

   You need at least one working LLM configuration before the Landing
   Page Generator can produce any content.

Creating a Template
===================

Templates are stored in the root page (PID 0) and managed via
:guilabel:`List > Landing Page Templates`.

1.  Switch to the **List** module
2.  Select the root page (ID 0) in the page tree
3.  Click :guilabel:`Create new record` and choose
    :guilabel:`Landing Page Template`

Template Fields Reference
=========================

General Tab
-----------

.. confval:: Template Name

   :type: string
   :required: yes

   Display name shown to editors in the wizard template selector.
   Choose a descriptive name like "Product Landing Page" or
   "Event Promotion".

.. confval:: Identifier

   :type: slug
   :required: auto-generated

   Machine-readable identifier derived from the title. Used
   internally for referencing. Auto-generated — usually no need to
   edit.

.. confval:: Description

   :type: text

   Help text displayed below the template name in the wizard. Use
   this to explain when and why editors should choose this template.
   Example: "For product launches. Generates hero section, features,
   testimonials, and CTA."

AI Configuration Tab
--------------------

.. confval:: AI Model

   :type: select
   :default: Default (system-wide model)

   Which LLM model generates the content. Select "Default" to use
   the globally configured model, or choose a specific configuration
   if this template needs a different model (e.g. GPT-4 for complex
   content, GPT-3.5 for simple pages).

.. confval:: Image Task

   :type: select
   :default: None (media library only)

   Controls AI image generation. When set to "None", images are
   sourced exclusively from the TYPO3 media library (FAL) based on
   keyword matching. Select an LLM task to enable AI-generated
   images as fallback when no matching media library images are
   found.

   .. note::

      AI image generation requires a configured image generation
      task in the nr_llm extension (e.g. DALL-E, Stable Diffusion).

.. confval:: AI Instructions (System Prompt)

   :type: text

   The most important field — controls **how** the AI writes. Define
   tone, style, language, length, and constraints here.

   **Examples:**

   .. code-block:: text

      Write in formal German. Each section should have max 200
      words. Always include a call-to-action at the end of each
      section. Target audience: B2B decision-makers.

   .. code-block:: text

      Casual, friendly tone for a young audience. Use short
      sentences. Include emoji-style descriptions for visual
      elements. Focus on benefits over features.

   Two helper buttons appear next to this field:

   **Auto-optimize** (rocket icon)
      Analyzes your template settings (content types, page fields,
      reference pages, layout, optimization context) and generates
      a tailored system prompt. Replaces the current text.

   **Preview** (play icon)
      Runs a complete test generation with a sample topic. Shows
      resulting content sections and matched images without creating
      any page. Use this to verify your prompt before going live.

.. confval:: Prompt Optimization Context

   :type: text

   Background information used when auto-optimizing: brand
   guidelines, target audience profile, tone of voice rules, SEO
   requirements. The optimizer combines this with your content types,
   page fields, and reference pages to generate a tailored system
   prompt.

   **Example:**

   .. code-block:: text

      Brand: TechCorp. Audience: CTOs and IT managers aged 35-55.
      Tone: professional but approachable. Always mention our
      24/7 support guarantee. Avoid superlatives.

.. confval:: Prompt Optimization Meta-Prompt

   :type: text
   :default: (built-in strategy)

   Advanced: Override the strategy the AI uses to optimize your
   prompt. Leave empty to use the built-in optimization approach.
   Only modify this if the default optimization consistently
   produces unsuitable prompts.

Content & Layout Tab
--------------------

.. confval:: Generation Mode

   :type: select
   :default: Structured

   Controls how the AI creates page content:

   ``Structured``
      Uses standard TYPO3 content elements (text, textmedia, header,
      etc.). Each section becomes a separate ``tt_content`` record.
      This is the default mode and works with all TYPO3 themes.

   ``Creative HTML``
      Gives the AI full design freedom. Each layout column receives
      a self-contained HTML fragment with embedded ``<style>`` blocks
      and optional inline SVG graphics. Content is stored as ``html``
      CType elements.

      **Design rules in creative mode:**

      -  CSS-only animations and transitions (no JavaScript)
      -  Inline SVG for graphics (no external image URLs)
      -  Scoped CSS classes per section to avoid conflicts
      -  Responsive design with relative units and media queries
      -  Semantic HTML for accessibility

      **Security:** All generated HTML is sanitized. ``<script>`` tags,
      event handlers, ``javascript:`` protocols, ``data:`` URIs, and
      CSS ``url()`` are removed automatically.

   When creative mode is selected, the :confval:`Allowed Content Types`
   and :confval:`Image Task` fields are hidden since they only apply to
   structured mode.

.. confval:: Allowed Content Types

   :type: select (checkboxes)
   :default: text, textmedia, textpic

   Which TYPO3 content element types the AI may generate. The wizard
   only produces elements of the selected types. If none are
   selected, the defaults ``text``, ``textmedia``, and ``textpic``
   are used.

   Common choices:

   -  ``text`` — plain text with header
   -  ``textmedia`` — text with media (images, video)
   -  ``textpic`` — text with image
   -  ``header`` — standalone header element
   -  ``bullets`` — bullet point lists
   -  ``table`` — tabular content

.. confval:: Page Fields

   :type: select (checkboxes)
   :default: seo_title, description, og_title, og_description

   Which page-level fields the AI generates. Selected fields are
   presented for review in the wizard's "Page Fields" step.

   Available fields include:

   -  ``seo_title`` — SEO title tag (max 60 characters)
   -  ``description`` — Meta description (120–160 characters)
   -  ``og_title`` — Open Graph title for social media
   -  ``og_description`` — Open Graph description
   -  ``abstract`` — Page abstract
   -  ``subtitle`` — Page subtitle

.. confval:: Reference Pages

   :type: page selector
   :default: (none)

   Existing TYPO3 pages whose structure and content serve as
   examples for the AI. The generator reads these pages and
   instructs the LLM to follow a similar style and structure.

   .. tip::

      Select 2–3 well-crafted pages that represent your desired
      output quality. The AI uses them as writing samples.

.. confval:: Backend Layout

   :type: select
   :default: (none — single column)

   Page layout for generated pages. Determines how many content
   columns are available and how the AI distributes content.

   When a multi-column layout is selected (e.g. Main + Sidebar),
   the AI generates content for **each column** with appropriate
   content:

   -  **Main/Content** columns receive the primary content
      (hero, features, body text)
   -  **Sidebar** columns receive compact supplementary content
      (CTAs, contact info, links)
   -  **Footer** columns receive closing elements (CTA, contact)
   -  **Header/Banner** columns receive hero elements

Wizard Tab
----------

.. confval:: Briefing

   :type: select
   :default: optional

   Controls the briefing step in the wizard:

   ``None``
      Skip the briefing entirely. The AI generates content based
      solely on the system prompt and reference pages.

   ``Optional``
      Show briefing questions but allow editors to skip them.
      Recommended for most use cases.

   ``Required``
      Editors must answer all briefing questions before proceeding.
      Use this when the AI needs specific input (e.g. product name,
      target audience, campaign dates).

.. confval:: Page Visibility

   :type: select
   :default: Hidden

   Whether newly generated pages are created as hidden or
   immediately visible.

   ``Hidden``
      Pages are created with the TYPO3 "hidden" flag. Editors must
      manually enable them after review. **Recommended for
      production use.**

   ``Visible``
      Pages are immediately visible in the frontend. Use with
      caution — only suitable for draft/staging environments.

Access Tab
----------

.. confval:: Backend User Groups

   :type: select (side-by-side)
   :default: (none — visible to all)

   Restrict which backend user groups can see and use this template.
   When no groups are selected, the template is available to all
   backend users.

   .. tip::

      Use this to create department-specific templates: a
      "Marketing" template visible only to the marketing group,
      a "Support" template for the support team, etc.

Output Language
===============

The generated content language is determined automatically from the
**default language** of the TYPO3 site where the parent page belongs.
For example, if the site's default language is "Deutsch", all generated
headers, body text, and SEO fields will be in German — regardless of
the language the system prompt is written in.

The system prompt language does not matter. You can write prompts in
any language; the output always follows the site's default language.
