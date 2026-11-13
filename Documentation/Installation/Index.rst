..  SPDX-License-Identifier: GPL-2.0-or-later
..  SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

============
Installation
============

.. contents:: On this page
   :local:
   :depth: 2

Requirements
============

-  PHP 8.2 or higher
-  TYPO3 13.4 LTS or 14.3 LTS
-  ``netresearch/nr-llm`` ^0.43.10 || ^0.44 || ^0.45 || ^0.46 (installed automatically as
   dependency)

Composer Installation
=====================

.. code-block:: bash
   :caption: Install via Composer

   composer require netresearch/nr-landingpage

Activate the extension:

.. code-block:: bash
   :caption: Activate the extension

   vendor/bin/typo3 extension:activate nr_landingpage

Database Setup
==============

The extension adds a database table for template records and
additional columns to the ``pages`` table for generation metadata.
Update the database schema:

.. code-block:: bash
   :caption: Update database schema

   vendor/bin/typo3 database:updateschema

Alternatively, use the TYPO3 Install Tool:

1.  Navigate to :guilabel:`Admin Tools > Maintenance > Analyze
    Database Structure`
2.  Apply all suggested changes for the ``nr_landingpage`` extension

Site Set Activation
===================

The extension provides a Site Set for global color settings. Add it
to your site configuration:

.. code-block:: yaml
   :caption: config/sites/<site>/config.yaml

   dependencies:
     - netresearch/nr-landingpage

Or add it via :guilabel:`Site Management > Sites` in the backend.
See :ref:`Site Set and Color Defaults <color-inheritance>` for
details.

Quick Start
===========

After installation, follow these steps to create your first landing
page:

1.  **Configure LLM backend** — Navigate to
    :guilabel:`System > LLM Configurations` and create a
    configuration with your API credentials (e.g. OpenAI API key)

2.  **Set brand colors** — Navigate to
    :guilabel:`Site Management > Settings` and configure the
    default color scheme under **Landing Page Generator**

3.  **Choose or create a template** — The extension ships four
    templates (see :ref:`installation-shipped-templates`), so the
    wizard offers templates right after setup. To make your own, go to
    :guilabel:`List` module on the root page (PID 0), create a new
    :guilabel:`Landing Page Template` record. Fill in at minimum:

    -  Template name (e.g. "Product Landing Page")
    -  System prompt (use the Auto-optimize button to generate one)

4.  **Generate a landing page** — Navigate to
    :guilabel:`Web > Landing Page`, click
    :guilabel:`Create Landing Page`, and follow the wizard

5.  **Review the result** — The generated page appears in the page
    tree. Open it in the :guilabel:`Page` module to review and
    enable it.

..  _installation-shipped-templates:

Shipped templates
=================

The extension ships four template records on the root level (PID 0):

*   **Product Launch** (structured): announce a product or a release.
*   **Event** (structured, briefing required): promote an event and lead
    to registration.
*   **Creative One-Pager** (creative HTML): a designed single page.
*   **SaaS Feature** (structured): explain one feature of a service.

They are created once, when the extension is set up
(``vendor/bin/typo3 extension:setup``). An installation that had the extension
before gets them with its next ``extension:setup``. To create the ones that
are missing again, run:

.. code-block:: bash

   vendor/bin/typo3 nr-landingpage:import-templates

A template with the same identifier is never changed, also not when it
is hidden or deleted. The three structured templates come with rules per content element
(:confval:`Rules per content element`), for example short headers.
The templates start without colours, so the
colours of the site settings apply; edit them like any other template.

Upgrading
=========

When upgrading to a new version:

.. code-block:: bash

   composer update netresearch/nr-landingpage
   vendor/bin/typo3 database:updateschema

Always run the database schema update after upgrading, as new versions
may add columns or indexes.
