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

The review page
===============

A share link opens the review page without a TYPO3 login. The link looks
like ``https://host/nr-landingpage/review#<code>``: the access code sits
behind the ``#``, which a browser never sends to a server, so it appears in
no access log and no referrer. The entry page copies the code into its form;
the visitor enters a name and, if there is one, the passphrase, and presses
:guilabel:`Open review`. Without JavaScript the code is pasted by hand.

What the visitor may do depends on the link:

-  :guilabel:`View` reads the draft and the comments.
-  :guilabel:`Comment` also comments on a section or one of its fields and
   suggests a replacement text for a field.
-  :guilabel:`Edit` also changes the heading, subheading and text of a
   section. The change applies at once and is recorded with the name given.

Text is plain text everywhere: a body text appears without its formatting,
and a text written through a link is stored as paragraphs. A reviewer's name
is whatever the visitor typed; it is shown as untrusted text and never as a
backend user.

A draft's comments are shared by all of its links: every link shows the
comments and names that came in through any other link of the same draft. Use
separate drafts for separate audiences.

Every refusal, whether the code is unknown, wrong, expired, revoked, used up
or the passphrase is wrong, gets the same page with the same status (404), so
the page does not tell whether a link exists. Too many failed attempts from
one address, or too many guesses of one link's passphrase, answer 429. The
page is served with ``Cache-Control: no-store``, ``Referrer-Policy:
no-referrer``, ``X-Robots-Tag: noindex`` and a content security policy that
allows only the extension's own script and style files, and it works only
over HTTPS (see :confval:`workspace.allowInsecureHttp`).

The limits are TYPO3 rate limiters and can be tuned under
``$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['<id>']``, with the keys
``limit`` and ``interval``:

``nrlp-review-enter``
   Failed entries per source address (default 10 per 15 minutes).

``nrlp-review-link``
   Entries to a passphrase-protected link by someone who holds its access
   code, per link (default 5 per 15 minutes). It caps guessing the
   passphrase.

``nrlp-review-write``
   Comments, suggestions and changes per review session (default 30 per 10
   minutes).

``nrlp-review-link-write``
   Comments, suggestions and changes per share link, over all its sessions
   (default 100 per 24 hours). Entering again does not reset it.

``nrlp-review-request``
   Entry attempts without a matching access code per source address, before
   any passphrase hash runs (default 60 per 15 minutes). A request with a
   valid code does not count, so other visitors behind the same address
   cannot lock out its holder.

A draft takes at most 500 comments, suggestions and changes through links.

An approved draft can still be read through a link, but takes no more
comments and no more changes.

Comments in the backend
=======================

The draft's page in the module lists every comment, suggestion and edit
under its section, with the author, the time and the state. A reviewer's
entries are marked as such.

Suggestions, approval and the page
==================================

An open suggestion shows the text it was made against and the proposed text.
:guilabel:`Accept` replaces the field's text, when the field still holds the
text the suggestion was made against; otherwise the suggestion is marked
outdated and nothing is written. :guilabel:`Reject` only closes it. Every
user who sees the draft may do both.

:guilabel:`Approve and create the page` needs the ID of the parent page. The
user must see the draft and may create pages below that page; no share link
can approve. The approval is bound to the version shown: if a reviewer changed
a text, or someone else approved, between opening the page and confirming,
nothing is created and the page says so. The page is created from the stored
draft by the same code as the wizard, below the chosen parent, hidden or
visible as the template says. From then on the draft is closed: links take no
more comments and no more changes, and the draft links to the new page.

When creating the page fails, the approval is taken back, a partial page is
removed and the draft is open again. After a crash the draft can stay approved
without a page; once that has stood for ten minutes, :guilabel:`Reset the
approval` removes any partial page and opens the draft again.

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
