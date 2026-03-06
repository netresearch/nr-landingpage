.. include:: /Includes.rst.txt

============
Installation
============

Requirements
============

- PHP 8.2 or higher
- TYPO3 13.4 LTS or 14.x
- ``netresearch/nr-llm`` ^0.4.0

Composer Installation
=====================

.. code-block:: bash

   composer require netresearch/nr-landingpage

Then activate the extension:

.. code-block:: bash

   vendor/bin/typo3 extension:activate nr_landingpage

Database
========

The extension requires a database table for template records. Run the database
compare tool in the TYPO3 Install Tool or use:

.. code-block:: bash

   vendor/bin/typo3 database:updateschema
