.. include:: /Includes.rst.txt

===============
Troubleshooting
===============

.. contents:: On this page
   :local:
   :depth: 2

No Templates Available
======================

**Symptom:** The wizard shows "No templates available" or the module
page is empty.

**Causes and solutions:**

1.  No template records exist — create one via :guilabel:`List` module
    on the root page (PID 0)
2.  Templates are hidden — check the "Hidden" flag in the template
    record
3.  Access restrictions — the template may be restricted to specific
    backend user groups that your user does not belong to

Content Generation Fails
=========================

**Symptom:** The wizard shows an error during content or page field
generation.

**Causes and solutions:**

1.  **No LLM configuration** — ensure at least one LLM configuration
    exists in :guilabel:`System > LLM Configurations` and is not
    hidden or deleted
2.  **Invalid API key** — verify your API credentials in the LLM
    configuration record
3.  **Model not available** — the selected model may not be available
    on your API plan
4.  **Empty system prompt** — while not strictly required, an empty
    system prompt often leads to poor or failed generation. Use the
    Auto-optimize button to generate one.
5.  **Network issues** — check that your TYPO3 server can reach the
    LLM API endpoint (firewall, proxy settings)

Check the TYPO3 log (:file:`var/log/typo3_*.log`) for detailed error
messages.

Images Not Found
================

**Symptom:** The preview shows "No FAL images found for this section"
for all sections.

**Causes and solutions:**

1.  **Empty media library** — the image search queries the TYPO3 FAL
    storage. Ensure you have images with descriptive titles and
    metadata
2.  **Poor keyword matching** — the AI generates English search
    keywords by default. If your media library uses German file names,
    consider adding English alternative text or titles
3.  **AI image generation not configured** — if you want fallback AI
    images, configure an Image Task in the template settings

AI Image Generation Fails
==========================

**Symptom:** Clicking "Generate Image" shows an error.

**Causes and solutions:**

1.  **No image task configured** — select an Image Task in the
    template's AI Configuration tab
2.  **Image generation service unavailable** — verify the LLM task is
    properly configured and the API supports image generation
3.  **Quota exceeded** — image generation APIs often have rate limits

Preview Button Shows Escaped HTML
==================================

**Symptom:** The preview modal shows raw HTML tags instead of
rendered content.

**Solution:** Clear the browser cache and reload the TYPO3 backend.
This issue occurs when outdated JavaScript files are cached.

Wrong Output Language
=====================

**Symptom:** Generated content is in the wrong language (e.g. English
instead of German).

**Cause:** The output language is determined from the TYPO3 site
configuration's default language. If the site's default language is
set to "English", all content will be generated in English.

**Solution:**

1.  Navigate to :guilabel:`Site Management > Sites`
2.  Check the default language configuration of the relevant site
3.  Ensure the default language title matches your desired output
    language (e.g. "Deutsch" for German content)

Performance Considerations
==========================

-  **LLM response times** — content generation depends on your LLM
   provider's response time. GPT-4 is slower but produces better
   results than GPT-3.5.
-  **Image search** — searches the FAL metadata table. Large media
   libraries benefit from indexed ``title`` and ``description``
   fields.
-  **Reference pages** — selecting many reference pages increases the
   prompt size and LLM processing time. 2–3 pages is usually
   sufficient.
