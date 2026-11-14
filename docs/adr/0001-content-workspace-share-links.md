---
type: adr
title: "ADR-0001: AI Content Workspace, data model and share-link protection"
description: How drafts, review links, comments, suggestions and approval are stored and protected, so that people without a TYPO3 login can review a landing page draft before the page exists.
tags: [security, workspace, share-links, data-model]
timestamp: 2026-11-14
---

<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# ADR-0001: AI Content Workspace, data model and share-link protection

Status: proposed (2026-11-14), accepted when the first implementation PR merges

## Context

The wizard generates a landing page and creates it in one session. Teams want
to draft the content first, let people without a TYPO3 login read and comment
on it, take their change suggestions, and create the page only after an editor
approved the draft.

Three things are new and carry risk: a frontend endpoint that works without
any login, a secret in a URL that grants access, and an approval step that must
not approve content nobody looked at. The decisions below are written so that
each of them has a test.

## Decision

### 1. Scope

- A **draft** holds the generated page fields and the sections (header,
  subheader, body text, image choice, column, animation) of one landing page,
  plus the briefing answers and the template it came from. `header` and
  `subheader` are stored as raw plain text (the page output escapes them);
  `bodytext` is stored as the HTML the structured generator produced.
- **Structured templates only.** A creative-mode template produces sanitised
  HTML+CSS per column, which nobody can review as text. Saving a draft from a
  creative template is refused.
- A draft is created from the wizard (button "Save as workspace draft" in the
  content step) and managed in a new backend module "Content Workspace".
- Page creation reuses `PageCreatorService::createLandingPage()` and the same
  section normalisation as the wizard's save action. That normalisation moves
  out of the controller into one service, so both paths share it.

### 2. Data model

All tables live on `pid = 0`, are managed by repositories (QueryBuilder), and
have no TCA: nothing edits them through FormEngine, so no record can be
changed around the checks in this ADR.

`tx_nrlandingpage_workspace_draft`

| Column | Meaning |
|--------|---------|
| `uid`, `crdate`, `tstamp`, `deleted` | standard |
| `owner` | `be_users.uid` of the creator |
| `be_groups` | comma list of `be_groups.uid` the owner shared the draft with |
| `title`, `template_uid`, `parent_page_uid` | name, source template, target parent page (0 until chosen) |
| `briefing_data`, `page_fields`, `sections` | JSON; every section carries a stable random `id` |
| `version` | integer, +1 on every change of any column that page creation reads: `title`, `template_uid`, `parent_page_uid`, `page_fields`, `sections` |
| `status` | `draft`, `approved`, `created` |
| `approved_by`, `approved_at`, `approved_version`, `created_page_uid` | set at approval and creation |
| `approval_attempt` | random 32-hex value written by the approval update, cleared by a revert or reset; fences page creation (section 9) |

`tx_nrlandingpage_workspace_link`

| Column | Meaning |
|--------|---------|
| `uid`, `draft_uid`, `label` | a link grants access to exactly this draft |
| `token_prefix` | 16 hex characters, unique, non-secret, lookup key |
| `token_hash` | SHA-256 of the whole token, 64 hex characters |
| `permission` | `view`, `comment` or `edit`, ordered, each includes the one before |
| `expires` | Unix time, **required**, never 0 |
| `passphrase_hash` | optional, TYPO3 `PasswordHashFactory` hash, never reversible |
| `max_uses`, `use_count` | optional usage limit; counts opened review sessions |
| `created_by`, `crdate`, `revoked_at`, `revoked_by` | audit of who created and who revoked |

The `pages` table gets one column, `tx_nrlandingpage_workspace_ref`
(`<draft uid>:<approval_attempt>`, empty for every other page). It is part of
the page's initial insert, so a page of a draft is identifiable from the moment
it exists (section 9).

`tx_nrlandingpage_workspace_session` (server-side review session)

`uid`, `link_uid`, `session_hash` (SHA-256 of the session id), `reviewer_name`,
`expires`, `crdate`. No IP address and no user agent are stored.

`tx_nrlandingpage_workspace_comment`

