..  SPDX-License-Identifier: GPL-2.0-or-later
..  SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _workspace:

=================
Content Workspace
=================

.. contents:: On this page
   :local:
   :depth: 2

The Content Workspace keeps the content of a landing page as a **draft**
before the page exists. The decisions behind it, above all how share links
are protected, are in
`ADR-0001 <https://github.com/netresearch/nr-landingpage/blob/main/docs/adr/0001-content-workspace-share-links.md>`__.

Saving a draft
==============

In the last step of the wizard, :guilabel:`Save as workspace draft` keeps
the generated page fields and sections as a draft and opens it in the
module :guilabel:`Web > Content Workspace`. No page is created at that
point. Drafts of a template in creative mode cannot be saved: their content
is HTML and CSS, which cannot be reviewed as text.

A draft is visible to its owner, to administrators, and to backend users in
a group the draft is shared with. Everybody needs access to the module
:guilabel:`Content Workspace`. A draft someone may not see is answered like
one that does not exist.

Share links
===========

The owner of a draft and administrators create share links on the draft's
page. A link gives people without a TYPO3 login access to exactly this
draft. Each link has:

Permission
   :guilabel:`View` reads the draft, :guilabel:`Comment` also comments and
   suggests changes, :guilabel:`Edit` also changes the texts directly. No
   link creates a page, approves or changes anything else.

Expiry
   Required. The form offers a default and refuses more than the configured
   maximum (see below). A hard ceiling of 90 days applies in any case.

Passphrase
   Optional, at least 8 characters, stored as a salted hash. Pass it on
   separately from the link.

Usage limit
   Optional: how often the link may be opened.

The link is shown **once**, right after it was created, in a read-only field
with a copy button. Only a hash of it is stored, so a lost link cannot be
shown again: revoke it and create a new one. A revoked link stops working on
the next request, also for people who have it open.

The list of links shows label, permission, expiry, use count and state, and
the first eight characters of the link's identifier to tell links apart.
The history below it records who created and who revoked a link.

Configuration
=============

Extension configuration, section :guilabel:`workspace`:

.. confval:: workspace.enabled
   :name: workspace-enabled
   :type: boolean
   :default: 1

   Switches the review page for share links off. Off, the site answers its
   standard 404 below ``/nr-landingpage/review``.

.. confval:: workspace.defaultLinkLifetimeDays
   :name: workspace-defaultlinklifetimedays
   :type: integer
   :default: 7

   The lifetime the share link form offers, never above the maximum.

.. confval:: workspace.maxLinkLifetimeDays
   :name: workspace-maxlinklifetimedays
   :type: integer
   :default: 30

   The longest lifetime of a share link. Values above 90 count as 90.

.. confval:: workspace.publicBaseUrl
   :name: workspace-publicbaseurl
   :type: string
   :default: (empty)

   The origin the share links are built from, ``https://host`` without a
   path. Empty uses the origin of the backend request.

.. confval:: workspace.allowInsecureHttp
   :name: workspace-allowinsecurehttp
   :type: boolean
   :default: 0

   For local development and tests only. Off, the review page answers a
   request that TYPO3 does not see as HTTPS with a 404.
