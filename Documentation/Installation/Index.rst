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
-  TYPO3 13.4 LTS or 14.x
-  ``netresearch/nr-llm`` ^0.4.0 (installed automatically as
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

Quick Start
===========

After installation, follow these steps to create your first landing
page:

1.  **Configure LLM backend** — Navigate to
    :guilabel:`System > LLM Configurations` and create a
    configuration with your API credentials (e.g. OpenAI API key)

2.  **Create a template** — Go to :guilabel:`List` module on the root
    page (PID 0), create a new :guilabel:`Landing Page Template`
    record. Fill in at minimum:

    -  Template name (e.g. "Product Landing Page")
    -  System prompt (use the Auto-optimize button to generate one)

3.  **Generate a landing page** — Navigate to
    :guilabel:`Web > Landing Page`, click
    :guilabel:`Create Landing Page`, and follow the wizard

4.  **Review the result** — The generated page appears in the page
    tree. Open it in the :guilabel:`Page` module to review and
    enable it.

Upgrading
=========

When upgrading to a new version:

.. code-block:: bash

   composer update netresearch/nr-landingpage
   vendor/bin/typo3 database:updateschema

Always run the database schema update after upgrading, as new versions
may add columns or indexes.