`uid`, `draft_uid`, `link_uid` (0 for an editor), `be_user` (0 for a reviewer),
`section_id`, `field` (`header`, `subheader`, `bodytext`, or empty for a
comment on the whole section), `kind` (`comment`, `suggestion`, `edit`),
`author_name`, `body` (plain text), `base_text` (the field's text when the
suggestion was made), `suggested_text`, `status` (`open`, `accepted`,
`rejected`, `stale`, `applied`), `resolved_by`, `resolved_at`, `crdate`.

`tx_nrlandingpage_workspace_audit` (append-only)

`uid`, `draft_uid`, `link_uid`, `be_user`, `action` (`link_created`,
`link_opened`, `link_revoked`, `suggestion_accepted`, `suggestion_rejected`,
`approved`, `page_created`, `approval_reverted`, `approval_reset`), `crdate`.
No token, no passphrase, no reviewer text. Append-only by construction: the
repository has an insert method and no update or delete method.

### 3. The token

- 256 bits from `random_bytes(32)`, encoded base64url without padding (43
  characters). Format `nrws_<prefix>_<secret>` where `<prefix>` is
  `bin2hex(random_bytes(8))` and `<secret>` is the 43 characters. The prefix is
  not secret and is not derived from the secret. The same shape and the same
  lookup order as the A2A token of nr-llm
  (`A2aInboundClientRepository::TOKEN_PREFIX`, `prefixOf()`, `hash()`).
- Only `token_prefix` and `token_hash = hash('sha256', $token)` are stored. A
  256-bit random secret needs no slow hash and no salt; an HMAC with the TYPO3
  encryption key would make every link unusable after a key rotation, which
  SHA-256 does not.
- A token is parsed with `^nrws_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$` (the secret may
  contain `_`, the prefix cannot). Lookup is by prefix, then
  `hash_equals($row['token_hash'], hash('sha256', $given))`. When no row matches, the comparison still runs against a constant
  zero hash, as nr-llm's `A2aAuthenticator` does, so the response time does not
  tell a known prefix from an unknown one.
- The token is returned once, in the response of the action that created it,
  with `Cache-Control: no-store, private`. The backend shows it in a read-only
  field with a copy button and a note that it cannot be shown again (nr-llm's
  A2A token module, `A2aTokens.js`). The list afterwards shows label,
  permission, expiry, use count and a prefix of 8 hex characters, nothing that
  restores the link. A lost link is revoked and a new one issued.
- The token is never written to a log, an exception message, the audit table or
  a rendered page. It is a `#[SensitiveParameter]` argument wherever it is
  passed, and the creation response is the only place it is rendered.

### 4. Link properties

- **Permission levels.** `view` reads sections and comments. `comment` adds
  comments and suggestions. `edit` also changes header, subheader and body text
  of existing sections. No level creates a page, approves, deletes a draft,
  changes sections' order or number, creates links or changes `be_groups`.
- **Expiry is required.** The form offers a default (7 days) and refuses a value
  above the maximum. Both are extension configuration (`ext_conf_template.txt`:
  `workspace.defaultLinkLifetimeDays`, `workspace.maxLinkLifetimeDays`, default
  maximum 30 days). A hard ceiling of 90 days applies whatever the
  configuration says. A review session never outlives its link and never lasts
  longer than 8 hours.
- **Revocation** sets `revoked_at` and `revoked_by`. Every review request loads
  the link again, so revocation applies to open sessions on the next request.
- **Passphrase** (optional). Stored with `PasswordHashFactory`. The entry form
  always shows the passphrase field, so a form that asks for one does not
  reveal that a token exists. Exactly one password-hash operation runs per
  exchange: `checkPassword()` against the link's hash when the link has a
  passphrase, otherwise `getHashedPassword()` on the given text (unknown link,
  or a link without passphrase), so the work is the same on every path. (Tests
  count the calls; wall time proves nothing.) Guessing the passphrase needs the
  token first, and is limited per link (section 5, `nrlp-review-link`). The
  trade-off: whoever holds the full link can use up that limit and so keep the
  recipient out for the window (15 minutes); a link holder is already past the
  secret, and revocation ends it.
- **Usage limit** (optional). `use_count` is raised by `UPDATE ... SET
  use_count = use_count + 1 WHERE uid = ? AND (max_uses = 0 OR use_count <
  max_uses)`; an affected-rows count of 0 refuses the entry. The check is atomic,
  two simultaneous entries cannot exceed the limit.
