.. include:: /Includes.rst.txt

============
Introduction
============

What does it do?
================

The Landing Page Generator extension provides a backend wizard that allows
TYPO3 editors to create complete landing pages powered by Large Language Models
(LLMs). Instead of manually creating pages and content elements, editors walk
through a guided process:

1. Select a landing page template
2. Answer optional briefing questions
3. Review AI-generated page fields (title, SEO metadata, etc.)
4. Review and edit AI-generated content sections
5. Choose placement in the page tree and save

The extension integrates with ``netresearch/nr-llm`` to communicate with LLM
backends such as OpenAI or Azure OpenAI.

Key Features
============

- **Template-driven generation** -- define reusable templates with system prompts,
  allowed content types, and page field configurations
- **Interactive wizard** -- step-by-step backend module with live preview
- **Content regeneration** -- regenerate individual content sections without
  restarting the wizard
- **Context menu integration** -- right-click any page to create a landing page
  underneath it
- **PSR-14 events** -- hook into page creation and content generation
- **Access control** -- restrict template visibility to specific backend user groups