- **Who creates and revokes.** The draft's owner and administrators (section
  8); a user who only sees the draft through a group cannot hand it to the
  outside. Both are recorded in the link row and the audit table.

### 5. The frontend endpoint

A PSR-15 frontend middleware answers everything below `/nr-landingpage/review`,
before the site is resolved, so a request never reaches page resolution, page
cache or the TypoScript renderer. It derives nothing from a site or a page and
its responses are never written to a TYPO3 cache. The web server must route the
host to TYPO3's frontend `index.php`, as for any frontend request; the public
base URL for the links defaults to the origin of the backend request and can be
overridden with `workspace.publicBaseUrl`.

**HTTPS only.** A request that TYPO3 does not see as HTTPS (`NormalizedParams`,
which honours the installation's reverse-proxy settings) gets the standard 404.
Tests and local setups switch this off with `workspace.allowInsecureHttp = 1`
(default 0). Over HTTPS the cookies are `Secure` and carry the `__Host-` name
prefix (below); over plain HTTP, only possible with that switch, they have
neither.

**The token travels in the URL fragment.** A share link is
`https://host/nr-landingpage/review#<token>`. A fragment is never sent to the
server and never put into a `Referer`, so the token cannot reach an access
log, a proxy log, a CDN cache key or a referrer header, and a link preview bot
that fetches the URL only receives the static entry page. A small script
(`Resources/Public/JavaScript/review-entry.js`, served from the extension, no
inline script) copies the fragment into the form and removes it with
`history.replaceState`, on load and again on every `hashchange`, so a second
link opened in the same tab works too. **It only prefills.** Entry needs a click on "Open
review", so a mail scanner that runs a headless browser cannot use up a usage
limit by loading the page. The click **POSTs** the token to
`/nr-landingpage/review/enter`. Without JavaScript the same form takes the code
by paste.

Accepted limits of the fragment: the full link sits in the recipient's browser
history, bookmarks and sync, in their clipboard and in whatever mail or chat
holds it, and a browser extension can read it. Expiry, passphrase, usage limit
and revocation are the answer to those; nothing in the design removes them.

The alternative of a token in the path (`/review/<token>`) with a redirect to a
session-bound view was rejected: the first request would still reach every log
in front of the application, and an unfurling bot would use up the usage limit
with a GET that changes state.

Exchange (`POST /nr-landingpage/review/enter`, body: `token`, `passphrase`,
`name`, `form_token`):

1. Checks the `form_token` against the entry cookie (below; a stale, missing
   or foreign one gets a `400` that asks to reload the page and says nothing
   about the token), refuses `Sec-Fetch-Site: cross-site` and `same-site`, and a
   present `Origin` that differs from the request's own origin.
2. **Link limit before the passphrase, verify before the address limit.** It
   finds the link as in section 3 and matches the token (a SHA-256 lookup, no
   password hash); only a request whose token did **not** match consumes
   `nrlp-review-request` and gets `429` once that is exhausted. It then checks
   revocation and expiry. For a link
   with a passphrase whose token hash matched, `nrlp-review-link` is consumed
   first: while it is exhausted the answer is `429` and **no passphrase hash
   runs**, so a correct guess is refused too (guessing is capped; section 4
   states the trade-off). Then the passphrase and the usage limit are checked.
   A request that passes everything succeeds whatever `nrlp-review-enter`
   holds, as in nr-llm's `A2aAuthenticator`: one address cannot lock out a
   recipient. Only a failure consumes `nrlp-review-enter`.
3. On success: inserts a session row, writes the `link_opened` audit row, sets
   the session cookie `__Host-nrws_session` (256-bit random value, only its
   SHA-256 stored; `HttpOnly`, `Secure`, `SameSite=Strict`, **`Path=/`**,
   `Max-Age` equal to the session's lifetime, no `Domain`), and answers `303` to
   `/nr-landingpage/review/draft`. The `__Host-` prefix makes browsers refuse a
   cookie of that name that a sibling subdomain tries to set, so an attacker
   cannot plant a session id of their own review session. The price is
   `Path=/`: the cookie is sent with every request to the host, including the
   site's own pages and the backend. It is `HttpOnly`, `Secure`, `Strict` and
   random, and only its hash is stored, so that is the smaller risk.
4. On every failure, whatever the cause (unknown, wrong, expired, revoked,
   wrong passphrase, usage limit reached), the answer is the same `404` with
   the same body and the same headers: the entry page with the line "This link
   is not valid or has expired." A `429` (a limiter is hit) is a different
   status by design, keyed on the caller's address or on a token the caller
   already holds, so it does not tell a real link from an invented one. No
   failure path says which check failed.

**Entry cookie.** Every render of the entry page, the `200` and every `404`
alike, sets `__Host-nrws_entry`: a random 128-bit value (`HttpOnly`, `Secure`,
`SameSite=Strict`, `Path=/`, `Max-Age` 2 hours), reusing the value the request
already carried. The form's `form_token` is `HMAC(entry value + timestamp)`, so
it is bound to this browser: a token fetched by an attacker is useless in a
victim's browser, and a cross-site form sends neither the `Strict` cookie nor
a token that matches it. The cookie carries no session and no link.

Session-bound views (all need the cookie; a missing, unknown, expired or
revoked-link session answers the same 404 entry page):

- `GET /nr-landingpage/review/draft`: the draft, its sections, comments and
  suggestions, and the forms the permission allows. Forms are only rendered for
  what the level permits, and every handler re-checks the level on the server.
  A draft's comments, suggestions and reviewer names are shared by all of its
  links: every link sees what any other link of the draft wrote. Separate
  audiences get separate drafts.
- `POST .../comment`, `.../suggest`, `.../edit`: one POST handler per action,
  each with its own permission check, a length limit, and `303` back to the
  draft view (Post/Redirect/Get).
- `POST .../leave`: deletes the session row and the cookie.

**Rate limiting.** TYPO3's `RateLimiterFactory` with
`CachingFrameworkStorage`, as in nr-llm's `A2aAuthenticator`, configurable under
`$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']`:

| Limiter | Key | Default | Consumed by |
|---------|-----|---------|-------------|
| `nrlp-review-enter` | source address | sliding window, 10 per 15 minutes | each failed exchange |
| `nrlp-review-link` | token prefix | 5 per 15 minutes | each exchange for a passphrase-protected link **whose token hash matched**, before the passphrase is checked |
| `nrlp-review-write` | session id hash | 30 per 10 minutes | each POST in a session |
| `nrlp-review-link-write` | token prefix of the link | 100 per 24 hours | each POST through the link, in any of its sessions |
| `nrlp-review-request` | source address | sliding window, 60 per 15 minutes | every entry attempt **whose token did not match**, before any password hash runs |

Accepted limit: the limiters are built without a lock, as TYPO3 core builds its
own login limiter, because `symfony/lock` is not a dependency of core 13.4 or
14.3. The sliding window reads and saves its counter in two steps, so a burst
of parallel requests can pass a few more attempts than the limit. For
`nrlp-review-link` this means a few extra passphrase guesses per burst by
someone who already holds the 256-bit token.

`nrlp-review-link` is consumed only by someone who holds the secret (the token
hash matched), so a stranger who hammers a known prefix cannot lock the real
recipient out, and invented prefixes create no limiter entries. A recipient
uses one of the five for each opening of the link. A limit that is hit answers
`429` with `Retry-After`. `nrlp-review-enter` is keyed by the source address and
`nrlp-review-write` by a session the caller owns, so `429` does not tell a real
link from an invented one. Entering again gives a fresh session, so the session
limit alone does not stop one link from filling the draft's cap of 500 entries
for everyone else; `nrlp-review-link-write` counts the writes of all sessions of
one link and is far below that cap. `nrlp-review-request` bounds the hash work an address
can ask for without holding a token (the entry page hands out a valid form token
to anyone), at a rate far above what recipients behind one address need. It is
not consumed when the token matched, so clients that share an address (a
reverse proxy that does not forward the client address) cannot lock out the
holder of a valid link by posting garbage; a holder of a valid token is bounded
by the link and failure limiters instead. A passphrase longer than 256 bytes never reaches
the hasher: it is hashed as an empty one and counts as wrong. The source address comes from
TYPO3's `NormalizedParams` (the reverse-proxy configuration of the
installation applies).

**CSRF.** Every POST carries a form token. The entry form's token is bound to
the entry cookie: `timestamp.HMAC-SHA256('nrws-entry|' + entry value + '|' +
timestamp, derived key)`, valid for 2 hours and useless without the cookie it
was made for, so it cannot be fetched by an attacker and replayed in a
victim's browser. A session form's token is `HMAC-SHA256('nrws-csrf|' + sessionId,
derived key)`, so it is bound to the session and, through the session, to the
one link. Verified with `hash_equals`. The derived key is
`hash_hmac('sha256', 'nr_landingpage.workspace', encryptionKey)`. In addition:
`SameSite=Strict` on the cookies, and the `Origin` and `Sec-Fetch-Site` checks.

**Response headers** on every response of the endpoint, including 404 and 429:

```
Cache-Control: no-store, private
Pragma: no-cache
Referrer-Policy: no-referrer
X-Robots-Tag: noindex, nofollow, noarchive
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'
```

The pages also carry `<meta name="robots" content="noindex,nofollow">` and
`<meta name="referrer" content="no-referrer">`. The only cookies the endpoint
sets are the entry cookie and the session cookie. There is no third-party
request and no inline script or style.

**Output escaping.** The pages are built with Fluid on a dedicated template
root, `{variable}` escaping on, no `f:format.raw`. Reviewer names and comment
bodies are plain text: stored as received after control-character removal and a
length limit (name 80, comment 2000, suggestion 4000 characters), escaped on
output, newlines shown with CSS `white-space: pre-wrap`, never converted to
markup. A reviewer name is untrusted display text: it is shown as "name
(reviewer)" and never as a backend user.

### 6. Editing through a link

- **One write rule for every writer** (link edit, suggestion acceptance, backend
  edit): `UPDATE draft SET <columns>, version = version + 1 WHERE uid = :d AND
  status = 'draft' AND version = :read`, where `:read` is the version the
  handler read together with the content it changed. Zero affected rows means
  `409`, a reload and nothing written; there is no read-modify-write of the
  `sections` JSON outside this statement. A link edit also carries the field's
  `base_text` and is refused when the current text differs, so two reviewers
  cannot silently overwrite each other. A refused form is shown again with
  what the reviewer typed and with the `base_text` they submitted, not the
  refreshed text, so sending it again stays a conflict until the page is
  reloaded.
- An `edit` link changes `header`, `subheader` and `bodytext` of existing
  sections of its own draft, and nothing else. The server looks the section up
  by `section_id` inside that draft; an id of another draft is not found. The
  new value is plain text. `header` and `subheader` are stored as that raw
  text (maximum 255 characters). `bodytext` goes through one function,
  `toBodytextHtml()`, that every writer uses: `htmlspecialchars` per
  blank-line-separated block, wrapped in `<p>`. A test feeds markup payloads
  through edit and suggestion to the created `tt_content` record and the
  rendered page.
- **Known loss:** a plain-text replacement drops links and lists of the
  generated HTML body. The review view shows the body as plain text, so the
  reviewer sees what they change; the backend shows the same text before an
  editor accepts a suggestion. Rich text review is out of scope.
- Every edit raises `draft.version`, is stored as a `kind = edit` comment row
  (with the previous text in `base_text`) and is visible in the backend.
- A draft whose status is not `draft` rejects every write through a link.

### 7. Suggestions

A suggestion is a comment row of `kind = suggestion` with `field`,
`base_text` and `suggested_text`. In the backend an editor with access to the
draft sees current and proposed text side by side and accepts or rejects.

- **Accept** goes through the write rule of section 6 (so `status = 'draft'` is
  in the WHERE clause) and `toBodytextHtml()`. It applies `suggested_text` only
  when the field's current text still equals `base_text` (otherwise the
  suggestion becomes `stale` and nothing is written).
- **Reject** sets the status, changes nothing.
- Both are recorded in the audit table with the editor.

### 8. Backend permissions

- **See** a draft: admin, the owner, or a user in a group listed in
  `be_groups`; and in every case the module permission for the workspace
  module. Group membership is the user's resolved group list (subgroups
  included, `BackendUserAuthentication::userGroupsUID`) compared with the
  platform's set function (`ExpressionBuilder::inSet()`), never with `LIKE`, so
  group 1 does not match group 12. A user without access does not get a
  "forbidden", they get "not found", the same as for a draft that does not
  exist.
- **Share and revoke links:** the owner and administrators.
- **Comment, accept, reject, edit:** everyone who may see it.
- **Approve:** everyone who may see it **and** may create a page below the
  parent page, checked with `BackendAccessGuard::checkParentPage()`, the check
  of the wizard's save action. A reviewer's link never carries this right.
- All state-changing backend actions are POST routes of the backend. That the
  core refuses such a request without its route token is **not assumed**: a
  functional test posts to each route without a token and expects a refusal,
  as nr-llm's `A2aTokenRouteTokenTest` does. The actions take a `draft` uid and
  re-check visibility on every call.
- Admin users can see every draft. Nothing in the frontend endpoint
  authenticates as a backend user.

### 9. Approval and the version check

The editor's detail view renders the full current content, the list of changes
made through links since the draft was created (who, what, when), and embeds
the draft's `version`. Approval binds to exactly the rendered version: it
cannot approve content the editor was not shown.

1. Checks `BackendAccessGuard::checkParentPage()` for the **stored**
   `parent_page_uid`.
2. One conditional update, which also takes ownership of the creation:

```
UPDATE draft SET status = 'approved', approved_by = :u, approved_at = :now,
       approved_version = :v, approval_attempt = :attempt
 WHERE uid = :d AND status = 'draft' AND version = :v
```

   `:attempt` is a fresh random value. An affected-rows count of 0 means the
   draft changed (any column page creation reads moves `version`) or was
   approved after the editor looked: nothing is created, the editor sees "The
   draft changed since you opened it" and the current version. Because
   `parent_page_uid` is part of the version, the parent that step 1 checked is
   the parent the page is created under.
3. `PageCreatorService` creates the page from the **stored** draft, never from
   the submitted form. The page's initial insert carries
   `tx_nrlandingpage_workspace_ref = '<draft uid>:<attempt>'`, so the marker is
   durable in the same statement that creates the page; a crash cannot leave a
   page without it. After creation, `UPDATE draft SET status = 'created',
   created_page_uid = :page WHERE uid = :d AND status = 'approved' AND
   approval_attempt = :attempt`. From `approved` on every writer of section 6
   fails its `status = 'draft'` condition, so the content cannot move between
   the update and the creation.
4. **Fencing.** Every step that finishes or undoes an attempt is guarded by
   `approval_attempt = :mine`. When page creation fails, the compensating
   update `SET status = 'draft', approved_by = 0, approved_at = 0,
   approved_version = 0, approval_attempt = '' WHERE uid = :d AND status =
   'approved' AND approval_attempt = :mine` runs, and the pages whose
   `tx_nrlandingpage_workspace_ref` is `<draft uid>:<mine>` are deleted through
   DataHandler first. An attempt whose finalising update in step 3 matches no
   row (a reset or another approval took over) deletes the pages carrying its
   own reference and ends; it can neither finalise nor revert someone else's
   approval. `approval_reverted` goes to the audit table.
5. Creation is idempotent per draft: a draft with a `created_page_uid` creates
   nothing, and a lookup of pages with a `tx_nrlandingpage_workspace_ref`
   starting `<draft uid>:` answers "does a page of this draft exist".
6. A PHP crash between steps 2 and 3 leaves the draft `approved`, possibly with
   a partial page. After 10 minutes an editor who may approve can reset it
   (`approval_reset` in the audit table): the pages carrying the draft's
   reference are deleted through DataHandler, then step 4's update runs with
   the attempt read from the draft, which clears `approval_attempt` and so
   fences any attempt still running. A new approval gets a new attempt.

### 10. Failure behaviour and what is tested

Each of these is a test and not only a sentence:

- expired, revoked, unknown, malformed and wrong-passphrase tokens: identical
  `404` status, body and headers, none of them sets a session cookie, and each
  sets the same entry cookie as the entry page does;
- a usage limit of 1 admits one exchange, not two simultaneous ones;
- a `view` session cannot comment, suggest or edit (server-side, with a forged
  form); a `comment` session cannot edit; no level can approve;
- the token is absent from the HTML and the headers of the pages after the
  exchange, and from the `Location` header of the redirect;
- the token is not in the stored row and not in the audit table, only its hash;
- a link of draft A cannot read or change draft B, including by guessing a
  section id or comment id of B;
- the rate limit answers `429` after the configured number of failures;
- every response of the endpoint carries the header set of section 5;
- a missing or wrong form token is refused; a session form token of another
  session is refused;
- reviewer names and comment bodies containing markup are escaped in the HTML;
- approval is refused when the draft changed after the editor's page was
  rendered, and when it was already approved;
- a backend user without access gets "not found" for a draft's actions;
- one password-hash operation per exchange on every failure path (unknown
  token, known token without passphrase, wrong passphrase, expired, revoked):
  counted with a counting hasher, not timed; the exception, tested separately,
  is a passphrase-protected link whose `nrlp-review-link` limiter is exhausted:
  `429` and no hash at all, also for the correct passphrase;
- a valid token and passphrase succeed from an address whose
  `nrlp-review-enter` or `nrlp-review-request` limiter is exhausted by other
  clients; garbage-only traffic from one address is still limited; hammering a known prefix with wrong
  secrets does not block the right token; invented prefixes create no limiter
  state;
- a form token made for one entry cookie is refused with another entry cookie
  and with none, and a cross-site form sends neither;
- a `__Host-` session cookie has `Path=/`, no `Domain`, `Secure`, `HttpOnly` and
  `SameSite=Strict`;
- two concurrent writers (a link edit and an approval, two stale edits):
  exactly one wins, the other gets `409`; no write lands after `approved`;
- approval after `parent_page_uid` changed, after the title changed, and
  accepting a suggestion while the draft is `approved`: all refused;
- forced page-creation failure: status back to `draft`, no orphan page,
  `approved_*` and `approval_attempt` cleared, a second approval creates
  exactly one page;
- a stale attempt (reset and re-approved in between) neither finalises nor
  reverts the newer approval and deletes only its own pages;
- a crash after the page insert and before `created_page_uid`: the page is found
  by its reference and deleted by the reset;
- markup payloads in header, subheader, bodytext, reviewer name and comment
  through to the created `tt_content` record and the rendered output;
- an open session of a link that was revoked, expired or whose draft was
  deleted gets the 404 entry page;
- a non-HTTPS request gets the 404, a cross-site `Sec-Fetch-Site`, a foreign
  `Origin` and an expired entry form token are refused, and no failure path
  sets a session cookie;
- group 1 does not see a draft shared with group 12;
- a backend POST without the route token is refused, for each route.

## Consequences

- No external service, no account system and no new PHP dependency: tables,
  Symfony's rate limiter (already part of TYPO3 core) and Fluid.
- The fragment-based link depends on JavaScript for the one-click case; the
  pasted-code form covers the rest.
- A link that was copied is as strong as the message it was sent in. The
  passphrase, the short expiry, the usage limit and revocation exist for that;
  none of them protects against a recipient who forwards the link on purpose.
- The review view is plain text. Rich text suggestions need a different design
  (a sanitiser pass over reviewer-supplied HTML) and are not part of this ADR.
- The frontend endpoint is part of the installation's frontend and is switched
  off with `workspace.enabled = 0` (extension configuration; default on).
  Disabled, the middleware passes every request on and the site answers its
  standard 404.
- There is no cleanup job: expired sessions are deleted on the next exchange,
  and expired links stay for the audit trail and are shown as expired.
  Deleting a draft (soft delete) revokes its links.

## Alternatives considered

- **Token in the path with a redirect to a session-bound view.** Rejected for
  log exposure and state-changing GET; see section 5.
- **HMAC with the encryption key instead of SHA-256.** Rejected: ties the
  stored hashes to the key, adds nothing for a 256-bit random secret.
- **TYPO3 frontend users for reviewers.** Rejected: needs accounts, which is
  what the feature avoids.
- **A TCA-managed link table.** Rejected: FormEngine would allow editing the
  hash, the expiry and the permission outside the checks above.
- **Page creation inside the approval transaction.** Not possible with
  DataHandler on the same connection without nesting problems; the
  compensating update of section 9 replaces it.
