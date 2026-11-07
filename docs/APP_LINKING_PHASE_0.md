# Browser-approved client linking: phase 0 inventory

## Completion milestones, 2026-11-05

The current owner instruction supersedes earlier audit-only continuations.
Deliver M0 through M6 in order; activation depends on each milestone's actual
acceptance evidence. The following is an implementation ledger, not hosted
qualification; exact committed SHA/run results are retained in issue #345.

| Milestone | Current state | Remaining acceptance |
| --- | --- | --- |
| M0 stabilization | Complete at `da951ba8` | BASIC `42093436058`; WinApp Ubuntu `42093436030` (718 pass, 12 skip) and Windows (728 pass, 2 skip) |
| M1 seamless linking/list | Exact-SHA BASIC `42098837922` and WinApp `42098837757` green at `15978bc6` | Installed Windows end-to-end; owner waived local browser HTTPS |
| M2 linked upload/compatibility | Linked selection enabled for local Windows profiles; real linked upload/review and four legacy transport combinations passed | Installed Windows click-through |
| M3 create and use | Native receipt-to-mode handoff and HTTPS create/recovery/upload passed; shortcut enabled | Installed Windows click-through |
| M4 visibility | Enabled at `c02effe4`; BASIC `42109129671` and WinApp `42109129395` green | Installed GUI and deployed acceptance |
| M5 media/presentation | Disposable HTTPS protocol and native Tk passed; source gates enabled | Windows audio output and installed click-through |
| M6 final qualification | Exact-source diagnostics and installer smoke configured | Protected FULL and installed Windows acceptance |

M0 at `da951ba8` passed BASIC (`42093436058`) and WinApp Ubuntu
(`42093436030`: 718 pass, 12 skip) and Windows (728 pass, 2 skip).
It classifies nine original failing/error test identities as outdated fixtures:
binding without persisted job history, recreating dispatched state, omitted
watcher merge baseline, the retired launcher cleanup export, and expectations
that age/entry rejection permits ZIP staging deletion. Those tests now model
the actual persistence owner and retain target-mismatch, corrupt-history and
source-retention assertions. The tenth identity revealed a production defect:
the monotone job guard rejected a current successful linked reply because its
write-ahead row was marked uncertain. Completion now requires the same positive
attempt, unchanged source/hash, no prior response and a validated live reply.
Empty/hash/inventory-only or malformed replies and a different attempt cannot
clear that evidence. This does not add retry, cleanup or exactly-once claims.
All 17 reported declaration documentation findings are addressed. Readiness
gates outside M4 remain false pending their separate acceptance criteria.

### Current M2 unknown-upload review

For one current unknown upload, inspect the gallery and original source before
review. The optional server hash probe observes matching bytes only; it does not
confirm registration or prove absence. The explicit review prompt defaults to
No. Review preserves the uncertainty marker, original file and ZIP, and the
same source path or bytes remain blocked after restart. Other eligible sources
can continue. Review never resends or deletes the unknown source.

A disposable HTTPS protocol fixture exercised two manual Import items and one
Watcher item through the actual PHP/MySQL 8.0.44 upload pipeline. One upload
was stored by the server before its reply was lost. Matching inventory did not
confirm that uncertain request, trigger a resend or remove its source; the
originals remained retained. After review, a new Import excluded a copy of the
unknown bytes and uploaded a different original to the same gallery. Review
does not reassign pending imports to another target. BASIC `42103334038` and
WinApp `42103333699` passed at `4fcdd4b5`. Windows passed all native mode,
Credential Manager, mutex and junction cases (740 pass, 2 skip); Ubuntu had
727 pass, 15 skip. This qualifies the local-profile admission boundary for
ordinary selection. Created-target activation remains separate.

Current Watcher recovery keeps the original target binding when its grant
expires or the app restarts. Explicit local closure preserves queued sources
and unknown-transfer markers. The v2 target lock retains the older
compatibility fence and can reopen only with its matching native file lock.
V1 markers still require inspection. The older test names and outcomes in the
evidence tables describe their original candidate; a released client lock
cannot establish the result of an in-flight server operation.

Supported linked profile topology is a single ordinary directory on a fixed
local Windows drive with compatible WinApp processes. Admission refuses
redirected ancestors, UNC and non-fixed drives; older clients must be closed
before using the same profile. Native file/credential and lease checks are
cooperative, and this path check observes the profile at admission time.

The legacy transport matrix crossed both exact archived clients and servers
(`df36afa1` and `15978bc6`): all four combinations passed inventory, real
multipart upload, scanner, client thumbnail and post-upload SHA-256 matching.
See `tests/fixtures/pgu_legacy_wire_contract.json` for the frozen request
sequence. It uses synthetic `pgu_` keys and disposable loopback HTTP servers.

### Current M3 creation handoff

On the disposable HTTPS server, the WinApp journal created an unpublished child.
Withholding the completed reply left the original intent on disk. A fresh
process recovered the exact receipt using status; the same-key request did
not create a second row. The verified child accepted an image. A separate
no-dispatch attempt required explicit resume with the same key.

Windows native receipt/target publication passed at `9eaa718a` in WinApp
`42104404568` (741 pass, 2 skip; Ubuntu 727 pass, 16 skip). BASIC `42104404501`
also passed. The shortcut requires both target readiness and the current receipt
lease; the saved key and receipt are independent of target publication.

### Current M4 visibility recovery

The visibility mutation's source gate is enabled at `c02effe4` after BASIC
`42107055888`, WinApp `42107055797`, local owned-PHP recovery coverage and the
disposable HTTPS checks below. Installed GUI, MariaDB and deployed use remain
unproven. The client offers this permission only when the server advertises it;
each change requires explicit browser approval. Before applying a selected
change it obtains fresh confirmation. A stale Admin revision or scheduled
publication conflict causes a read-only state refresh; it does not replay the
mutation.

Its v2 marker
binds the original edit revision, unchanged gallery columns and the exact
original, private and target sidecar hashes. The default operator command
`php scripts/inspect_gallery_visibility.php --gallery=ID` is read-only and
returns a review fingerprint only when the current row and sidecar evidence
match. An explicit
`php scripts/inspect_gallery_visibility.php --gallery=ID --reconcile=FINGERPRINT`
may repair only the sidecar selected by that exact current fingerprint; it
retires the marker only after synchronized replacement and readback. Recovery
does not update the database row or its edit revision. Historical v1 markers
remain inspectable but cannot be reconciled automatically. No schema migration
or change to the legacy `pgu_` path is involved.

A disposable HTTPS PHP/MySQL 8.0.44 run covered public, private and unpublished
visibility, fresh GET verification, stale Admin revision and scheduled
publication refusal, and preservation of password, unlisted and NSFW settings.
It injected interruption before and after database commit and recovered through
the exact reviewed CLI fingerprint without a database revision write. Exact-
candidate BASIC `42107055888` and WinApp `42107055797` passed at `8de8e50a`.
Installed GUI, MariaDB and deployed evidence are not present. Older servers do
not advertise this optional permission; the client keeps it unavailable when
discovery omits it.

The discovery and security-design sections below record earlier checkpoints.
For the current experimental source state and its outstanding qualification,
see [Experimental linking implementation](#experimental-linking-implementation-and-operating-prerequisites).

Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
Author: Rudolf Klusal
Tracking issue: https://github.com/klusik/PHP_gallery/issues/345

## Status and scope

### Current M1 implementation state

Version-1 discovery verifies the exact trusted issuer, independent crypto
secret, full core schema and readiness of optional capability owners. If core
linking is not ready it returns `available: false`; unknown optional storage
hides only that operation. The existing gallery-scoped `pgu_` path remains
independent. The native client POSTs to the exact discovered issuer and receives
`handoff_version: 1`, a random short-lived 128-bit non-authorizing Base32
`request_handle`, and the exact issuer's
`index.php?page=app_linking_start&request_handle=...` URL, with a 300-second
expiry. This reuses existing hashed claim/PKCE storage and adds no migration.

The browser saves the handle in session state and redirects to the fixed start
route without the query before ordinary Admin login. After login, a CSRF
protected request binds the handle to the current session and shows explicit
consent for the exact scope and expiry policy. The bearer lasts up to 15
minutes. Compatible normal links request rolling consent, with a seven-day
window. The separate legacy offline option retains its 30-day absolute and
seven-day idle limits. Only Approve issues a one-use code; Cancel returns
a denial to the native listener. A current session may redisplay the same
consent; duplicate, mismatched, cross-account, expired or replayed handles
fail. The handle alone
never authorizes or provides credentials. The client verifies discovery, opens
the browser, validates issuer/state/callback, and exchanges the existing code.
Listing consent is visible and selected by default; clear it for identity-only
access. A listing-capable connection loads its first page without selecting a
destination. Request handles can appear in the initial access log; responses
use no-store and no-referrer. Access and refresh tokens stay out of URLs.

The disposable MySQL 8.0.44 fixture passed the actual HTTPS protocol through
explicit approval/denial, loopback, PKCE, identity, listing and revocation.
The consent renderer was inspected in Codex at 1000 and 360 px. The owner chose
to proceed without a live browser HTTPS journey after local trust failed.
Installed Windows and deployed acceptance remain unverified. Existing
activation/readiness gates stay false until qualified. The historical planning
and design checkpoints below remain context, not current runtime behavior or
qualification evidence.

The tracking issue remains the functional and security contract. Dated phase
notes do not claim deployment qualification.

### Discovery route contract

The public canonical query route is
`<installation-base>/index.php?page=app_linking_discovery` and requires no
rewrite rules or Admin session. GET returns version-1 JSON with the current
availability decision; HEAD returns matching representation headers without a
body. Other methods
receive 405 and `Allow: GET, HEAD`. Responses use
`Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`,
and `X-Content-Type-Options: nosniff`. There is no clean-URL requirement.

The response never includes credentials, identities, gallery lists, internal
feature settings or database configuration. Availability requires the
verified exact issuer, independent linking secret, complete core schema and
ready optional capability owners. Unknown optional storage hides only its
operation. The gallery-scoped `pgu_` upload credentials and POST upload
semantics are unchanged. Discovery is not authentication.

The ordinary runtime dispatcher owns route selection; the public HTTP controller
owns the response. The normal generated runtime plan is required, and the
route does not initiate visitor maintenance. Real deployment and exact-candidate
qualification remain separate from source and fixture coverage.

## Existing owners to inspect

| Concern | Existing source or contract |
| --- | --- |
| Browser Admin authentication and CSRF | `app/controllers/admin_auth.php` |
| Existing gallery-scoped upload authentication | `app/controllers/upload_automation.php` |
| Upload-token persistence | `app/models/upload_automation.php` |
| Existing token schema | `database/migrations/202605160001_upload_automation_tokens.php` |
| Gallery-creation safety | `app/services/gallery_creation_safety.php` |
| WinApp legacy configuration | `winapp/uploader/config.py` |
| WinApp integration and UX | `winapp/README.md`, `winapp/AGENTS.md` |
| Schema/migration readiness | `docs/AGENT_SCHEMA_POLICY.md`, `DATABASE.md` |
| UI design | `docs/ADMIN_DESIGN.md` |
| Compatibility | `docs/COMPATIBILITY_LIFECYCLE.md` |

These are entry points, not proof that they are the complete domain ownership
chain. Inspect callers, services, models, dispatch, current auth identity and
the upload transport before selecting extension points.

## Boundaries for the next implementation increment

1. Keep the existing `pgu_` upload-key workflow unchanged and operable.
2. Reuse the normal Admin login and CSRF owner; a successful login must not
   automatically approve a native application.
3. Keep OAuth-style PKCE and state verification separate from the browser
   session. Never place long-lived bearer credentials in redirect URLs.
4. Preserve the PHP Gallery server as the only gallery data authority.
5. Keep storage and HTTP changes within the existing MVC ownership model.
6. Reject unknown schema state before security-sensitive writes.
7. Keep native client networking client-initiated. Use verified HTTPS for remote
   galleries and direct loopback for an exact configured localhost HTTP issuer.
8. Do not change gallery visibility, access rights or publication semantics.
9. Do not add mobile code or gallery-management operations during the first
   secure linking increment.
10. Add focused negative-path tests and update permanent documentation with
    the implementation that actually changes behavior.

## Next review checkpoint

Before writing runtime code, verify the installation-aware URL dispatcher,
the Admin identity/session and account-role model, the upload controller's
authorization handoff, schema inspection/migration conventions, and WinApp's
current configuration and credential lifecycle. Then define the smallest
server discovery contract and its unsupported-server fallback, followed by
a separate security review of registration, consent, code exchange, token
rotation, revocation and per-gallery authorization.

## Phase 0 handoff

Only this planning document was added. There is no runtime, database, UI,
installer or migration change. No TeX manual impact: the documented product
behavior remains unchanged. This document is not evidence of a completed
security design or implementation qualification.


## Phase 0.1: verified server-discovery design checkpoint (2026-11-03)

### Source-level ownership findings

- The branch remains based directly on `develop` at
  `df36afa1d37e55096242e53cd857a47ba7da1d3a` at this checkpoint.
- `app/bootstrap/routing.php::cms_route_from_request()` strips the actual
  installation mount before matching clean paths and continues to honor
  `?page=...` query-string routes.
- `app/bootstrap/dispatch.php::cms_route_handlers()` is the canonical
  page-to-controller map. `app/runtime/bridge.php::cms_runtime_kernel()`
  requires every page to have a module entry in the reviewed, generated
  `app/runtime/modules.php` plan. Adding a dispatch-only route is invalid.
- `app/helpers_request.php::request_script_base_path()`,
  `request_mount_url()` and `url_for()` own installation-aware paths.
  Discovery must not derive a base URL from the hostname alone.
- `app/controllers/admin_auth.php` establishes authenticated Admin identity
  in the existing PHP session. Discovery does not need to read or expose it.
- `app/controllers/upload_automation.php::cms_upload_automation_upload()`
  currently enforces POST, validates the upload schema and resolves an
  existing gallery-scoped API key. The client key is recognized from
  `X-Gallery-API-Key`, Authorization or the legacy form field. Do not
  repurpose GET `/api/upload` as discovery or alter these credentials.
- `app/services/feature_flags/registry.php` is a configured/effective
  internal feature-policy and Admin-settings registry, not a public
  installation discovery protocol. Publishing the whole registry would
  unnecessarily disclose internal configuration and conflate feature
  management with the native-client protocol.
- `winapp/uploader/discovery.py` currently discovers local files and ZIPs;
  its name does **not** denote server discovery. WinApp persists the manual
  upload configuration in `gallery_watch_upload.pyw`, with versioned
  normalization owned by `winapp/uploader/config.py`.
- No grant storage, schema migration, or live Admin/WinApp behavior is needed
  to advertise a hard-coded, safely unavailable linking state.

### Earlier security design notes

The following discovery shape was the pre-implementation proposal. The current
runtime contract is described under Current M1 implementation state and
Discovery route contract above; retain the following material only as design
history where it adds constraints not superseded by implementation.

Use a dedicated canonical route `app_linking_discovery`, served by a small
HTTP controller through the existing dispatcher and generated runtime module
plan. A stable query-string address works without rewrite rules:

`<installation-base>/index.php?page=app_linking_discovery`

An optional clean route `<installation-base>/api/app-linking/discovery` may
map to the same page via the existing path dispatcher, but must not be the
only supported address. Do not add an alternative front controller.

The version-1 representation, returned only for GET and HEAD, is:

```json
{
  "contract_version": 1,
  "app_linking": {
    "available": false
  }
}
```

HTTP 200; Content-Type `application/json; charset=utf-8`;
`Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
HEAD returns the same status/headers without a body. Unsupported methods
return 405 with `Allow: GET, HEAD` and no credentials or private data.
No user, gallery, API key, consent, token, feature registry or configured
endpoint URL is exposed. The initial response must **always** advertise
`available: false`, regardless of Admin login or existing manual upload
keys. Enabling it in future requires the completed security and
authorization implementation and separate review.

Client interpretation:
1. Valid HTTP 200 JSON, exact supported contract version, and boolean
   `app_linking.available === true`: linking can be offered **only** once
   all authorization endpoints and credentials are actually shipped.
2. Valid HTTP 200 JSON and `available === false`: discovery is supported;
   linking is not available, keep manual upload-key setup.
3. A confirmed older installation returning a documented not-found or
   method-not-allowed response at the *known discovery address*: classify
   discovery as unsupported and retain manual setup. HTML, malformed JSON,
   unsupported versions, redirects, timeouts, TLS failures and 5xx responses
   must **not** be treated as proof of availability. Report unknown/error
   separately, while leaving existing saved manual settings unchanged.

The client must validate the exact origin and installation mount before
contacting this unauthenticated route, limit redirects to the same verified
origin/mount, bound network/JSON sizes and timeout, and never send existing
`pgu_` keys during discovery. No CORS wildcard or browser cross-origin
credential access is required.

### Decision and implementation gate

**Decision: keep this checkpoint documentation-only.** Reusing the internal
feature registry would expose the wrong contract; attaching discovery to
`/api/upload` would change the semantics of its established authenticated
upload surface. A new canonical page also requires the repository-owned
runtime-module source inventory/generation and its route-map checks, not
just one controller. Do not hand-edit `app/runtime/modules.php`, its
generated file inventory, or the updater manifest as a shortcut.

The next bounded implementation increment should add the dedicated
controller/route, prepare the runtime plan through its canonical generation
workflow, add focused GET/HEAD/405/query-path/subdirectory/JSON/security
regressions, update permanent developer/operational documentation and all
affected manual editions, then push the feature branch and run exact-SHA
hosted BASIC. Do not trigger FULL or integrate into develop.

This phase adds **no runnable endpoint**, changes no Admin/WinApp behavior,
does not modify `pgu_` tokens, and makes no CI qualification claim.

## Phase 0.2: runtime preparation blocker (2026-11-03)

The requested endpoint cannot be shipped as a working, self-contained feature
branch under the current preparation and verification constraints:

- `cms_runtime_kernel()` checks every canonical route against the tracked
  `app/runtime/modules.php` before processing any request. Registering
  `app_linking_discovery` without a matching generated entry would therefore
  make all routes fail during kernel construction, not just discovery.
- Ordinary feature pushes run **Basic source checks** only. The installed
  `.github/workflows/candidate-preparation.yml` expressly does not generate
  runtime metadata for these pushes. A successful BASIC result would not prove
  that discovery works or that the runtime plan is current.
- The installed hosted preparation paths are part of the separately authorized
  integration or CI-maintenance/FULL workflows. The requested increment excludes
  FULL, integration and squash. Local generation is restricted to explicitly
  requested maintainer recovery or unavailable hosted preparation.
- The canonical `scripts/prepare_candidate.php` generates the runtime plan,
  index-based inventory and complete-file manifest in that order. Hand-editing
  those outputs, adding a parallel loader, or registering the route without its
  plan would violate the existing runtime contract.

No endpoint, route, tests, database changes or authorization features were added
at this checkpoint. Existing POST upload and `pgu_` key code were not changed;
no new functional or HTTP regression evidence is claimed. No TeX manual impact:
this checkpoint documents a delivery constraint and changes no supported
product, operational or developer workflow.

A follow-up needs a separately authorized, compliant preparation path that can
publish the complete generated candidate without invoking the excluded FULL,
integration or squash operations. Once that path is agreed, implement the route,
controller, focused HTTP/security regressions and synchronized documentation as
one coherent candidate, then verify the exact prepared SHA. Do not advertise
`available: true` until the separately reviewed linking protocol is complete.

## Phase 0.2: implementation after preparation approval

The maintainer explicitly authorized one local execution of the canonical
candidate preparation because the available hosted paths were outside the
requested scope. This authorization applies to this candidate, not a general
change to the repository's hosted preparation policy. The historical blocker
above records the earlier state; it does not describe this implementation as
still documentation-only.

The query route is registered through the canonical dispatcher, and
`app/controllers/public_media.php` provides its static response. The authored
runtime roots reuse `public-robots`, while `app/bootstrap/maintenance.php`
keeps discovery requests out of the visitor maintenance trigger. No OAuth,
PKCE, grants, token exchange, client registration, consent or WinApp linking
is implemented. Test definitions and a prepared candidate alone are not proof
of a successful hosted functional run.

## Proposed next linking increment: security design only

This section proposes one bounded, end-to-end identity-linking increment; it is
not an implemented protocol or authorization to claim one. Discovery must keep
returning `app_linking.available: false`. No consent, authorization code,
client registration, exchange, bearer credential, refresh, or linked-device
revocation described here exists yet. A complete release of this increment needs
its own security review and the exact-candidate verification below.

### Proposed client and callback registration

Register one built-in **public** native client with the proposed identifier
`php-gallery-winapp` and the fixed callback path
`/oauth2redirect/php-gallery`. These are proposed values, not existing client
registration or proof of an authentic binary. Accept only the registered HTTP
loopback IP literals `127.0.0.1` and `[::1]`, with an explicitly supplied
available port and that exact path; reject hostnames including `localhost`,
other hosts, userinfo, fragments, unregistered paths and callback query data.
Allowing a variable loopback port follows
[RFC 8252](https://www.rfc-editor.org/rfc/rfc8252.html); bind the complete
selected redirect URI including its port to the authorization request and
require the identical URI during code exchange. Validate URI syntax before any
redirect, compare registered components unambiguously, and do not treat a client
ID or callback path as proof that the caller is a trusted executable.

Remote authorization, exchange and resource requests use verified HTTPS. The
local HTTP exception requires a verified direct localhost issuer.
The client establishes the trusted installation origin **and mount path** before
starting a request, maintains that issuer binding throughout the flow, and
rejects issuer or origin/mount changes, unexpected redirects and TLS errors.
The loopback HTTP callback is local to the client's machine; it is not permission
for an insecure remote gallery connection. A single short-lived listener binds
only the selected loopback interface, uses the operating system's assigned port,
and closes after the attempt. The client must verify a fresh unpredictable
`state` against the matching in-memory attempt and expected issuer before using
a callback; concurrent tabs and connections cannot share that binding. A
multi-issuer deployment also needs an explicit mix-up defense such as the
issuer-identification mechanism in [RFC 9207](https://www.rfc-editor.org/rfc/rfc9207.html),
or an equivalently reviewed distinct-redirect design.

### Consent, scope and code exchange

Use the existing Admin login/session and CSRF owners. Authentication alone is
never consent: an authenticated ordinary Admin must see a localized, accessible
consent screen identifying the claimed client, selected installation and exact
requested scope, then explicitly approve or cancel via CSRF-protected POST. A
public client name is only a claim, not a verified application identity. Recheck
current Admin identity and role and the same pending request at approval; deny
support/delegated sessions even when `current_user()` exposes their owner's
Admin role. A login redirect must remain an installation-local path and preserve
only an opaque, bounded pending-request reference; an expired attempt, account
switch or lost session requires explicit renewed consent rather than silent
approval. Client verification, exact redirect validation, rate limits, short
expiry, no-store/no-referrer headers and redacted diagnostics are required.

Propose one initial scope, `link:identity`, limited to a minimal authenticated
identity/status read and the holder's ability to revoke its own link. It grants
no gallery enumeration, upload, creation, publication or Admin-wide authority;
additional operations require their own scopes, consent and authorization review.
Existing gallery-scoped `pgu_` upload keys remain separate and unchanged.

Require Authorization Code with PKCE `S256` as specified by
[RFC 7636](https://www.rfc-editor.org/rfc/rfc7636.html) and the current OAuth
security guidance in [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html).
The verifier must use the RFC's 43–128 unreserved ASCII characters; validate
the canonical base64url-encoded SHA-256 challenge and compare in constant time.
Reject missing, malformed or downgraded challenges and never embed a client
secret in a public app. Retain only hashes of unpredictable authorization codes
and bearer tokens; never log codes, verifiers, `state`, tokens, cookies or raw
security exceptions. The redirect may carry only the short-lived one-time code
and client `state`, never an access or refresh token.

At exchange, use one database transaction to lock the matching unexpired,
unconsumed code and recheck the registered client, exact redirect, PKCE proof,
grant scope, current Admin account and schema readiness. Mark the code consumed
with a guarded transition and insert the grant and short-lived token hash in the
same transaction; commit once or roll back everything. Missing or unknown
required schema must fail closed. Concurrent exchanges of the same code must
produce at most one successful issuance. A lost exchange response is ambiguous:
do not blindly retry or recreate a token from the consumed code; restart the
linking attempt and explicitly account for any issued but unreceived grant.
Replay handling must not let a party without a valid verifier revoke another
client's grant. Every authenticated request must recheck token/grant revocation,
expiry, scope and current account role rather than relying on a cached Admin
session. A holder and the owning Admin need a way to revoke the link; revocation
must remain possible with its narrower verified schema where safe.

Initially issue no refresh tokens: after expiry the user explicitly links
again. If a later increment issues them, it must bind each to the consented
client, grant and scopes and use sender constraint or atomic refresh rotation
with replay detection and revocation of the active token family, as required by
RFC 9700. Lost refresh responses, account demotion, disablement, password or
security changes, expiry and explicit revocation need documented fail-closed
semantics before refresh is enabled. Persist no raw refresh token.

### Implementation and verification gates

A proposed MVC split is an HTTP controller for transport and consent, an
app-linking service for client/PKCE/scope policy, and a model for all database
access and atomic transitions. A timestamped migration and named three-state
schema policy belong to their existing owners. Register routes and authored
runtime dependencies through the normal dispatcher/compiler, without raising
runtime baselines; use the existing Admin session, CSRF, security logging and
Theme design owners. Complete the native client callback and secure credential
handling in the same end-to-end increment; no unreachable skeleton endpoint
should be advertised as functional. Finish affected permanent documentation,
translations and all four manual sources with the actual implementation.

Before claiming a working or secure link, verify the prepared exact SHA with
hosted BASIC and the complete required FULL owners, including real disposable
MySQL concurrency and HTTP flows. Required cases include root and subdirectory
installs; both loopback families where supported; invalid redirect variants;
wrong issuer/state/PKCE; invalid and expired codes; simultaneous exchange and
replay; rollback and lost replies; CSRF, cancellation, expired session, account
switch and support-session denial; missing/unknown schema; current-role and
scope checks; revocation; no credential leakage; and unchanged legacy `pgu_`
upload authentication. Verify real Windows callback, secure storage and
consent UI on desktop and narrow screens separately from isolated fixtures.
Review the exact prepared runtime closure and unchanged limits, and record all
missing coverage. BASIC alone does not establish these properties. FULL is not
currently authorized for this work and must not be dispatched until separately
permitted; no earlier SHA can qualify a later candidate.

No TeX manual impact for this proposal: it documents prospective security
decisions and changes no supported behavior or operating instructions.

## Authorization start: redirect confidentiality review

The initial identity-only design had a redirect confidentiality blocker.
A browser navigation carrying authorization `state` in a fragment can pass it
on to a different origin if a server or intermediary redirects before the
start page removes the fragment. A plain cross-origin form POST is not a safe
substitute: an HTTP 307 or 308 can forward its body to another origin before
application code controls the redirect. A preflight of the start URL does not
remove the time-of-check/time-of-use gap.

A same-origin `fetch` with redirect refusal still needs real-browser evidence
for session continuity and service worker behavior. The later pairing-code
implementation below addresses transport design but has not yet received the
necessary browser, database concurrency and security qualification.

### Pairing-code candidate under review

An exploratory alternative keeps the browser's initial URL free of the
authorization parameters. The native client would send its request directly
over HTTPS without following redirects, then display a short-lived one-time
code. An already authenticated ordinary Admin would enter that code on a
fixed page, review the claimed client and identity-only scope, and explicitly
approve or decline. The code is a transferable secret: possession can allow
another person to claim an attempt, so it must not be logged or placed in a
URL. A claimed client name does not authenticate the executable.

The candidate needs bounded, atomic claim and consent transitions tied to the
same Admin and browser session, rate limits, CSRF and exact issuer checks. A
separate configured random key would protect recoverable pending state; its
absence, unknown schema or unavailable encryption must refuse the flow. Key
rotation invalidates outstanding attempts. The browser's refusal to follow a
redirect does not protect secrets from an untrusted TLS terminator, server,
browser extension or service worker. Their trust and request-body logging
remain deployment and review concerns.

This design requires exact schema and foreign-key inspection, a reachable
owner revocation entry, runtime preparation, end-to-end implementation review
and the verification gates above. It does not change existing upload authority.

### Experimental linking implementation and operating prerequisites

This source implementation is experimental and remains unqualified until its
runtime plan and required browser, database and security checks are complete.
It is not an instruction to enable the feature on a production installation.
Discovery reports availability from the current installation state. Identity, gallery listing,
delegated upload and opt-in client refresh are present as experimental source.
Watcher and Import integration depends on the prepared runtime, configuration
and outstanding platform qualification.
The independent scope-capacity migration expands the four linking scope
columns to 255 binary bytes without rewriting grants. Existing short-scope
checks accept either verified width during migration; the separate wide-ready
check requires all four columns and does not grant any new scope. Interrupted
DDL can be replayed, but older runtime code requiring exactly 64 bytes may
refuse widened storage. Actual database upgrade and downgrade coverage remains
unqualified.
The callback limits each metadata-lock wait to at most five seconds, preserves
any stricter existing value and verifies restoration when it reduces the
session timeout. It cannot bound total DDL time.
Its advisory lease ends before the migration runner records the version, so
it does not itself serialize competing checkpoint writers.
The earlier fragment-based start proposal is superseded; this flow does not
claim RFC 9126 interoperability.

The client pushes the request without following redirects. Remote galleries
use verified HTTPS. An installation saved as literal `http://localhost` can
issue temporary and rolling access on direct loopback. PHP checks both socket endpoints,
the exact configured port and mount, and the absence of forwarding headers.
The native HTTP transport connects only to numeric loopback addresses
returned for `localhost`. This uses the browser's special-use localhost
name and keeps the issuer scheme in the existing exact binding.
[RFC 6761, section 6.3](https://www.rfc-editor.org/rfc/rfc6761.html#section-6.3).
Legacy offline consent requires HTTPS. Rolling access also works for the
verified direct localhost issuer. A macOS restart can still reuse an
unexpired temporary bearer for the exact issuer.
The system browser receives a short-lived handle, which the start endpoint
stores in the session before redirecting to a clean URL. After ordinary Admin
login the browser binds the handle automatically and shows the requested access
for explicit approval or denial. Browser
claim and consent use CSRF-protected same-origin `fetch` with redirect refusal.
The request sends the exact browser Origin with an empty referrer. This keeps
the page URL out of Referer while the server can validate the origin.
The server consumes the attempt once, binds
it to the session and Admin. The controller owns the active session, CSRF check
and pending claim ID; the service receives the verified identity explicitly and
the model retains atomic claim/consumption. This requires the session handler's
exclusive request lock. A same-origin browser response carries the
approved code or denial, `state` and `iss` for navigation to the exact
registered loopback callback, without an HTTP 3xx. The client checks the
issuer and state and exchanges an approved code with PKCE S256. On macOS,
an identity-verified temporary listing bearer may be saved in the user's
Keychain up to its original 15-minute deadline. Before publishing a restored
session, the client checks the exact issuer, approved scope and Admin ID
against the server. Revocation or expiry requires fresh approval. Rolling access requires explicit approval of the exact
`rolling_access offline_access` bundle. Older servers use temporary access. The identity scope leaves
uploads to their separate grant or existing `pgu_` key.
The distinct `link:identity galleries:list` scope explicitly authorizes a
bounded list of all galleries visible to an ordinary Admin, including private
and unpublished galleries. It provides IDs, parent IDs and titles, not disk
paths or mutation authority. Each page rechecks the active bearer, issuer,
grant, scope and current role in the same database read. A previously issued
identity-only grant gains no listing access.

When discovery offers `galleries:search`, the basic native link requests the
search bundle with or without upload access. If discovery also advertises
`rolling_access`, the bundle includes the rolling suffix. The same consent
discloses metadata search, including private and unpublished galleries.
The picker sends its expression and continuation ID in a bounded form POST
to `app_linking_galleries`. Queries use the public parser and rank names
including one-edit title matches ahead of descriptions and gallery tags.
Results contain only gallery IDs, parent IDs, titles, depth and up to four
ancestor titles. The server rechecks live authority in the catalog query.
The search cap is 10,000 galleries, with an explicit limited reply above that
size. Existing GET clients and basic grants keep paginated listing.

A gallery selection alone is only
a local preference; the distinct `gallery:upload` scope explicitly permits
upload to all ordinary Admin galleries, including private and unpublished
galleries. The server resolves the selected physical target and rechecks live
authority before mutation. The linked Watcher and Import paths exist in source,
but linked upload target selection is temporarily disabled until safe mode
restoration after restart is integrated and qualified. Listing, details,
gallery creation and receipt acknowledgement remain available; the legacy
`pgu_` upload workflow remains separate. The guard does not affect an older or
already running process. Runtime and cross-version behavior remain unqualified.
For a delegated upload, the server confirms a newly inserted image row for each
original, verifies its final gallery path and observed file identity after any
automatic rename, and rechecks the live feature and grant before returning
success. Missing or ambiguous evidence returns a generic unavailable response;
the original may already exist on disk and must not be replayed automatically.
The client accepts one uploaded original only with one positive image ID and
the exact `upload_evidence: fresh_insert_v1` acknowledgement. An older linked
server cannot provide this evidence; a reply after sending remains unresolved
and must not trigger an automatic retry. This marker is a server assertion,
not a separate cryptographic proof.
These observations do not exclude unrelated filesystem writers or guarantee
durability. Revocation or expiry after the initial check can occur after a
file has been stored; a later refusal does not roll the file back. The legacy
`pgu_` path and its response remain unchanged.
The separately requested and consented `gallery:read` permission adds a
selected-gallery detail request to the identity and listing bundle. Upload
and `offline_access` remain separate choices. Its bounded response contains
only ID, title, revision and the stored visibility, access mode and listing
mode. These are explicit stored values, not effective inherited access; no
filesystem path is returned. Old grants gain no read permission, and an older
server may not support the request. The client reports unsupported or
unavailable without automatically downgrading and leaves the legacy `pgu_`
path available. The detail query checks the live grant, issuer, scope, token,
expiry and Admin role while reading. A read already past that check can finish
after a concurrent revocation; revocation cannot retract an in-flight result.
Real Windows, deployed HTTPS and database execution remain unqualified.
The four exact read bundles are:

- `link:identity galleries:list gallery:read`
- `link:identity galleries:list gallery:read gallery:upload`
- `link:identity galleries:list gallery:read offline_access`
- `link:identity galleries:list gallery:read gallery:upload offline_access`

Claim codes, callback values, verifier, cookies, access tokens and request
bodies must not be logged or included in URLs except for the specified
one-time loopback callback. A lost response is ambiguous and must not cause
blind replay; begin a fresh attempt.

The four exact basic rolling bundles combine gallery listing with optional
search and upload, followed by `rolling_access offline_access`. Their four
presentation counterparts insert `media:read presentation:read` immediately
before the renewal suffix. Discovery advertises `presentation:rolling` only
on verified rolling transport with current wide scope, refresh, and
presentation storage. That marker is a discovery capability. The browser
shows the exact presentation permission before consent, and renewal preserves
the approved bundle. Previously issued links keep their original scope. When
secure storage is unavailable, a new link can use temporary presentation on a
server advertising the marker if the link does not also request search.
On successful
authenticated use or rotation, both existing family deadlines advance to
seven days from that use. The active refresh token inherits those deadlines.
Legacy `offline_access` bundles retain the 30-day absolute limit and
seven-day inactivity limit. Rotation updates the hashes transactionally and
revokes the family and grant when a retained consumed hash is replayed.
Rolling families can prune consumed hashes after seven days, when the
consumed credential would already have expired. The family retains a
maximum of 4,096 hashes. Legacy families retain all hashes until deletion.
WinApp saves the approved refresh credential in Windows Credential Manager
or macOS Keychain. The local binding keeps the non-secret issuer, account ID
and exact scope. Startup can attempt the same saved link. A
cross-process lock and durable pending fence cover one refresh, live identity
validation and verified replacement storage. A definitive expired or revoked
rolling-refresh rejection from the same verified issuer discards the affected
saved credential. An explicit Link action can then start the normal browser
approval flow. Startup leaves approval to the user. An uncertain outcome or
storage failure blocks reuse and requires review. Explicit local forget removes the
saved credential and binding without revoking the server grant; the active
session may remain valid. Server-side revocation remains available separately.
Rolling links keep one saved binding per exact issuer. The legacy Windows
HTTPS option keeps one shared binding. Resolve outstanding work and forget
the local binding before replacing a saved account for the same issuer.
Review or revoke the previous server grant separately.
Neither the Windows integration nor its recovery after crash or power loss has
been qualified on real Windows; source support does not establish durability or
production readiness.

The linked Watcher and Import source capture the exact issuer, Admin and
gallery, keep separate local history, and use a target-specific exclusive lock.
An uncertainty marker is persisted before sending; an ambiguous outcome keeps
the source and must not be automatically retried or deleted. A stale lock is
never stolen automatically. After
closing all uploaders, reviewing the gallery and sources, and preserving the
recovery JSON, a user may manually remove the specific stale lock. Correct local
filesystem exclusivity is assumed; network filesystems and sudden power loss
are not qualified. Linked flight data stays local and does not implicitly send
AI work or PDFs through a saved legacy key.

Routine delegated inventory uses the database checksum only to select indexed
originals. A positive match is returned only after the current authorized
gallery and both database leases are rechecked and each selected direct regular
file is opened and hashed with stable observed identity and the requested
size. The existing response fields and `matched_by` value remain unchanged;
`inventory_evidence: "verified_file_v1"` identifies the new response. A missing,
changed, redirected, inaccessible or over-budget indexed original makes the
entire request return HTTP 503 `inventory_unknown`. An empty checksum index
still returns an empty inventory; it is unindexed, not proof of file absence.
The `pgu_` inventory path is unchanged.

The indexed lookup is direct-only: rows with a slash in `relative_path` are
not candidates, even if a nested original exists in the gallery tree. Such a
row may therefore remain unindexed for this response and a fresh upload can
create another copy. An anomalous selected path containing a backslash, or a
selected symbolic link or multiply linked file, cannot be verified and returns
`inventory_unknown`. The database selects one representative per checksum; an
unverifiable representative remains unknown even if another copy may exist.
This is not complete deduplication of nested or unusual filesystem layouts.

One delegated response verifies at most 8 distinct positive matches, at most
64 MiB per file and 128 MiB in total, with a two-second monotonic budget checked
between filesystem calls. An operating-system call can block beyond that
budget. The linked client batches requests and rechecks each source before a
skip. These checks describe observed bytes, not a permanent guarantee against
unrelated filesystem writers or power loss. The database fingerprint does not
establish that an original file is unchanged.

The experimental delegated inventory accepts `deep_check: true` for exactly one
candidate and returns `action: "recovery"` and `result: "present"` or
`"unknown"`, without a server filename or filesystem path. The service scans
only bounded direct regular image files under the authorized gallery, holds the
existing gallery and global writer leases and rechecks the current feature,
bearer, role, issuer, scope and target. It refuses symlinks and compares file
identity and metadata around the read. `present` means that matching SHA-256
bytes were observed during this probe; it does not prove that the original
upload completed or that the image is registered. `unknown` includes absence,
limits, unsafe paths and incomplete or failed reads, and is never evidence of
failure or permission to retry. The client can explicitly request this
observation for exactly one uncertain marker and a user-supplied size up to
64 MiB. It does not open the recorded local path, clear the marker, retry or
delete anything. The user must inspect the gallery and source before deciding
what to do. The scan checks a two-second monotonic budget between filesystem calls, but
cannot interrupt a blocked operating-system call. External local filesystem
changes are not atomically excluded by application writer leases, so observed
bytes can change after the probe. This source remains unqualified.

The delegated endpoint bounds request sizes and file counts, but PHP or the
web server can parse multipart data before application code executes. Configure
consistent proxy, web-server and PHP body, upload and temporary-storage limits
upstream; application checks cannot undo pre-parser resource consumption.

Normal installation and application updates provision the installation key in
`app_link_installation_key`. The configured website address
(`base_url`) supplies the canonical authority and exact mount. The request
must match that address. HTTPS can use direct transport or the existing
trusted proxy policy. Literal `http://localhost` requires direct loopback on
both endpoints and rejects forwarded requests. Discovery reads readiness without provisioning anything.

Keep nonempty legacy `app_linking.issuer` and `app_linking_secret` overrides
for installations already using them. They take precedence independently and
malformed overrides fail closed. Independent keys for state encryption,
session binding and rate-limit subjects are derived from the selected 256-bit
secret. All nodes serving one installation must read the same durable key.
Include the database in the protected backup. Loss or rotation invalidates
pending requests and session bindings and starts new rate-limit subject
buckets. Issued authorization codes, grants and `pgu_` keys retain their
own lifecycles. Owner revocation uses its narrower schema and remains
independent of the linking key and rate limiter.

The database must support the linking migrations and transactional
InnoDB row locking. The PHP session handler must provide the exclusive request
locking assumed by the claim and consent transitions. Trusted proxy settings
must resolve the actual client address and HTTPS status without accepting
arbitrary forwarding headers. The TLS terminator, reverse proxy, web server,
APM/WAF and application are inside the trust boundary: they must not capture
request bodies or internally forward sensitive requests to another authority.
`fetch` redirect refusal only stops browser-followed HTTP redirects; it does not
prevent an upstream proxy from forwarding or logging a body. An untrusted
service worker controlling the page, browser extension, injected script or
compromised endpoint can expose data; the page refuses an already
controlling service worker but that check is not a complete race-free isolation
mechanism. Audit the deployment and real browsers before enabling it.

The Admin account page links to an owner-only grant list and revocation.
Ownership is the authenticated ordinary Admin account ID in the shared
application database; the same account can manage its grants across stored
issuers/mounts and sees each original issuer. Support sessions cannot use this
path. Bearer authentication and bearer revocation remain bound to their exact
issuer. A bounded list reports explicitly when additional rows were omitted;
visible grants remain revocable. Approval creates an authorization code, not a
grant; successful exchange atomically consumes the code and creates a grant and
bearer-token hash. Approved authorization codes not yet exchanged are not
listed and can remain usable for up to 120 seconds. Revoking a grant never
cancels other pending attempts. A configured issuer is not required to
revoke an existing owned grant, but HTTPS, an authenticated ordinary Admin,
CSRF and the narrower verified owner-revocation schema remain required.

No mixed-version upload result is inferred from this design. Preserve existing
API-key uploads in both directions and verify real mixed-version combinations,
Windows behavior, local callback, database races, proxy/browser security and
rendered desktop/narrow UI before claiming support. No PDF, installer, FULL
workflow or release publication is authorized by this implementation.

The native HTTPS transport uses a 12-second overall request deadline and
best-effort interruption of an active socket. A resolver stuck in the OS may
outlive that deadline and retain the single transport slot until it returns;
its late result cannot start a request. An interrupted exchange has an
uncertain result and must not be automatically replayed. These protections
remain subject to the required platform and end-to-end qualification.

### Experimental gallery creation source

The separate `galleries:create` scope is implemented for explicitly registered
bundles after verified wide-storage readiness. Existing listing, read and
upload grants do not acquire creation authority. The source uses a narrow owner under the gallery writer
lease and a caller-owned database transaction. It inserts the new unpublished
gallery and assigns its canonical URL without repairing or rewriting other
galleries. It refuses occupied paths, incompatible schema, catalog drift and
catalogs exceeding 10,000 rows including the new row. Slug search checks at
most 128 candidates. Neither count limit is a deadline; database and filesystem
operations may still wait.

The linked owner creates the new sidecar exclusively and rechecks the root,
parent, new directory and sidecar identities and bytes before committing. An
existing sidecar or final link is refused; failed work is retained for
reconciliation rather than deleting possibly foreign objects. These checks do
not atomically exclude changes by unrelated filesystem writers.

Before any linked-create folder write, a separate grant-checked transaction
durably records the operation and normalized target reservation. The final
gallery and receipt transaction marks that reservation completed and clears its
active path identity. While any reservation is pending or invalid, both Admin
in-place import owners, discovery photo moves and unmanaged-folder deletion,
picture-manager copies, metadata-organizer moves, moving galleries to Trash and
Trash restoration,
consistency repairs and browser consistency publication, ordinary Admin creation and another
linked create refuse; completed reservations
do not block. Discovery holds the shared writer lease from the reservation check
through its filesystem work and follow-up scan. Picture-manager compound creation
holds the lease through copying and any cleanup; it skips destructive cleanup
when ownership cannot be confirmed. Trash restoration checks before claiming
its entry; moving a subtree to Trash checks before sidecars or movement.
Metadata-organizer batches retain the lease and preserve a newly
created destination after a failed or uncertain move for explicit review. These checks are
not atomic filesystem fences. This is global rather than
path-matched, so no
filesystem case, Unicode or symlink equivalence is assumed. The query bounds its
returned row count, not elapsed time or completed history scanned. Unknown or
partial reservation schema refuses; confirmed pre-migration absence preserves
legacy Admin behavior, assuming no reservation history was lost in a DB-only
restore. Linked creation itself requires the migrated table. There is no
automatic cleanup of uncertain pending reservations, including after an abandon request.
Old runtimes, already-running workers after a lost DB lease, other mutations,
external filesystem writers, independent databases and inconsistent backups
are not protected by this same-database guard. The legacy `pgu_` upload path
is unchanged. A caught failure before the first mkdir attempt may close its
claim and release the active reservation only after confirmed transaction
rollback, both original leases and renewed grant verification; the tombstone
and `no_effect` state commit together. Any mkdir attempt, crash or uncertain
ownership retains pending. Real database concurrency and recovery remain
unqualified.

The unintegrated `upload_mode.py` foundation validates two copies of an
explicit mode record and compares the reviewed generation before publication.
Version 2 binds the record to an exact, validated lexical Windows config path;
this does not establish filesystem identity or resolve aliases and reparse
points. A separate in-process admission owner tracks parent and child work and
refuses a mode transition while registered work remains active.
The coordinator and the ConfigStore mode port add full-snapshot
comparisons and a single-writer anchor update. A string passed as `--config`
retains its original spelling before `Path` conversion; the port verifies that
spelling and both selected path owners remain consistent. Existing `Path`
callers retain only their already-converted spelling. The two-record resolver
allows verified unmigrated legacy profiles without imposing the v2 lexical path
restriction; a v2 binding requires that restriction. Worker integration must
retain admission through each request. These lexical checks do not establish
Windows filesystem identity or rule out aliases and reparse points.
An unintegrated target verifier checks the exact issuer, account and scope,
reads the live identity twice and verifies the selected gallery with one bounded
page request. Its result does not activate upload or replace server-side
authorization; callers must discard cancelled or stale results.
`--once` now uses these owners before constructing its legacy watcher and
holds an in-process legacy lease until the scan returns. It returns a nonzero
status for first setup, linked or unknown evidence, including an unavailable
Windows vault; no scan starts in those cases. Confirmed unmigrated legacy
profiles, including UNC paths, can still use the API-key path. Each once-mode
inventory and upload attempt and redirect rechecks the evidence, but an
in-flight request can finish. GUI worker admission is under active development
and is not qualified. These checks do not provide a cross-process work fence.
GUI mode selection now reads the persisted two-owner mode record and treats
a restarted linked profile as disconnected until an explicit same-target
verification; missing in-memory access cannot silently admit the old API key.
A newly chosen target has a server-verified asynchronous handoff followed by
local mode publication and activation, while accepted imports remain bound to
their original destination across restart. The Tk handoff now rechecks the exact
upload scope captured by the remote worker, and clears the previous local
target when the grant changes or host mode publication is unconfirmed.
It never interprets an unconfirmed publication as permission to use the saved
legacy API key. The ordinary linked upload target
activation gate remains disabled pending Windows/crash and cross-process
qualification. The created-gallery receipt shortcut has a separate,
also-disabled activation gate. Its worker now reconciles the original bound
creation receipt with the server before independently verifying the exact
issuer, Admin account, upload scope and created gallery through the same
two-identity-read target verifier used by normal selection. The original
owner-bound event is rejected if the dialog, session, scope, receipt or
verified gallery changed. Under the journal's existing profile-wide receipt
lease, an accepted callback must ask the host to publish and activate the
exact target using the existing mode coordinator; a local target alone never
confirms mode publication. If publication is unconfirmed, the local target is
cleared and neither the old target nor the manually saved API key is
automatically restored. The server continues to authorize each request.

Both `LINKING_UPLOAD_TARGET_READY=False` and
`LINKING_CREATED_TARGET_READY=False` remain mandatory. The new source-level
handoff is not a native Windows, cross-process, interrupted-publication,
database or HTTPS qualification. A fresh receipt is not itself sufficient to
authorize the current gallery, and a successfully checked target is not a
durable mode commit. The following failure boundaries remain open:

- A crash can interrupt publication between the config anchor and Windows
  vault record, or between durable publication and in-process activation.
  Matching records after restart still require explicit fresh verification;
  inconsistent or missing evidence must remain disconnected or unavailable.
- An outstanding creation intent, accepted import, linked-upload state lock or
  live legacy/mixed-version worker may survive a process crash or run in a
  different process. Local mode admission does not form an operating-system
  work fence across those processes.
- Native Windows Credential Manager mutex/SID behavior, profile path aliases,
  reparse points, directory durability and recovery after power loss require
  platform-specific inspection. Two simultaneous lost or rolled-back copies
  of mode evidence cannot be proven safe by this local protocol.
- Qualify event cancellation after each server request, stale receipt and
  gallery data, lost or changed grant, parallel uploader instances, interrupted
  mode publication and restart with pending imports on a disposable Windows
  profile and HTTPS installation before enabling either gate. Keep upload
  sources and unresolved evidence for explicit operator recovery.

A focused native Windows regression fixture exercises the real Credential
Manager mode record, a subprocess terminated immediately after its vault
write, restart with the config anchor still absent, and matching persisted
records returning only `linked_disconnected`. A fresh GUI mode owner is
also exercised against the real native vault and snapshot store: startup
stays disconnected, explicit same-gallery reactivation can use the live scoped
session, and changed scope or unavailable vault evidence cannot admit legacy.
The test snapshot adapter does not implement the production cross-process
ConfigStore lease and does not prove unrelated writers are excluded.
The fixture also checks mismatched
records, competing native metadata mutex owners, and a target lease retained
after an abrupt uploader-subprocess termination. These tests run only on an
isolated Windows runner with a disposable config path and refuse an occupied
fixed vault namespace. A crash simulation with `os._exit` is not a power-loss
durability test; successful cross-process coordination between cooperating
current-version instances does not fence legacy, noncooperating or concurrent
different-profile writers.

The coordinator's fixed Windows credential namespace
is not safe for multiple profiles. The compare-and-publish check depends on
all relevant processes holding cooperating metadata and work-admission leases;
older or unrelated writers can bypass them. Simultaneous loss of both copies
cannot be distinguished from an unmigrated legacy profile, and restoring both
to the same older generation cannot be detected from these records alone.
Windows APIs and Windows tests have not been exercised for this foundation.
The separate experimental pgu upload journal is not connected to runtime.
Crash-lock recovery, partial-history loss and binding the record to the exact
sent bytes remain unresolved. Its presence does not make legacy replay safe
after a crash; incomplete or ambiguous outcomes retain their sources.

Before sending, the client writes and verifies a complete non-secret intent in
an exclusive temporary file, then publishes it without replacing an existing
pending key. An interruption can leave an unused temporary file or a stale
profile lease; the latter can block further work. Windows filesystem behavior
and directory-entry durability after power loss remain unqualified.
Before publishing a new intent or checking a retained one, the client requests
the current server identity and requires its account to match the selected or
saved account. Unavailable or changed identity refuses the action.
The separate completed receipt follows the same staged principle: under the
profile lease the client exclusively creates a temporary file, writes, flushes
and reads back the complete receipt, then publishes it without replacing an
existing receipt. It checks file identity and link count before and after
publication and removes only a staging entry it can still identify as its own.
An uncertain publication or cleanup retains the pending intent and any
published receipt. A restart may inspect the retained receipt and recheck the
same operation with the server; explicit acknowledgement remains required.
These observations do not atomically exclude unrelated filesystem changes,
and real Windows behavior, hardlink semantics and directory durability after
power loss remain unqualified.
The server keeps
a separate operation claim bound to the installation, client and Admin. A
completed database receipt and new gallery row share a commit. Filesystem
writes are not part of that transaction: an interrupted or ambiguous result
must not be treated as a failed creation or automatically retried. Explicit
status lookup may retrieve a completed receipt, while an unknown result does
not establish absence. After confirming the original title and parent, the
client can explicitly resume the original request with the same operation key,
issuer and account. A missing claim can then be created once; an unresolved
existing claim is not taken over. This does not automatically create a
replacement or prove that an unknown operation had no filesystem effects.
An explicit abandon request instead binds the original key and normalized
input to a permanent server tombstone, preventing any later create with that
key. The unconfirmed status message points to both explicit original-request
resume and closure after reviewing the gallery and storage. It closes an absent
claim or a worker-marked `needs_reconciliation` only
under the operation and gallery writer leases; `pending`, mismatched and
unknown states remain unavailable. An existing completed claim returns its
ordinary current status. Abandonment does not prove absence, delete files or
stop unrelated filesystem processes; an uncertain reply retains the intent.
The client retains a validated receipt until explicit
acknowledgement. Its local receipt handle binds the displayed gallery to a
domain-separated fingerprint of the original issuer, account, operation key,
title and parent. Before acknowledgement or selecting the gallery, the client
checks that this is still the same operation; an old UI result cannot consume or
select a later receipt even when both display the same gallery. The operation
key is not displayed. This local binding does not replace the server status
check. After that network check, the client rechecks the bound local receipt
and applies the target while retaining its profile lease; it does not perform a
network request while holding that local handoff. The server state can still
change after its last status response, and unrelated local filesystem writers
are not atomically excluded. The synchronous lease and local receipt checks run
on the Tk thread, so a slow filesystem can delay the interface. If the target
change notification fails, the internal target and gallery are restored, but a
preflight may already be invalidated or the displayed state may be stale. Real
Windows behavior remains unqualified. Before
using a saved receipt as an upload target, it asks the
server for status again and requires the same result, separate upload
permission and the existing target-change guard. The server verifies that the
receipt's original ID, title and parent still match the stored gallery. A
renamed gallery yields unknown; a moved, deleted or unverifiable reference can
produce a safe unavailable response. Neither permits an automatic replacement
creation. A missing, redirected or unverifiable gallery directory or sidecar
also prevents a completed status. This checks the currently observed objects,
not whether their inodes are the original ones. The receipt is not a live
gallery detail.
New access fields are normal and listed where present, and a present scheduled
publication field is explicitly null and read back. Local flushes do not prove
persistence after power loss. External local filesystem changes are not
atomically excluded.

Real database migration and concurrency, HTTPS integration and Windows
credential, filesystem and recovery behavior remain unqualified. Do not rely
on this experimental workflow in a deployment until its security review and
exact-candidate qualification are complete. The existing manually configured `pgu_` upload path remains
separate.

### Visibility mutation recovery boundary

The separately approved `gallery:visibility` bundles are enabled on the exact
candidate recorded above. Older servers do not advertise this permission, and
the client does not synthesize it. Each mutation requires explicit browser
approval and fresh confirmation. A conflict refresh is read-only and never
replays the mutation. The source owner holds the gallery writer lease and a
live grant transaction, compares the selected revision, refuses an active
scheduled publication, and preserves other gallery columns. Its v2 marker
binds unchanged columns and exact original, private and target sidecar hashes.
It probes file and directory synchronization, persists the marker before
staging conservative `private` visibility, and preserves all other sidecar JSON
bytes. Missing or failed synchronization refuses the operation. Successful
calls to `fsync` alone do not qualify power-loss safety on a particular
filesystem.

The read-only inspection command can issue a review fingerprint for current
eligible evidence. Supplying that exact fingerprint to `--reconcile` permits
only the matching sidecar repair and retires the marker after synchronized
replacement and readback; it does not write the database row or revision.
Version-1 markers remain inspectable but are not automatically reconciled.
The observed disposable HTTPS PHP/MySQL 8.0.44 run covered public, private and
unpublished visibility, fresh GETs, stale revision and schedule refusal,
password/unlisted/NSFW preservation, interruption on both sides of database
commit and exact CLI recovery without a revision write. Exact-candidate BASIC
`42107055888` and WinApp `42107055797` passed at `8de8e50a`; the source gates
are enabled. Installed GUI, MariaDB and deployed evidence remain absent.

The marker and bounded observation helpers are not a general import fence:
existing imports, cached discovery, branch moves, Trash, copy and other sidecar
writers do not become coordinated by the visibility permission. Older runtime
versions do not advertise the new permission; stale cached input or an
older/incomplete backup may also omit its protection. The visibility mutation
and its explicit recovery are qualified only within their current owner.
Database commit and filesystem replacement remain separate. Lease and path
checks cannot atomically exclude unrelated filesystem writers or a lost database
connection; replacement also needs assessed ACL and extended-metadata policy.

### Current M5 media and presentation source

The native photo endpoints accept the active gallery listing grant for media
already eligible for public export. The legacy `media:read` suffix is still
accepted. The shared policy admits only direct non-NSFW photos whose gallery
and ancestors are public, listed, normal-access and non-NSFW. The separate
`media:admin` capability adds explicit browser consent for restricted,
unpublished and NSFW gallery and photo reads. It requires an active
administrator grant on the current installation. A photo must still belong
directly to the selected physical gallery. Older grants retain their original
scope. Rolling Admin Photos bundles can carry the supported presentation,
creation, visibility, search and upload permissions, with their own readiness
and authorization checks. The connected-applications page identifies Admin
photo access and combined gallery and photo visibility by name.
Gallery information returns direct indexed inventory.
Admin-only `gallery_info` and `photo_info` selectors extend Information on
demand. They keep the original `info` response available to earlier clients.
The server binds photo details to the requested physical gallery, returns
opaque refusal for a nonmatching photo, and sends bounded JSON with no-store.
The projection includes indexed dimensions, MIME type, capture and EXIF data.
GPS follows the gallery's map policy. The optional JPEG and WebP quality
values describe current server thumbnail generation settings.
An explicit Admin Photos upgrade can replace a saved native binding after
the app verifies the same issuer and administrator account. It compares the
exact prior hint under the binding lock before publishing the newly approved
scope and vault credential. An earlier Windows global hint moves to the
per-issuer rolling location. A selected upload destination receives the new
scope through its existing mode owner. If publication fails, the client
restores the prior credential and hint while its generation still matches.
Photo visibility also requires the visibility permission and the previously
observed value. The server binds the change to the current grant, gallery,
photo and stored visibility. An uncertain response requires a fresh read.
The write uses the fixed `POST index.php?page=app_linking_media` query.
Photo and presentation reads require their existing GET/HEAD selectors.

The Admin `kind=revision` selector returns a no-store hash of the indexed
direct photo count, newest image ID, newest update and total bytes. It uses the
same gallery authority as Admin photo listing and reads no thumbnail files.
The aggregate is a change hint. A changed revision triggers a photo catalogue
refresh, and the circular arrow can request a refresh directly.

Permanent photo deletion uses the current saved `media:admin` grant.
Previously issued grants with the exact historical `media:delete` scope
remain valid. Discovery advertises deletion with the existing core deletion
schema. The fixed POST accepts `action: delete`, one positive `gallery_id`,
and a unique JSON array of 1–64 positive `image_ids`. It returns
`deleted_ids` on confirmation.
Under the gallery writer lease, the server rechecks the exact live issuer,
bearer, Admin role and scope, then validates every direct photo before calling
the same deletion owner as web Admin. A stale batch is refused as a whole.
An unknown outcome requires a fresh read; clients must not blindly retry.

An explicit contextual Watcher start uses the already approved upload scope.
The client generates a `pgw_` key for the selected gallery. `watch_open` and
`watch_close` on the existing inventory POST send its SHA-256 hash, and
keyed capture requests carry the key in `X-PHP-Gallery-Watch-Key`. The server
requires the exact live grant and gallery, with a 24-hour key expiry. It
rechecks keyed upload authority at the existing commit boundary. Close
matches only its original hash and can be repeated after an uncertain reply.
The temporary key lives in memory during capture. Closing it leaves the
browser link available to Photos. The feature uses the verified
`app_linking_watch_keys` schema and existing upload capability.

Before requesting a capture key, destination admission checks accepted import
work under the existing local owner. The full `upload_state.json` snapshot
uses the same 256 MiB ceiling for admission and read-modify-write. The previous
16 MiB admission ceiling rejected a healthy Windows Watcher history before
gallery verification even though the state writer could still process it.
Admission still rejects unreadable or ambiguous evidence and protects pending
work bound to another target. The separate import-binding ledger and linked
target history keep their own bounds.

Watcher startup exposes a preparation phase before reading the initial folder.
The folder exclusion scan runs in the worker, and REC indicates that the initial
boundary is ready. Stop and Finish present a populated activity view while
preparing the existing drain workflow.

On Windows, the Watcher deletion preference also applies to linked captures.
A fresh successful transfer response and durable acknowledgement precede
native same-handle source deletion. Captured identity, size and digest must
match, and the handle excludes concurrent writers and replacement during the
check. Failure retains the source with a warning. Neither inventory matches
nor uncertain/restarted work independently authorize deletion. Manual import
sources and ZIPs retain the existing policy. Other platforms keep their sources
until an equivalent native owner is available.

Photo metadata uses eight-item pages by default. WinApp can request up to 64 with
`X-PHP-Gallery-Photo-Page: 64`; older servers ignore this header.
Responses cap each binary at 16 MiB and use existing live
grant checks, verified issuer transport and ETag/Range support. Photos and
thumbnails have a separate five-minute quota of 600 requests per IP and
3,600 globally. Thumbnails fit the scene after matte detection. On HTTP 429, Photos reports
the limit and pauses speculative work until Retry. Thumbnail requests can use other
configured renditions or the existing public display file under the same
limits. Explicit `presentation:read` remains required for settings and up to
32 visible MP3/WAV tracks under the public playback policy. The consent text
discloses the local playback copy of authorized music. Native display uses
the AppKit HDR surface on compatible macOS setups and Pillow/Tk SDR elsewhere. Audio
playback uses a local file with WinMM or the macOS system player. Both use
the current schema. A server with the older scope requirement can return
an unavailable photo response to an ordinary listing link.

Photo descriptors may also include a latitude/longitude pair when the selected
gallery's effective EXIF map policy is enabled and the GPS schema is available.
This reuses the public map disclosure policy and includes no path or altitude.
Missing coordinates, disabled maps, or missing/unknown GPS schema leave the
existing descriptor shape unchanged. The WinApp accepts older server responses
without coordinates, and older clients tolerate the additive fields. Deployed
server/client combinations and use of this metadata remain unknown.

The embedded Photos workspace shows direct child folders above the selected
gallery's photos when its default-on Subgalleries switch is enabled. A folder
cover uses the first authorized direct thumbnail. A neutral folder represents
absent or denied media. Photos keeps listings in memory and uses the current
link grant. Photo metadata appends to the scrollable grid. The viewport
drives thumbnail requests and the same looping sequence drives manual
navigation and the slideshow. Adjacent details are prepared in bounded
memory. The grid and default lightbox fit share black-bar detection. The
lightbox offers an original-frame toggle, wheel and trackpad zoom, and F or
F11 for fullscreen. Moving the pointer reveals controls briefly. The
presentation action enters fullscreen, advances through the album and starts
the configured authorized soundtrack. Contextual gallery and photo actions
use the same live session and main window.

The optional binary cache applies when a photo or thumbnail request includes
`X-PHP-Gallery-Photo-Cache: 1` and passes the live grant and public-photo
policy checks. A cacheable 200 or authorized
304 carries `X-PHP-Gallery-Photo-Cache: 1`,
`Cache-Control: private, max-age=60, must-revalidate`, and
`Vary: Authorization, X-PHP-Gallery-Photo-Cache`. WinApp stores validated
bytes and their SHA-256 ETag under a digest of canonical issuer, gallery,
image and rendition. After 60 seconds, a matching authorized 304 refreshes
validation metadata while retaining the bytes. An opt-in catalogue page can
include the current strong ETag of an existing public thumbnail up to 128 KiB.
The server computes proofs only for materialized thumbnails.
WinApp can reuse the matching verified local bytes immediately. Ordinary
Admin-only media receives `no-store` and is excluded from persistent media
reads and writes. On an older server that
returns `no-store` to a conditional request, WinApp retries once without
the validator and accepts only a validated 200 body.

The additive `media:admin-preload-v1` discovery capability permits a separate,
explicit Admin Photos preload without introducing a scope or schema change.
Current descriptors carry strict `preload_eligible` and optionally
`preload_thumbnail_etag`; the public `cache_eligible` and `thumbnail_etag`
contract is unchanged. Eligibility requires a non-NSFW photo and a complete,
cycle-free, non-NSFW gallery ancestry with verified NSFW schema. Missing or
unknown ancestry/schema refuses persistence. Private binary requests use the
fixed `X-PHP-Gallery-Preload: admin-v1` header alongside the existing photo-cache
opt-in. After live grant, current Admin role and exact photo/gallery checks,
the server confirms that header and a strong validator; conditional 304s pass
the same authorization. Unrecognized preload headers fail closed.

WinApp persists the explicit preload intent and private cache namespace under
hashes of canonical issuer, server-verified account, gallery, image and rendition.
Private bytes are always revalidated by the server before display, including
after restart. Public entries retain their existing namespace and freshness.
Retained galleries keep the existing pin policy; unpinned private data counts
toward the ordinary cache quota. Neither local bytes nor a saved preference
grant offline, expired-link, revoked-link or NSFW access. A bounded valid
`Retry-After` delays explicit preload with cancelable progress; retry exhaustion
retains completed entries and the existing server limits.

The same cache holds track bytes under a digest of issuer, gallery, track ID
and SHA-256. An authorized playlist advertises persistence with
`track_cache: true`. A full authorized track response can opt in with
`X-PHP-Gallery-Track-Cache: 1` and `Cache-Control: private, no-cache`.
A current authorized playlist must match the track ID, digest, size and
format before disk reuse. WinApp plays the selected verified local file and
prepares the next track. The cache caps storage at 4,096 entries or 1 GiB and
evicts least-recently-used entries under capacity pressure.

Before the embedded workspace, the Codex render fixture passed desktop 1000 px
and narrow 360 px with
both consent descriptions and 44 px narrow actions. No browser HTTPS journey
was run by owner choice. The disposable HTTPS run passed an eight-plus-one
photo page, thumbnail and detail, same-gallery binding, ETag and Range, and
the inherited visibility, password, unlisted and NSFW denials. Its authorized
WAV round-tripped byte for byte and the same track was refused after music was
disabled. This does not establish Windows playback or an installed build.
Native Tk rendering, slideshow and cancellation passed on Windows at
`1844648a` (BASIC `42112181155`, WinApp `42112181033`). The WAV playback
fixture loaded System32 WinMM but skipped actual playback because the runner
exposed no output device. The reader is enabled; slideshow requests the next
bounded page as needed. An installed Windows click-through with audio is
separate. Browser-only and protected FULL requirements are unchanged.

The gallery edit owner contains an isolated filesystem-fence prototype with a
fixed disabled admission gate. It opens only an already provisioned lock file,
checks its directory and single-link inode and never rewrites or deletes it.
It is not acquired by gallery writers and offers no current protection. Before
any activation, all writers and imports need a compatible shared lock order;
legacy and linked uploads currently acquire per-gallery database locks before
the global writer, and legacy completion and derivative generation extend beyond
both leases. The linked path generates derivatives inside its callback. Any
future fence must cover the complete use case without changing the legacy upload
response.
Mixed runtime versions, unrelated filesystem clients, lock-file replacement,
cross-host flock semantics and power-loss behavior also require explicit
deployment controls and qualification. The prototype does not itself establish
any of those properties.

The operator command
`php scripts/inspect_gallery_visibility.php --gallery=ID` requires verified
schema, an owned lease, the current gallery row and recognizable marker and
sidecar state. Without `--reconcile`, it returns no hashes or filesystem paths,
refuses uncertainty, and does not repair or remove anything. With the exact
current `--reconcile=FINGERPRINT`, it can repair an eligible v2 sidecar and
retire its marker after readback; the database row and revision remain
unchanged. Version-1 markers are inspection-only. The command does not start a
session. Its standard bootstrap can create
`cache/updates/runtime-use.lock`, update `runtime-used.json` during a rollback
window, and migrate or clean an updater activation marker; read-only here
refers to gallery database rows, visibility marker and sidecar. The visibility
scope remains disabled pending exact-candidate gates.

The source contains a separately disabled discovery checkpoint phase that
reuses the existing `step` polling request to check marker absence over batches
of candidate rows. Each step admits at most the existing discovery batch limit
and examines at most 4096 directory entries, including dot entries. Exhaustion
while a single candidate still needs observation returns an error; continuing a
single wide directory across requests is not implemented. Partial rows are not
published. A completed preview is only a snapshot: its marker observations can
become stale and cached image counts remain nonauthoritative. Existing completed
jobs can enter the phase once, and subsequent status reads do not restart it.
This disabled preview does not enforce the final import guard, does not protect
other writers, and does not justify enabling the visibility scope. Exact-candidate
hosted tests and real interruption, concurrency and filesystem qualification are
still required.


## Continuation 5: concurrent-process, in-flight HTTP and restart safety qualification

**Security decision (2026-11-05): BLOCK linked upload activation.**
Both `LINKING_UPLOAD_TARGET_READY` and `LINKING_CREATED_TARGET_READY` remain
`False`. A passing unit or native test proves only its exercised boundary,
not mutual exclusion of older clients, network/server completion, or durable
reconstruction of earlier import work. Existing `pgu_` behavior and its
experimental, unintegrated upload journal remain separate and unchanged.

### Evidence, exact candidates and diagnostic classification

- Starting commit: `e5381b78014194b024641481bf8a5ca486c5ddd6`;
  BASIC **PASS** [#42016136246](https://github.com/klusik/PHP_gallery/actions/runs/42016136246).
  The feature branch was 90 commits ahead of `develop`, zero behind; the merge
  base was `df36afa1d37e55096242e53cd857a47ba7da1d3a`.
- The raw named WinApp JSON, not just the workflow conclusion or PHP adapter
  summary, was compared across [#42008661954](https://github.com/klusik/PHP_gallery/actions/runs/42008661954),
  [#42015268281](https://github.com/klusik/PHP_gallery/actions/runs/42015268281) and
  [#42016136226](https://github.com/klusik/PHP_gallery/actions/runs/42016136226),
  for **both** Ubuntu and Windows. Baseline: 710 tests; last predecessor:
  723 tests. The added test identities passed on Windows or were explicitly
  Windows-only skips on Ubuntu. No baseline-passing identity became a failing
  identity in that comparison; this does not make old failures harmless.
- `ceffd931` fixed the stale append-only offline-scope test contract, keeping
  the first five serialized scope positions and checking the eight appended
  positions instead of requiring the scope tuple to stop after index four.
  Exact-SHA BASIC **PASS** [#42022346260](https://github.com/klusik/PHP_gallery/actions/runs/42022346260).
  [Python #42022346189](https://github.com/klusik/PHP_gallery/actions/runs/42022346189):
  723 tests; Ubuntu 698 PASS / 7 FAIL / 6 ERROR / 12 SKIP;
  Windows 695 PASS / 18 FAIL / 8 ERROR / 2 SKIP, plus 15 failing subtests.
- `43424f3` made the linked target-specific recovery JSON reader reject duplicate
  keys, `NaN`, `Infinity` and overflowing JSON float exponents; the strict
  import target-change inspection now also rejects overflowing exponents.
  This prevents a second `failures` field from masking an uncertain upload.
  Two new regression identities passed on both systems in
  [Python #42023950349](https://github.com/klusik/PHP_gallery/actions/runs/42023950349);
  [BASIC #42023950303](https://github.com/klusik/PHP_gallery/actions/runs/42023950303)
  **PASS**. `d729cfe` restored original per-line endings in the same source
  patch without changing its normalized Python text; exact-SHA
  [BASIC #42024412327](https://github.com/klusik/PHP_gallery/actions/runs/42024412327)
  **PASS**, and
  [Python #42024412277](https://github.com/klusik/PHP_gallery/actions/runs/42024412277)
  still **FAIL** with 725 tests: Ubuntu 700 PASS / 7 FAIL / 6 ERROR /
  12 SKIP; Windows 697 PASS / 18 FAIL / 8 ERROR / 2 SKIP plus 15 failing
  subtests. The two new tests pass in both raw artifacts.
- Remaining Ubuntu failures include `test_flight_ui` (two GUI mocks),
  `test_flight_watch_drain` (one captured-configuration error and two drain
  assertions), `test_redesign` (mocked legacy inventory, uncertain upload,
  dialog and revoke paths), `test_simconnect_time` (save mock), and two
  `test_windows_startup` GUI fixtures missing `mode_owner`. The same classes
  also fail on Windows, whose additional failures include installer/temporary
  path assumptions, native `WorkspaceLease` permissions, an open SimBrief
  SQLite file, update-helper identity/flow and a native newline assertion.
  Do not mark an issue "historical" solely because its test is outside linking:
  some previously failing fixtures exercise mode-owner integration and must
  be qualified independently. The `test_redesign` 422 mock lacks a normal
  initialized `ManualUploadThread.paths`, so its observed `AttributeError`
  is not a demonstrated server result or a valid successful uncertain-transfer
  assertion.
- Windows `cache/test-audit/latest.json` reports
  `result_reason=invalid-test-reason` and
  `report_schema_valid=false`, producing misleading zero task counts,
  even though the artifact's `winapp-report.json` contains the actual 725
  executed named tests and their tracebacks. This **central PHP adapter
  limitation remains unresolved**; do not cite its zero counts as execution
  results. On Ubuntu the structured adapter accepts the same test-report
  schema. The authorized entry point remains
  `php scripts/audit.php --suite winapp`. No FULL was run.

### Concurrent-process and mixed-version safety matrix

| Concurrent situation | Mechanism and direct evidence | What is **not** proven / remaining failure | Linked activation |
| --- | --- | --- | --- |
| Current linked + current linked, same profile | `WindowsInstance(CONFIG_DIR)` acquires a global path-derived Windows mutex before startup; `LinkedTargetLease` uses an exclusive target-specific `.lock`. `test_mode_windows_native.NativeWindowsProcessTests.test_current_desktop_mutex_excludes_same_profile_across_pids` and `test_target_lease_survives_crashed_process_and_blocks_reuse` PASS on Windows. | Neither lock is held by every distinct profile; a lease is not an end-to-end server transaction or a cross-host mutex. Different profile roots can upload to the same server gallery. An old server request can outlive the process holding the lock. | **BLOCK** |
| Current linked + current legacy, same profile | The current GUI/CLI entry points share `WindowsInstance`; process-local `ModeAdmission` refuses changes with active `CoordinatedWork`. | Mixed-process HTTP lifetime, independent profile roots, different account identities and lock-ignoring entry points are not excluded. The vault metadata mutex covers record transactions only, not complete request lifetimes. | **BLOCK** |
| Current linked + older legacy uploader | Current mode anchor/vault mismatch and saved target identity fail closed for a cooperating current client. | An older binary does not know new profile/mode/target leases; it can continue using the saved `pgu_` key or an already dispatched request. Detecting an old executable by name or checking the Windows process list is not proof that its PHP request completed. No safe way was demonstrated to stop or exclude it without touching configuration or interrupting a live transfer. | **BLOCK** |
| Current legacy + older legacy uploader | Current `WindowsInstance` excludes other **current** desktop launches sharing its profile. Some flight work has a separate `WorkspaceLease`. | Older writers can ignore both locks and race on the shared `upload_state.json`, `import-bindings.json`, config or remote destination. Current legacy protections cannot retroactively fence an old upload. | **BLOCK** |
| GUI uploader + CLI `--once` | `main()` acquires `WindowsInstance(CONFIG_DIR)` before branching to GUI or one-shot execution; CLI independently runs `ModeCoordinator.refresh` and `acquire_work(view, 'legacy')`. | Native subprocess tests exercise the mutex but not every real CLI/GUI transfer permutation. `--config` changes config evidence, not the fixed `CONFIG_DIR` mutex. Separate profile roots, stale server work and an old CLI still need qualification. | **BLOCK** |
| Restarted uploader + exited old process with HTTP in flight | Saved mode records resolve to linked-disconnected; there is no silent `pgu_` fallback. A linked unknown send retains an uncertainty marker when successfully published; a crashed linked owner leaves its `.lock`. Native Windows crash/reconnect tests PASS. | A TCP close, process exit and named-mutex abandonment cannot establish server rollback or completed PHP work. A lost marker, deleted lock, older sender or restored old backup destroys the provenance proof. No server-coordinated fencing/idempotency proof exists. | **BLOCK** |

For these rows, **BLOCK** applies to enabling the new linked target selector;
it does not mean that ordinary existing legacy upload is globally disabled.
Tests of the new process owner establish cooperative, same-profile behavior
only. `WindowsInstance` hashes
`os.path.normcase(str(profile.resolve())).casefold()`; exact profile path
identity, Windows junction/reparse semantics, alternate Windows sessions and
untrusted/non-cooperating binaries have not all been established by those tests.

### HTTP request lifetime and destination custody

| Stage | What the client can establish | Forbidden inference and safe disposition |
| --- | --- | --- |
| 1. Prepared, not sent | Captured worker configuration, target and source checks may be validated locally. | An unsubmitted task is not proof that earlier tasks in the same batch completed. Keep its original binding and do not silently retarget a persisted accepted job. |
| 2. Send began | Linked manual/watcher writes an uncertain marker **before** transmission; legacy work records a conservative send-started state. | "Connection failed before the return" is not proof that nothing was dispatched. Retain source and exact target; no automatic repeat after ambiguous send. |
| 3. Server received bytes | Client knows its chosen URL, exact linked gallery ID or legacy key, and request metadata. | TCP acknowledgement does not prove server validation or commit. Do not switch the request's destination mid-flight. |
| 4. Server committed, client lacks reply | The original remote operation may exist with no durable client receipt. | Absence of a local success receipt is not permission to retry or delete media. Reconcile where supported, otherwise mark unknown. |
| 5. Verified client acknowledgement | Linked response requires exact `gallery_id`, one `fresh_insert_v1`, strict image IDs; flight reports require exact stable IDs and digests. | A valid receipt proves that request's response, not that another producer has stopped or future state cannot change. |
| 6. HTTP timeout or socket disconnect | Transport error, not the remote transaction outcome. | Never classify as "not uploaded" just because the socket failed. Linked recovery remains uncertain; legacy drain retains sources. |
| 7. Client process exited while PHP continues | No surviving process-local lease proves whether the server operation has completed. | Named mutex disappearance does not resolve the HTTP request. Stale linked `.lock` is deliberately not stolen automatically. |
| 8. Client restarts with unknown result | Matching mode records yield linked-disconnected; target-specific state and bindings are re-read. | Same-gallery reconnect is not proof of no prior upload. Do not auto-retry, retarget or remove sources on missing or rolled-back evidence. |

Transport-specific observations:

- **Legacy `pgu_` watcher/manual:** workers capture the selected URL/key and
  validate pre-request admission where integrated. The send-started branch
  stops new work and retains originals on failure. `test_redesign` still has
  failing legacy reconciliation/422 fixtures, so this behavior is source
  inspection, not comprehensive runtime qualification. Legacy config switches
  do not retroactively change an already-dispatched HTTP request.
- **Linked multipart/manual/watch:** `LinkedUploadTarget` fixes
  issuer/account/gallery; `LinkedMultipart` is single-use, and the host
  publishes an uncertain marker before upload. A target lease covers
  cooperating local producers. Linked inventory and explicit recovery probes
  report presence/unknown, not a definitive causal history of a previous
  upload; a found hash can predate the attempted request. An absent hash does
  not establish non-commit while an older request may still be running.
- **Watcher and Manual Import:** `GuiModeOwner`/CLI `CoordinatedWork`
  lifetime and pre-request checkpoints deny new effects after mode evidence
  changes; already-sent requests cannot be cancelled into a proven rollback.
  Manual retry blocks uncertain items and checks the original persistent
  binding. An original captured target remains attached to submitted workers.
- **Flight report delivery:** `FinalizationWorker` holds a legacy mode work
  lease while reporting. `deliver_report` uses a stable report ID, language,
  SHA-256, status-before-send and post-error status reconciliation. If
  reconciliation cannot prove the result, it preserves "unknown". This is
  stronger evidence than a bare socket result but not proof that an older
  server request has already quiesced.
- **Inventory, recovery and AI worker requests:** inventory is a read-only
  server observation and may be stale after return. The AI worker has
  admitted-work checkpoints and its own server claim lease, but the claim
  is not a universal upload-mode fence. Linked recovery requires matching
  marker identity before publishing a local observation; it does not mutate
  or silently resolve the original pending upload.

### Crash/restart and persistence matrix

| Boundary / interruption | Present control or test | Unproven state and required treatment |
| --- | --- | --- |
| Job saved, binding not yet saved | `JobStateStore.inspect_jobs_for_target_change` rejects any unfinished job without its ledger binding; portable `test_orphaned_job_never_infers_destination_from_display_label` PASS. | Not an atomic two-file transaction. Missing provenance blocks a target change; never synthesize a binding from UI labels. |
| Binding saved, job absent | `ImportBindings.entries()` plus `admitted_imports_match_target` rejects a ledger entry whose job cannot be found; portable orphaned-ledger test PASS. | The legacy `launch_manual_job` order binds and then saves; its interrupted half-publication is blocked, not repaired. |
| One persistent document lost or damaged | Strict, bounded read-only job inspection rejects invalid versions, items, keys, duplicate objects, `NaN`/Infinity and exponent overflow; ten previous portable destination tests plus the new exponent test PASS on both platforms. | No automatic normalization/quarantine in this admission check. An unbound unfinished job or ledger-without-job blocks. The ordinary legacy state reader retains its unrelated permissive behavior. |
| Concurrent producers update different jobs in common JSON | In-process `_STATE_LOCK` protects cooperating threads. `atomic_write_json` flushes one `.tmp` then atomically replaces the destination. | No interprocess lock or atomic compare-and-swap across these read-modify-write operations; the shared temporary basename can collide, and last-writer-wins can discard a job or binding. New code does **not** delete or reconcile it. |
| Restart after an uncertain linked send | `LinkedUploadState` is keyed by issuer/account/gallery SHA; `mark_uncertain` precedes send; `LinkedTargetLease` is fail-closed on an existing lock; the new duplicate/nonfinite JSON regression PASS on both platforms. | If the entire target-specific state was removed before the new process starts, `_history_created` cannot know that it existed. The lock may also be lost or an older client may have sent without it. Manual recovery remains required; no automatic retry. |
| Restart with different account or gallery | Two independent matching mode records and server identity are required; the saved linked mode starts disconnected; targeted native restart tests PASS. | A different profile cannot safely reinterpret a global vault record as its own. Previously accepted imports and remote requests retain their original target. |
| Both related files lost, rolled back or restored to earlier valid versions | Individual documents can be atomically replaced, and current valid bytes can be re-read. | **Critical provenance gap:** if both `import-bindings.json` and `upload_state.json` disappear, the current admission read can see an empty ledger and no jobs. Neither file carries independently monotonic proof of earlier accepted work. Both may also be restored together to an older mutually consistent snapshot. Do not enable target switching on this basis. |
| After vault write but before config anchor | `publish_mode` writes and reads back vault first, then writes/reads config. A mismatch or half-write denies startup; native `test_crash_after_vault_write_blocks_key_fallback_then_requires_reconnect` PASS. | Simulated `os._exit(0)` models a process exit, **not** a real power cut or storage-controller reorder. Neither writer offers a coordinated durable commit across vault and filesystem. |

### Windows vault namespace and filesystem qualification

| Boundary | Proved mechanism / exact native evidence | Remaining gap / decision |
| --- | --- | --- |
| Fixed Credential Manager mode target | `RudolfKlusal.PHPGallery.UploadMode.v1` is independent of local profiles; metadata mutex is SID-bound. `test_another_profile_cannot_infer_legacy_from_fixed_vault_namespace` PASS. | One Windows SID may own multiple distinct uploader profiles, but their mode records share one target. This fails closed on mismatch rather than supporting simultaneous independent profiles. Requires namespace decision before activation. |
| Named mutex, process ownership | `_WindowsCredentialMutex` checks effective SID/thread and refuses abandoned mutex; native `test_native_metadata_mutex_serializes_two_windows_processes` PASS. Profile owner process mutex and stale linked lease have separate native PASS tests. | Mode metadata acquisition is not a lock held for the complete HTTP lifetime; unrelated old binaries and profiles need explicit exclusion/fencing. ACL inheritance, multiple sessions and process exits during in-flight PHP work are not fully qualified. |
| Lexical config path vs physical identity | `profile_path_digest` hashes an exact drive-rooted Windows spelling and does **not** collapse case, dot aliases or junctions; `test_case_alias_of_existing_config_fails_lexical_mode_binding` PASS. `ConfigSnapshotStore` checks file/parent identities and rejects ordinary direct symlinks/hardlinks where observed. | Distinct names can address the same directory or file, and those path guards are not a global file-identity mutex. Relative/absolute CLI spelling, UNC, junction/reparse behavior, hardlink/symlink races and TOCTOU need broader native tests. Case-alias refusal demonstrates conservative mismatch, not canonical identity equivalence. |
| Atomic file publication | Config writer stages a unique file, calls `fsync` on the file, `os.replace`, and reads back; the older upload-state writer uses a fixed `.tmp` and no cross-process CAS. | Read-back and file `fsync` do **not** establish parent-directory durability after a power failure or atomicity with Credential Manager. Native open-file replace semantics may differ from Linux; one linked target lease replacement test is **SKIP on Windows** because replacing its open file is prohibited by that filesystem. |
| Interrupted linked lease and unknown HTTP | Native `test_target_lease_survives_crashed_process_and_blocks_reuse` PASS and stale lease files are not stolen. | Simulated termination establishes an owner marker remained, not that the remote PHP operation stopped or that metadata survived physical power loss. Never remove a lock merely because the process no longer appears in a task list. |

All eight tests in `winapp/tests/test_mode_windows_native.py` passed in the
725-test Windows artifact; they were Windows-only skips in the Ubuntu
artifact. Windows native process-lease coverage is substantive but limited to
the tested identity and filesystem configuration. Other Windows-specific test
failures/skips described above remain open.

### Minimum next security steps and activation conditions

1. Establish a single trustworthy admission/ownership boundary **before** the
   first request and covering all cooperating writers for the same physical
   profile and target. Qualify GUI/CLI, different profile spellings, concurrent
   read-modify-write of jobs/bindings, and a minimally invasive exclusion or
   explicit unavailability decision for older clients. Never rewrite an old
   process's config or terminate its transfer.
2. Introduce only the **minimum** independent durable provenance/fencing needed
   to reject a lost or rolled-back pair of histories. A per-job admission
   record/epoch must precede dispatch and be verifiable on restart; scope and
   atomicity must be justified before building a general-purpose journal.
3. Qualify remote in-flight behavior. Where the server offers a stable
   operation identity, investigate server-enforced idempotency/status
   reconciliation and define a terminal "unknown, manual review" state for
   missing evidence. Presence in inventory alone cannot identify the causing
   request. No automatic replay or source deletion.
4. Resolve per-profile vault namespace or explicitly block multi-profile
   coexistence. Verify exact Windows physical file identity, reparse aliases,
   partial credential/config publication and directory-durability assumptions.
   Native crash and real power-loss testing must be kept distinct.
5. Triage still-failing GUI/mode-owner fixtures separately from actual transport
   defects, and repair the Windows central PHP report adapter's
   `invalid-test-reason` failure without bypassing
   `php scripts/audit.php --suite winapp`.
6. Re-qualify each exact commit using the normal BASIC workflow and authorized
   Ubuntu/Windows Python owner. Do **not** enable
   `LINKING_UPLOAD_TARGET_READY` or `LINKING_CREATED_TARGET_READY` merely
   because the new portable or Windows-native tests are green. FULL remains
   explicitly out of scope until requested.

## Continuation 6: durable provenance and cross-process ownership qualification

**Decision (2026-11-05): linked upload and created-target activation remain BLOCKED.**
The changes below narrow specific evidence and test-fixture gaps. They do not
establish a complete monotonic history, exclude old clients, or prove
server-side quiescence. `LINKING_UPLOAD_TARGET_READY = False` and
`LINKING_CREATED_TARGET_READY = False` remain unchanged. Existing `pgu_`
functions, saved credentials, active uploads and source files are untouched.

### Exact commits and platform-qualified results

| Commit | Scoped change | Exact BASIC | Python outcome |
| --- | --- | --- | --- |
| `26aa6332` | Strict read-only `import-bindings.json` admission and two portable regression identities | [PASS #42028002670](https://github.com/klusik/PHP_gallery/actions/runs/42028002670) | [FAIL #42028002635](https://github.com/klusik/PHP_gallery/actions/runs/42028002635): 727 tests, Ubuntu 702/7/6/12, Windows 699/18/8/2 plus 15 failing subtests (PASS/FAIL/ERROR/SKIP). |
| `829ae9ff` | Compare test installer staging directories using physical `Path.samefile()` | [PASS #42028582199](https://github.com/klusik/PHP_gallery/actions/runs/42028582199) | [FAIL #42028582108](https://github.com/klusik/PHP_gallery/actions/runs/42028582108): 727 tests, Ubuntu 702/7/6/12, Windows 702/15/8/2 plus 6 failing subtests. |
| `1832ec70`, `61b1fe2c` | Unique owned staging files for shared state writes, overlapping-writer regression, complete docstrings | [initial FAIL #42029180842](https://github.com/klusik/PHP_gallery/actions/runs/42029180842), [fixed PASS #42029780382](https://github.com/klusik/PHP_gallery/actions/runs/42029780382) | [FAIL #42029780203](https://github.com/klusik/PHP_gallery/actions/runs/42029780203): 728 tests, Ubuntu 703/7/6/12, Windows 703/15/8/2 plus 6 failing subtests. |
| `a07c95aa` | Complete legacy post-dispatch HTTP 422 fixture and assert one attempted multipart call | [PASS #42030153970](https://github.com/klusik/PHP_gallery/actions/runs/42030153970) | [FAIL #42030154066](https://github.com/klusik/PHP_gallery/actions/runs/42030154066): 728 tests, Ubuntu 704/6/6/12, Windows 704/14/8/2 plus 6 failing subtests. |
| `db77b357` | Startup settings GUI fixture invokes the mode-owned save callback | [PASS #42030493797](https://github.com/klusik/PHP_gallery/actions/runs/42030493797) | [FAIL #42030493875](https://github.com/klusik/PHP_gallery/actions/runs/42030493875): 728 tests, Ubuntu 706/6/4/12, Windows 706/14/6/2 plus 6 failing subtests. |

All figures above are raw named WinApp results from the authorized
`php scripts/audit.php --suite winapp` workflow, not synthetic zero totals.
Two new strict-ledger identities and the overlapping-writer identity PASS
on both platforms. Eight native Windows mode/process identities PASS on
Windows and SKIP on Ubuntu. The Windows PHP adapter's previous
`invalid-test-reason` was caused by an overlong traceback from the installer
test's lexical comparison of Windows 8.3 and long-path aliases. After the
physical-identity fixture correction the adapter accepts the full raw report
without relaxing its 16,384-byte reason bound. Overall Python remains FAIL.

### Precisely narrowed protections and unresolved boundaries

| Boundary | Present mechanism and direct evidence | Not established / remaining failure mode | Activation |
| --- | --- | --- | --- |
| Binding-file bytes and file identity | `ImportBindings.entries()` limits reads to 16 MiB, requires a regular single-link file, compares file identity before/open/after, rejects JSON duplicate object keys, nonfinite numbers, invalid schema and binding values. Two portable test identities PASS. | Missing file still reads as empty, and an adversary or failed disk can remove or restore a valid older ledger. The test is not a native symlink/junction race or power-loss test. | **BLOCK** |
| Shared staging name | `atomic_write_json()` now uses same-directory `tempfile.mkstemp` with an independent exclusive stage for each caller. A deterministic overlapped two-thread test PASS on both systems. | It proves no fixed `.tmp` truncation and final JSON integrity in the tested overlap, **not** independent Windows PID coordination, write serialization, metadata durability or preservation of two independently edited snapshots. | **BLOCK** |
| Shared read-modify-write transactions | `_STATE_LOCK` protects current threads; `JobStateStore` and `ImportBindings` still each load their whole JSON and replace it. | Two writers with disjoint accepted jobs may both read state version N and publish different N+1 snapshots. Neither a common `.tmp` fix nor a single-file atomic replace performs CAS or a cross-file transaction. Old binaries need not participate in any future lock. | **BLOCK** |
| Job stored before binding | `admitted_imports_match_target()` rejects an unfinished unbound job; portable orphan test PASS. | Crash between files cannot be repaired by guessing the target from display labels. Leave the job and block retargeting. | **BLOCK** |
| Binding stored before job/HTTP | `launch_manual_job()` persists the binding first, then the job before starting its worker; orphan-ledger inspection rejects a missing job. | Crash before the job write leaves a blocking orphan. Crash after both files but before/while submitting HTTP does not prove which network side effects occurred. Do not auto-retry. | **BLOCK** |
| One state file lost | A surviving unfinished job or orphaned binding blocks a different target; malformed ledger bytes refuse admission. | A completed-job cleanup, concurrent stale writer or unrecorded old process may obscure whether any work was still in flight. Do not infer more than the surviving bytes prove. | **BLOCK** |
| Both files lost | **No independent trusted monotonic witness exists.** Existing missing-file readers can report no jobs and no bindings. | A clean-looking empty state is observationally identical to complete joint deletion after an accepted upload. There is no client-only proof of no older server request. | **BLOCK** |
| Both files rolled back together | Valid files and matching entries can be read without parse errors. | A mutually consistent older snapshot is not evidence that a later accepted operation never existed. File checksum/mtime, duplicated JSON or file-atomic replace is not a rollback-resistant monotonic counter. | **BLOCK** |
| Target-specific uncertain marker lost | Target-bound linked state and `LinkedTargetLease` preserve an existing unknown result and deny reuse of a surviving lock. Native stale-lock test PASS. | Losing both marker and lock erases local witness. An older sender may never have written either. Keep linked upload disabled and never infer a safe retry from inventory. | **BLOCK** |
| Different account, gallery, Windows profile | Saved linked mode restarts disconnected and demands current server identity plus exact issuer/account/gallery. Native profile/mismatch/reconnect tests PASS. | One SID can contain multiple profiles while the mode vault target name is fixed; `WindowsInstance` hashes resolved path spelling, not every physical alias. Hardlinks/junctions/reparse points and other SID/session scopes are not universally qualified. | **BLOCK** |
| Older GUI/CLI `pgu_` client | Current cooperative GUI and CLI `--once` share current `WindowsInstance` admission for the same profile; current `GuiModeOwner` protects admitted work. | Old executables can ignore every new mutex or marker, and an old PHP request can outlive the process. Neither task-list absence nor mutex abandonment is a server-side completion proof. Never terminate a competing process or rewrite its settings. | **BLOCK** |
| Atomic replace and crash | The writer stages a complete file and attempts file `fsync`, then replace; existing native `os._exit(0)` tests establish only process termination and observed surviving marker behavior. | Current state writer still tolerates file `fsync` errors; parent-directory fsync / directory durability, vault+file atomicity, real power failure and host-storage reordering remain unqualified. | **BLOCK** |

### Remote request identity: what a minimal solution would need

On the linked image path, the server returns `upload_evidence=fresh_insert_v1`,
the selected `gallery_id` and inserted image IDs after successful handling.
That response is request-specific **only when the client receives it**.
The multipart `image_client_ids[]` field is used to associate images and
client thumbnails, **not** as an idempotent operation key or durable status
lookup. A timeout or disconnected socket may follow a committed server write.
Neither a matching inventory content hash nor an absent hash proves that a
particular prior upload request committed or stopped.

To close this gap, a **separately reviewed minimal** protocol would require
one stable client-generated operation identity recorded before dispatch,
server-side uniqueness bound to the exact issuer/account/gallery and content,
atomic request-status publication with the image storage outcome, and a
queryable status that can distinguish committed from unknown without replay.
A server revision or epoch must also fence **older noncooperating writers**
if changing upload mode could otherwise authorize a second upload. Existing
flight-report delivery already uses stable report IDs, SHA-256 and status
reconciliation, but that does not establish identity for ordinary images.
Nothing in this continuation implements this server API or an automatic retry.

A third locally stored counter or per-job ledger might expose **some**
one-sided losses, but cannot by itself detect removal or synchronized rollback
of *all* local evidence, cannot control an old uploader, and cannot prove
server-side quiescence. It would therefore be misleading to call such a file
a sufficient monotonic provenance/fencing mechanism. No large journal,
experimental `pgu_` journal or speculative local counter was introduced.
Fail-closed behavior remains the only supported activation decision.

### Diagnostic triage and next checkpoint

- The manual HTTP 422 test previously failed before the mocked transport
  because its artificial `ManualUploadThread` omitted `paths`. With that
  fixture completed, it now verifies one dispatch, unknown-result reason,
  batch stop and source retention. **PASS on both systems.** This is a
  simulated transport failure, not proof of remote transaction finality.
- Two startup-settings fixtures previously omitted the new GUI mode owner;
  they now execute its saved callback under an explicit mock, and both PASS
  on Ubuntu and Windows. The fixture does **not** qualify actual Windows
  cross-process ownership. Production mode guards were not relaxed.
- Two remaining `FlightUI` revoke tests still reach an actual Tk message
  dialog from uninitialized fixtures: direct revocation is currently refused
  by the production safety gate. Do not reopen it merely to make old tests
  pass. Drain, legacy inventory/reconciliation and Windows path/environment
  failures remain separate diagnostic work.
- Next continuation must first recheck remote HEAD, merge base and issue
  comments. It should qualify end-to-end ownership across independent
  processes and profile aliases, and propose an exact server-side durable
  identity/status contract **before** implementing transport mutation.
  Keep legacy behavior and uncertain originals intact; no source deletion,
  replay or retarget based only on hashes. Use BASIC and central Python
  qualification after each scoped checkpoint; no FULL until explicitly asked.

## Continuation 7: cross-process accepted-work and image-operation identity

**Design/qualification checkpoint (2026-11-05).** Neither linked upload
activation gate was changed. The following source findings are current
observations, not claims that the proposed protocol exists. The single
small Python change in this checkpoint repairs a stale UTC save fixture.
This section introduces **no upload endpoint, database migration, cross-PID
lock, retry, cleanup, reconciliation mutation or new persistent ledger**.

### Current transaction boundaries, with evidence and limits

| Source boundary | Verified source mechanism | Evidence limitation / failure mode | Activation |
| --- | --- | --- | --- |
| Shared JSON publication | `state_store.atomic_write_json` uses an exclusively allocated same-directory `mkstemp`, file flush, best-effort file `fsync` and `replace`. Continuation 6 overlapped-stage test PASS on both platforms. | The test overlaps threads and validates intact snapshots, **not** whole read-modify-write serialization or real power-loss durability. The writer swallows `fsync` errors; parent-directory durability is not established. | **BLOCK** |
| Job and event mutation | `JobStateStore.save_job`, `delete_job`, `append_event` and `merge_upload_sections` read, modify and rewrite the complete `upload_state.json` under the process-local `_STATE_LOCK`. | Two independent processes can both read N and publish different N+1 snapshots. Last writer can erase a job or other import's progress. `ImportBindings` does not share this lock. | **BLOCK** |
| Accepted-job binding | `ImportBindings.bind` and `resolve` separately call the strict `entries()` reader then replace the whole `import-bindings.json`. `launch_manual_job` records a binding before it persists the job and starts work. | Neither individual file nor their pair is a cross-process compare-and-swap or transaction. Inconsistent half-publication fails admission, but valid joint deletion/rollback can look empty. An older writer can ignore any new mutex. | **BLOCK** |
| Windows cooperative owners | `WindowsInstance` owns a process mutex derived from resolved profile **spelling**; `GuiModeOwner` coordinates current work; Credential Manager upload-mode metadata uses a fixed SID-wide target/mutex; `LinkedTargetLease` fails closed on an existing target lease. Eight native mode/process identities PASS on Windows at the previous checkpoint. | Same physical profile under junction/reparse/alternate path may not share the same mutex. Mode-metadata acquisition is not a lifetime HTTP fence. One SID with multiple profiles shares the fixed vault name. Neither native tests nor `os._exit(0)` simulate a power cut. | **BLOCK** |
| Linked image upload admission | `cms_app_linking_upload` validates bearer, target, multipart limits and live authority; `upload_automation_store_images` obtains a per-gallery advisory lock and the global gallery writer before moving originals and scanning/indexing them. Delegated callbacks recheck authority and registration under these leases. | The application moves filesystem entries, records image rows, renames, indexes checksums and installs derivatives in multiple steps. A held writer lease is **not** an atomic transaction across filesystem, image rows and an operation-result row. There is currently **no image-operation result row**. | **BLOCK** |
| Linked success / recovery | Success response supplies `fresh_insert_v1`, `gallery_id` and `image_ids`; `app_linking_recovery_probe` can return only `present` or `unknown` for matching file bytes. `image_client_ids[]` maps originals and thumbnails. | Receipt proves this handled HTTP response only if received. Neither inventory SHA nor `present` attributes a file to the lost request; `unknown` does not imply absence. No status endpoint for a specific image operation exists. | **BLOCK** |
| Separate gallery creation precedent | `app_linking_create_gallery` uses a stable key, retained `admin_operation` claim, live authority transaction, reserved filesystem target and specific result reconciliation. | Its receipt/claim lifecycle is **gallery creation only**. Reusing the idea without a filesystem/DB-aware image transaction cannot establish image idempotency. | **BLOCK** |

The last two rows were checked against
`app/controllers/app_linking/upload.php`,
`app/services/upload_automation.php`, `app/services/uploads.php`,
`app/services/app_linking_recovery.php` and
`app/services/app_linking_create.php`. The local rows were checked against
`winapp/uploader/state_store.py`, `flight_jobs.py`,
`mode_coordinator.py`, `windows_startup.py` and `linked_upload.py`.
This is **source inspection**. None is a new native cross-PID, real
network-fault, shared-host MariaDB or power-failure test.

### Ten recovery cases: what can and cannot be concluded

| Interruption / conflicting history | Observable state today | Defensible response today |
| --- | --- | --- |
| Job exists, binding absent | Unfinished job has no exact target fingerprint. | Reject target change; do not reconstruct from a display label. |
| Binding exists, job absent | An orphan accepted-job ledger entry exists. | Reject target change; do not discard the orphan. |
| Both written, no HTTP yet, then crash | Snapshot may look complete, but the client has no independent proof that the send did not start before its termination. | Keep accepted identity and source; no automatic retarget/retry. |
| Crash during HTTP | PHP may continue, fail before storage, or commit after disconnect. | Unknown; do not replay or delete sources. |
| Two processes change distinct jobs | Each may publish a complete but stale snapshot and erase the other's new job. | No whole-transaction guarantee; **BLOCK**, not a best-effort automatic merge. |
| Only one file lost | Surviving unmatched job/binding generally exposes inconsistency. | Refuse admission; retain surviving data for review. |
| Both files lost | An empty current view is indistinguishable from a fresh workspace. | Treat loss of historical provenance as unknown; do not authorize linked switching. |
| Both files consistently rolled back | Older valid pair can pass schema, identity and binding checks. | Valid parsing is not a monotonic witness; **BLOCK**. |
| Target-specific linked uncertainty marker and lock lost | No local trace survives; an older producer may never have written either. | Never treat absence as permission to resend; **BLOCK**. |
| Older uploader/config restored | Its saved `pgu_` worker can ignore new mutexes and keep an earlier request running. | Do not kill it, rewrite its config or assume process exit ended PHP work. **BLOCK**. |

A third JSON file or locally incremented epoch merely creates another
rollbackable object. It cannot establish a monotonic lower bound if all
local objects can be deleted/restored together. A server-side witness can
help only for **requests actually recorded by the server and whose
identities are still recoverable**. It does not retroactively identify an
older `pgu_` upload or eliminate already in-flight requests.

### Proposed minimal *image* operation protocol, NOT implemented

1. **Identity before dispatch.** Generate one cryptographically random
   256-bit lowercase hexadecimal `operation_id` per upload intent, retain
   it with the accepted source and captured exact issuer, Admin user ID,
   client ID, gallery ID and ordered original/thumbnail manifest before the
   first HTTP attempt. On uncertain loss of that record, do **not** mint a
   replacement ID for an automatic retry. A client-created ID is only a
   correlation candidate until the server enforces it.
2. **Content binding.** Compute a versioned, canonical manifest fingerprint
   covering the original's bytes/size and filename, ordered thumbnail bytes,
   format/size, `create_thumbnails`, approved camera metadata, issuer,
   client, actor and gallery. Server must recompute byte digests from the
   received bounded temporary inputs; never trust an unchecked client SHA.
   Any reuse of one ID with a different manifest or owner is a conflict,
   never an instruction to upload again.
3. **Authoritative unique claim.** Add a separately reviewed MariaDB ledger
   with a unique key on **installation issuer + client identity +
   operation-ID digest** (not access-token hash). Each row immutably binds
   actor, intended gallery, normalized manifest digest and outcome. Rotation
   of the access token must not create a new operation. A mismatching
   account/gallery/content query is refused without revealing another
   user's receipt. Keep rejected/uncertain keys reserved; never silently
   recycle them after retention or cleanup. Define bounded retention and
   non-reusable tombstones before deploying this schema.
4. **Same-key concurrency.** The database unique constraint is authoritative
   for concurrent PHP requests, and a per-operation owner must serialize
   claim/status transitions. Equal key + equal manifest can return the
   recorded committed receipt *after live authorization recheck*. Equal key
   + different manifest yields a permanent `409 operation_conflict`.
   An outstanding owner returns `pending` or `unknown`, **not** a second
   multipart execution. Losing a PHP process-local lock is not a proof that
   its external effects were rolled back.
5. **Commit boundary.** Preserve the existing gallery and global writer
   ordering. A durable pre-effect claim must exist **before the first
   `move_uploaded_file`**. To claim `committed`, the original image
   row(s), their validated provenance and the request's immutable receipt
   must commit in **one MariaDB transaction**, after actual filesystem
   identity checks and current target/permission fencing. Existing
   `store_uploaded_gallery_images_owned` and scanner code do not currently
   offer that transaction contract: a dedicated implementation review and
   rollback/commit failure injection are prerequisites. The filesystem
   itself cannot participate in the MariaDB commit. Files left by an
   interrupted pre-commit move are **unknown**, never automatically removed
   or replayed. Optional derivative failures should be represented in the
   committed receipt, not converted into a claim that originals failed.
6. **Queryable read-only status.** Proposed authenticated
   `POST /api/app-linking/upload/status`, carrying the exact operation ID
   and expected gallery/manifest identity in a request body, not URL.
   Recheck issuer, live Admin/grant, upload scope and gallery authorization
   at every query. Return a bounded, non-secret result with:
   `committed` + immutable image IDs/manifest digest;
   `rejected_no_effect` only if no filesystem/database write could have
   begun (supported by a durable no-effect terminal transition);
   `pending` while a proven current owner exists; or `unknown` for
   absent, partial, damaged, unqualified or ambiguous evidence. An HTTP
   404, expired receipt or missing ledger row is **not automatically**
   `rejected_no_effect`. Do not expose cross-account record existence.
7. **No implicit replay.** On a lost success response, query status using
   the **same recorded ID**. A `committed` receipt permits marking exactly
   those originals confirmed after checking the immutable target/manifest.
   `pending` and `unknown` preserve source and target; `rejected_no_effect`
   requires a new explicit user intent and a new operation ID. The protocol
   itself should not auto-retry an uncertain multipart.
8. **Legacy and migration fence.** Keep all `pgu_` endpoints and existing
   keys functional. A new linked-only operation ledger does **not** fence an
   older nonparticipating `pgu_` worker or HTTP request. A future opt-in
   server-side per-gallery epoch would need to be rechecked by **both**
   legacy and linked PHP paths under the same gallery writer lease,
   including requests admitted before the cutover, before it could serve
   as a migration fence. Such a change would need explicit compatibility,
   drain/revocation and old-version qualification. **It is not authorized
   in this checkpoint.** No process-list query or client mutex substitutes
   for this server-enforced cutover.

**Crash/status proof obligations for that proposal:**

| Failure window | Required durable proof | Allowed answer |
| --- | --- | --- |
| Before claim is durably published and before **any** possible effect | Verified no-effect transition only; otherwise absence is inconclusive, especially across old versions. | `rejected_no_effect` only with proof; otherwise `unknown`. |
| Claim persisted, PHP died before first file move | Claim remains reserved; the owner death alone cannot certify which instruction ran. | `unknown` unless a durable, race-free no-effect terminal record exists. |
| Original moved, DB image/receipt transaction did not commit | Filesystem may contain an orphan or a partly indexed row. | `unknown`; no automatic deletion/retry. |
| DB image rows and immutable operation receipt committed in the **same** transaction, HTTP reply lost | An authenticated status read returns the exact committed receipt. | `committed` with source-specific image IDs. |
| Same key concurrently submitted by two PHP workers | Unique claim and ownership allow at most one mutation; the other observes progress or the same immutable outcome. | `pending`/`unknown` or same `committed` receipt, never a second write. |
| Client's operation ID or entire local history rolled back | Remote lookup by an unknown ID is impossible. | Remain `unknown` and **BLOCK** automatic recovery/retargeting. |
| PHP/DB unavailable, role revoked, scope removed or target changed | Live authority/status cannot be established. | Refuse request; never infer no-effect or rewrite the accepted destination. |

### Cooperative cross-process transaction proposal, NOT implemented

- **Lock scope:** One exclusive ownership domain for the actual *physical*
  profile/workspace, held from acquiring the latest job+binding snapshots
  through validation, mutation, publish and read-back. Bind it to the same
  Windows SID/security principal as the intended profile; do not derive
  identity only from the caller's lexical `--config` or junction spelling.
  Independently running profiles targeting one gallery still need **server**
  operation identity and fencing.
- **Physical identity qualification:** Open handles with explicit access
  checks; compare volume serial/file ID and parent-directory identity on
  Windows, and reject ambiguous junctions, symlinks, reparse points, hardlinks,
  UNC aliases, mixed sessions or identities that change while the lock is
  acquired. A canonical string alone is insufficient. Native tests must
  exercise the actual Windows APIs and independent PIDs, not just mocks.
- **Writer inclusion:** `JobStateStore.save_job/delete_job/append_event`,
  watcher `merge_upload_sections`, `ImportBindings.bind/resolve`, the
  pre-send accepted-work publication and read-only target admission must
  cooperate in one documented lock order. A lock around only
  `atomic_write_json` still permits lost updates because stale reads
  occurred outside it. Publish/read-back ordering must never imply a
  two-file atomic commit.
- **Crash and older binaries:** A crashed new lock owner can leave partial
  job+binding publication, which must fail closed on recovery. A prior binary
  does not honor the lock, and different profiles can bypass a per-profile
  owner. Neither silent takeover nor process termination is acceptable.
  A proof of a server-side request epoch/status is needed before any linked
  activation. A best-effort new mutex may narrow cooperating races but must
  **not** be represented as a complete safety solution.

### Continuation 7 qualification ledger

- `d78bbc60`: `test_simconnect_time` invokes the **unchanged** actual
  `WatcherApp._save_owned_config` under an executing `GuiModeOwner.save`
  fixture. It retains the key and stop-before-save assertions.
  Exact [BASIC #42036289918](https://github.com/klusik/PHP_gallery/actions/runs/42036289918)
  **PASS**. Central Python [#42036289611](https://github.com/klusik/PHP_gallery/actions/runs/42036289611)
  overall **FAIL**; original per-test artifacts show **728 total**:
  Ubuntu **707 PASS, 5 FAIL, 4 ERROR, 12 SKIP**;
  Windows **707 PASS, 13 FAIL, 6 ERROR, 2 SKIP**, plus 6 failing
  subtests. The repaired UTC test is PASS on both platforms. One fewer
  failing test identity on each platform; no production mode or upload
  behavior was changed.
- Remaining unqualified areas include GUI direct-revoke test expectations
  (currently blocked by production), watcher drain capture/queue state,
  legacy inventory and discovery fixtures, Windows installer aliases,
  WorkspaceLease permission, SimBrief SQLite file lifecycle,
  update-helper identity/exit and Windows native newline assumptions.
  Each needs separate diagnosis; do not lower security guards to obtain green.
- **Activation remains BLOCKED**. Preserve every legacy `pgu_` function,
  accepted source, in-flight request, saved configuration and both `False`
  readiness switches. The next checkpoint must prove a minimal transaction
  strategy with independent native processes and a server-side operation
  lifecycle **before** implementing any upload API or enabling target choice.

## Continuation 8: measured native lock and upload-operation boundaries

This section records source-inspected facts and exact candidate tests. The
Continuation 7 cross-process and per-image operation protocols above remain
**proposals**, not implemented invariants. In particular, a green test for one
lock must not be used as a proxy for an atomic accepted-import transaction.

### Actual local ownership topology and competing writers

- `winapp/gallery_watch_upload.pyw` owns `CONFIG_DIR` and
  `STATE_PATH = CONFIG_DIR / "upload_state.json"` (around lines 210-215).
  `WatcherApp` constructs `JobStateStore(STATE_PATH)` (around line 6491)
  and independently constructs `FlightUI(self, CONFIG_DIR / "flights", ...)`
  (around line 6630). The latter constructs
  `ImportBindings(directory)` and `WorkspaceLease(directory)`.
  The two accepted-import ledgers therefore live in **different
  directories** even in a normal single-profile deployment.
- `JobStateStore.save_job/delete_job/append_event` and
  `merge_upload_sections` in `winapp/uploader/state_store.py` still
  perform whole-state read/modify/write using process-local `_STATE_LOCK`
  or separate atomic replacement. `ImportBindings.bind/resolve` in
  `winapp/uploader/flight_jobs.py` also load and replace the entire
  binding document. None jointly holds a cross-process lock from a
  **fresh** two-file read through validation, mutation, publication and
  read-back. Sequential `bind(job)` then `save_job(job)` in
  `WatcherApp.start_manual_upload` is not a two-file commit.
- `WindowsInstance` in `winapp/uploader/windows_startup.py` chooses
  a Global mutex from SHA-256 of a case-normalized
  `str(profile.resolve())`. It does **not** query the Windows volume
  serial / FileIdInfo of the opened workspace. A distinct lexical/
  canonical name, file alias, alternate profile, older executable,
  helper or CLI `--once` cannot be assumed to honor this mutex.
  `WorkspaceLease(owner.lock)` is scoped to the flight directory,
  not the entire profile/state ledger or all server uploads.
- `winapp/uploader/credential_store.py` uses the fixed mode target
  `RudolfKlusal.PHPGallery.UploadMode.v1`. This Windows-user vault
  identity is independent of the profile-name-derived GUI mutex.
  Therefore two differently spelled profiles belonging to one Windows
  principal cannot be assumed to have independent persistent mode
  ownership merely because their mutex names differ. This does **not**
  prove such a collision is exploitable; it means equivalence and
  separation require real native qualification.
- Physical file identity on Windows remains **unverified**: opened
  directory/file handles, volume serial and File ID, hardlinks,
  junctions/reparse points, symlinks, path replacement, UNC spelling,
  multiple sessions and SIDs have no new complete proof. Do not treat
  Python `Path.resolve()` or a lock filename as a physical-identity
  certificate.

**Proven, narrowly:** `3cf7dd47` moved the `WorkspaceLease`
first-byte read and sentinel preparation inside its existing
`OSError` ownership-denial boundary. The original Windows runner
failed with `PermissionError` while reading the byte locked by the
first owner. After the fix and the separate subprocess-fixture newline
correction `b1f005bd`, both the original contention test and new
`test_workspace_lease_excludes_an_independent_python_process` pass on
Ubuntu **and** Windows. The latter starts an actual second Python PID,
which is refused while the first holds the same workspace and succeeds
after release. Exact [BASIC #42045249937](https://github.com/klusik/PHP_gallery/actions/runs/42045249937)
**PASS**; central [Python #42045249884](https://github.com/klusik/PHP_gallery/actions/runs/42045249884)
overall **FAIL**. This proves same-root cooperative exclusion and
handled busy-owner reporting, **not** whole-RMW serializability or
lost-history resistance. It removes one real Windows error-handling
failure; it does not authorize linked upload activation.

**Cross-process transaction decision:** Do **not** implement a
best-effort lock solely around `atomic_write_json`, and do not
describe this lease as an accepted-work transaction. A future
implementation must bind the physically verified profile and flight
directory to the same cooperative ownership domain, include every
mutator and admission reader, reload both documents under that owner,
validate source/target provenance, publish/read back without
representing two separate file replacements as atomic, and reject
inconsistent crash states. An older non-cooperating executable can
still write, so even this design would not by itself prove a safe
cutover. No independent monotonic local witness currently defeats
simultaneous rollback of both ledgers (or rollback of a third JSON
file). The ten Continuation 7 crash/loss cases remain **fail closed**.

### Actual PHP image upload commit boundary

Source trace, with both supported clients preserved:

1. Legacy `cms_upload_automation_upload()` in
   `app/controllers/upload_automation.php` (around line 955) and
   delegated `cms_app_linking_upload()` in
   `app/controllers/app_linking/upload.php` (around line 391)
   both call `upload_automation_store_images()`. The legacy caller
   passes no delegated `authorizeBeforeStore` hook.
2. `upload_automation_with_gallery_lock()` in
   `app/services/upload_automation.php` (lines 1181-1197)
   acquires/releases a gallery-scoped MySQL advisory lock.
   `app/models/upload_automation.php` uses `GET_LOCK` and
   `RELEASE_LOCK`. This is **serialization**, not a MariaDB
   transaction coupling image rows with an operation receipt.
3. `store_uploaded_gallery_images()` in
   `app/services/uploads.php` (around lines 1136-1170)
   acquires the existing global gallery writer, checks an optional
   delegated authority callback, then calls
   `store_uploaded_gallery_images_owned()`. For the legacy caller
   the optional delegated authority callback is absent, by design.
4. `store_uploaded_gallery_images_owned()` (around lines 1180-1460)
   calls `move_uploaded_file()` for accepted originals **before**
   `scan_gallery_selected_uploaded_images()` and before querying
   newly indexed image IDs. Optional renaming and derivatives follow.
   Its delegated `fresh_insert_v1` receipts associate actual image
   rows with filesystem identity, but do **not** commit a stable
   request operation key with them. Filesystem moves are outside
   MariaDB transactions. If PHP dies between a move and row/receipt
   publication, there may be an orphan or partial effect.
5. `image_client_ids[]` are request-local media correlation values
   accepted by both controllers. No unique
   installation/client/operation key is enforced for each image
   upload, and neither inventory SHA-256 nor client ID proves which
   HTTP attempt created a row. `admin_operation_keys` and the
   `app_linking_create_gallery()` transaction provide a **gallery
   creation** precedent; they do not confer image upload atomicity
   on the scanner and filesystem pipeline.

**Server protocol decision:** Keep the exact proposed Continuation 7
256-bit `operation_id`, independent bounded manifest fingerprint,
issuer/client/Admin/gallery binding, unique MariaDB claim, durable
pre-effect intent, immutable per-image commit receipt, authenticated
read-only status, reserved unknowns and nonreusable tombstones.
Do **not** add the image-upload API/status endpoint until the image
scanner's transaction boundary, partial-move handling, crash
injection, same-key parallel submissions, authorization changes and
status semantics are proved. In particular, a missing row is
`unknown`, not `rejected_no_effect`, unless an independently
durable no-effect transition is established. An operation committed
in the DB but whose HTTP response was dropped must be recovered
only by **its original** operation ID, and never by blind retry or
inventing a replacement ID after local history loss.

### Mixed-version server fencing and cutover limits

A per-gallery epoch checked **only** by the delegated controller is
insufficient: `cms_upload_automation_upload()` reaches the same
service without the delegated authority callback. A future fence
must bind both legacy and delegated paths within the actual shared
gallery writer/DB ownership boundary, including the final pre-move
check, and must be supported by an explicit per-gallery compatibility
and admission policy. Flipping an epoch while a preexisting PHP worker
runs old code cannot retroactively make that worker check the new
fence. Preserving existing `pgu_` keys and already-started transfers
requires a verified drain/maintenance cutover, **not** killing a
client process, silently invalidating keys or assuming HTTP ended
because the desktop app exited. Until old-version and mixed-version
server paths are qualified with actual in-flight requests, linked
activation must remain blocked.

### Continuation 8 evidence and remaining qualification

| Tested candidate | Exact BASIC | Central Python Ubuntu | Central Python Windows | Significance |
| --- | --- | --- | --- | --- |
| `8c29a782` | [#42043625389](https://github.com/klusik/PHP_gallery/actions/runs/42043625389) PASS | 709 pass, 4 fail, 3 error, 12 skip | 710 pass, 11 fail, 5 error, 2 skip; 6 failing subtests | Two stale mode/save and dialog fixtures corrected. No runtime change. |
| `a176c6a9` | [#42044297041](https://github.com/klusik/PHP_gallery/actions/runs/42044297041) PASS | 712 pass, 2 fail, 2 error, 12 skip | 713 pass, 9 fail, 4 error, 2 skip; 6 failing subtests | Three watcher-drain fixtures now assert sealed capture and conservative uncertain-result handling. No runtime change. |
| `b1f005bd` (after `3cf7dd47`) | [#42045249937](https://github.com/klusik/PHP_gallery/actions/runs/42045249937) PASS | 713 pass, 2 fail, 2 error, 12 skip | 715 pass, 9 fail, 3 error, 2 skip; 6 failing subtests | Windows busy-owner error handling fixed; independent-PID same-root lease test passes on both OSes. |

The listed test totals are 728 before the new subprocess case and
729 afterwards. These diagnostic suites are **not** the FULL profile.
Source-only and fixture repairs must not be counted as removal of
uncertainty about admitted uploads.

**Still missing:** an independently proven physical workspace
identity and cooperative whole-RMW transaction; fail-closed treatment
of non-cooperating prior binaries across different profiles; credible
anti-rollback evidence; an image-level MariaDB operation/receipt
transaction with filesystem crash qualification; authenticated status
and immutable tombstones; and a no-interruption mixed-version server
fence. **Both activation flags stay `False`**, no new linked image
upload API is enabled, legacy `pgu_` clients continue unchanged, and
no FULL/integration is authorized.

## Continuation 9: native diagnostic qualification and blocked transaction proof

This checkpoint distinguishes corrected Windows diagnostic defects from the
still-unproven authorization of accepted upload work. It implements no
local accepted-work transaction, linked image-operation endpoint, database
migration or mixed-version fence. The ten loss and crash cases from
Continuation 7 are unchanged, including joint rollback and history loss.

### Windows evidence, corrections and limits

The original named-test JSON artifacts for the previous final
[Python run #42047294517](https://github.com/klusik/PHP_gallery/actions/runs/42047294517)
were inspected before editing the affected source. Two narrow fixes were
committed in the first [Continuation 9 candidate
`659b6c2f`](https://github.com/klusik/PHP_gallery/commit/659b6c2f35485860442b0e79d95782b51c1d0d2f):

- The installer test's `/DSourceRoot` argument now checks that exactly one
  path points to the **same existing filesystem directory**, rather than
  demanding the same textual Windows 8.3/long-name spelling.
- The AIRAC fixture closes its SQLite read connection before temporary
  directory cleanup. This did not yet fix the separate live writer handle
  in `remember_plan()`.

The exact [BASIC #42054266542](https://github.com/klusik/PHP_gallery/actions/runs/42054266542)
**PASS** for that candidate. [Python #42054266566](https://github.com/klusik/PHP_gallery/actions/runs/42054266566)
remained overall **FAIL**: Ubuntu 717 pass, 0 fail, 0 error, 12 skip;
Windows 721 pass, 5 fail, 1 error, 2 skip, plus 2 failing
subtests, all out of 729 named tests per OS. Original JSON confirmed a
**second** installer path assertion on the returned artifact, a
still-open SQLite handle from production `remember_plan()`, and
two separate update-helper path-identity failures.

The next [candidate `df6b70b3`](https://github.com/klusik/PHP_gallery/commit/df6b70b33204202fab705004a68004f6db5b8815)
therefore also checks the actual returned installer file with
`Path.samefile()`. Production `flight_navdata.remember_plan()` uses
`contextlib.closing()` **outside** the SQLite connection's existing
commit/rollback context, closing its handle on success and exceptions.
The production update-helper's owned helper-executable comparison now
resolves **both** operands, so an 8.3 alias cannot falsely reject its
own session. The registered installation authority check remains
unchanged; its test now checks the actual installed file identity
with `samefile()`, and still refuses a developer copy.

The exact [central Python #42055009849](https://github.com/klusik/PHP_gallery/actions/runs/42055009849)
is **PASS** on both runners based on the original platform JSON:
Ubuntu **717 PASS / 0 FAIL / 0 ERROR / 12 SKIP**,
Windows **727 PASS / 0 FAIL / 0 ERROR / 2 SKIP**,
**0 failing subtests** on either OS (729 total per OS). This is
platform qualification for those code paths, not for local accepted-work
serializability or upload finality. The initial [BASIC #42055009951](https://github.com/klusik/PHP_gallery/actions/runs/42055009951)
found only `winapp/tests/test_update_helper.py:164 return.missing`;
the materially changed test declaration requires an explicit
`Returns:` docstring. This documentation defect is repaired in this
checkpoint without discarding the original docstring.

### Additional local provenance and owner findings

- `gallery_watch_upload.main()` constructs
  `WindowsInstance(CONFIG_DIR)` **before** reading settings and
  running `--once`. Thus **for the same canonical profile spelling
  and a cooperating current executable**, `--once` does participate
  in the GUI Windows mutex. This is narrower than treating every CLI
  invocation as an independent writer. It does **not** qualify
  alternate `APPDATA` profiles, aliases, earlier binaries, other helper
  processes, or already-dispatched PHP requests.
- `--config` can select a configuration file through
  `ConfigStore(args.config)`, while the process mutex, state path and
  flight workspace still derive from `CONFIG_DIR`. Therefore the
  chosen config pathname does not establish a separate accepted-work
  owner, nor does the profile-name mutex certify the content or
  physical identity of an arbitrary alternate config.
- `ImportJob` and its `ImportItem` preserve source labels/paths,
  per-item size and optional SHA-256. `ImportBindings` persists only
  job ID to an exact 64-character target fingerprint in a different
  JSON file. `LinkedUploadTarget.binding()` hashes issuer, Admin ID
  and gallery ID for that in-memory target, but the local binding
  record is **not** an immutable server-enforced operation claim
  containing the original bytes, client identity and actual HTTP
  attempt. Existing per-file strict reads are useful tamper checks,
  not a two-file atomic read/modify/write.
- `JobStateStore` and watcher `merge_upload_sections()` use the
  per-process `_STATE_LOCK`; `ImportBindings.bind()/resolve()`
  use independent whole-document replacement. No fresh joint read,
  physical-root validation, single inter-process write owner or
  authoritative cross-file commit was added. A current complete
  snapshot cannot prove that both files were never jointly deleted or
  restored to an older consistent snapshot. Explicitly **BLOCK**
  target migration and automatic recovery on this missing evidence.

The next local qualification must establish opened Windows handle
identity (volume serial and File ID) and rejection/ownership rules for
UNC, symlink, junction/reparse and hardlink aliases across SIDs,
sessions, profiles, GUI and `--once`. Under an independently verified
cooperative owner, each mutator and mode-admission read must reload
**both** current documents before making a decision, and the two-file
publish/readback and crash windows must be tested with two real PIDs.
A cooperative-only improvement must still not authorize linked upload
while older writers can bypass it. No third rollbackable local JSON
is an acceptable monotonic witness.

### Additional PHP image transaction findings

- Both `cms_upload_automation_upload()` and
  `cms_app_linking_upload()` enter
  `upload_automation_store_images()`. The former intentionally
  supplies no delegated authority callback. The latter checks current
  bearer/target authority but does not supply a durable per-image
  operation ID.
- `upload_automation_with_gallery_lock()` holds the
  gallery-scoped MySQL `GET_LOCK`, and
  `store_uploaded_gallery_images()` holds the global gallery
  writer. Neither lock is the database transaction required to
  atomically publish an image row **with** an immutable operation
  receipt.
- `store_uploaded_gallery_images_owned()` moves accepted original
  files using `move_uploaded_file()`, then calls
  `scan_gallery_selected_uploaded_images()`. The latter iterates
  the selected paths; `scan_gallery_image_file_entry()` invokes
  `image_model_insert_scan_row()` or
  `image_model_update_scan_row()` per file, followed by
  `scan_gallery_refresh_after_changes()` for gallery-derived data.
  The inspected service path does not open an enclosing MariaDB
  transaction for all image rows plus a durable request receipt;
  no such image-operation receipt exists. A partial filesystem move,
  one or several indexed rows, or loss of the HTTP response are
  distinguishable only with evidence not currently persisted.
- `fresh_insert_v1` checks request-local filesystem/row identity
  and returns image IDs on a received response. Neither
  `image_client_ids[]` nor an inventory SHA-256 proves request
  causality or same-key replay suppression. A missing operation row
  must remain `unknown`, not `rejected_no_effect`.

**Server gate:** No new image API, status endpoint, schema or epoch was
introduced. Before an implementation may claim idempotence it needs a
client-persisted random 256-bit per-upload operation identity; exact
issuer/client/Admin/gallery/source+thumbnail+metadata fingerprint
verified against received bytes; a MariaDB-unique pre-effect claim and
non-reusable retention/tombstones; same-key concurrent-request tests;
an immutable result committed in the **same** transaction as the image
rows; read-only authorized status with `committed`, proven
`rejected_no_effect`, `pending` and `unknown`; plus filesystem
crash/recovery qualification. A shared-host mixed-version migration
also requires **both** legacy and linked server entry paths and
already-admitted requests to obey an explicit, proven cutover policy.
An old PHP worker which never checks the new fence cannot be fixed
retroactively. Keep functioning `pgu_` keys and in-flight transfers,
and **do not** activate either linked target flag on the basis of
local locks or passing Python tests.

**Activation remains blocked:** `LINKING_UPLOAD_TARGET_READY = False`
and `LINKING_CREATED_TARGET_READY = False`. No automatic retry,
source deletion, retargeting, legacy API change or FULL run was
performed. The sole runtime changes in this continuation are the
Windows update-helper alias comparison and the SQLite connection
lifetime correction; all other code changes are diagnostic fixtures.

## Continuation 10: implementation-first accepted-work owner and physical Windows profile identity

This phase prioritizes incremental production mechanisms over repeated CI
qualification. **New BASIC, central Python, native Windows and MariaDB runs
were explicitly deferred by the project owner.** The last qualifying BASIC
and Python reports still refer to prior `d08e4a5d`, **not** to the changes
below. No new security assertion may inherit those old results.

### Source-level changes, not yet runtime qualification

- `state_store.load_upload_state_for_mutation()` refuses to overwrite existing
  malformed, redirected, changed, truncated or oversized shared state with an
  empty snapshot. Legacy non-admission reads keep their established parser.
  At this checkpoint, mutation reads had a separate 256 MiB limit and target
  admission used 16 MiB. The current shared-history contract above supersedes
  that mismatch.
- `accepted_work_owner(config_dir)` acquires the stable in-place
  `accepted-work.lock` using native Windows byte-range locking or POSIX
  `flock`, within the process-local `_STATE_LOCK`. Every adopted RMW
  obtains its owner **before** the fresh read, holds it across publication,
  and reads back the saved state before release. This is deliberately
  different from locking only `atomic_write_json()`.
- `JobStateStore.save_job/delete_job/append_event`, watcher
  `merge_upload_sections()`, and `ImportBindings.bind/resolve` now use that
  cooperative owner. These are separate **per-file** commits, with no new
  fictitious cross-file atomicity or monotonic local witness.
- `ImportBindings.admit_job()` is now the actual manual-import admission
  path in `gallery_watch_upload.pyw`. After acquiring the shared owner,
  it reloads the job and binding ledgers, checks the exact binding and
  previously retained job/source/receipt data, publishes the binding first,
  then the job, and re-reads both before the upload worker starts. It
  refuses an orphan binding with a missing job, a previously dispatched
  job with a missing binding, changed immutable source identity, a stale
  attempt counter, and removal of confirmed results. Original source bytes
  are retained. A crash between the two independent file replacements can
  still leave an orphan requiring manual review.
- The GUI's linked-target and legacy connection-change predicates read
  the relevant persisted binding and job evidence while holding the same
  cooperative owner. The legacy connection predicate no longer deletes a
  completed binding just because it was inspected. This prevents destructive
  side effects during an admission query; it does not prove rollback-resistant
  provenance or exclude every writer/read path.
- New `windows_file_identity.WindowsDirectoryAnchor` holds an actual
  `CreateFileW(FILE_FLAG_BACKUP_SEMANTICS)` directory handle without
  `FILE_SHARE_DELETE`. It reads `GetFileInformationByHandleEx(FileIdInfo)`
  to retain the complete volume serial and **128-bit File ID**, and
  reopens the named directory to compare against the pinned identity.
  `WindowsInstance` preserves its legacy path-named mutex and adds a
  physical-ID-derived `Global\\...` mutex and activation event to refuse
  cooperating alias launches. The accepted-work RMW owner also holds a
  Windows directory anchor for its full lifetime and checks identity at
  both sides of the critical section.
- Microsoft documents `FILE_ID_INFO` as a volume serial plus 128-bit File
  ID, suitable for comparing opened objects. Documentation:
  <https://learn.microsoft.com/en-us/windows/win32/api/winbase/ns-winbase-file_id_info>.
  Microsoft also documents that a directory handle is opened with
  `FILE_FLAG_BACKUP_SEMANTICS`:
  <https://learn.microsoft.com/en-us/windows/win32/fileio/obtaining-a-handle-to-a-directory>.
  The 64-bit older file index is insufficient for ReFS, and some filesystems
  or remote servers may not support the requested information. An unavailable
  native identity fails closed in these new ownership paths.

### Explicit proof limitations and remaining blockers

These modifications establish a *cooperative source-level ownership protocol*
for the adopted writers, not a fully proven upload admission protocol.
Real independent-PID Windows tests are still required for 8.3 aliases,
junctions/reparse points, symlinks, UNC, alternate profiles, distinct SIDs,
cross-session mutex ACLs, lock-file substitution, open-handle stability and
post-crash recovery. Native directory identity is checked before and after
the work, but path-based file mutations and intermediate ancestor switches
still require qualification. Existing binaries may ignore the new lock.
Missing history, or joint rollback of both ledgers to a self-consistent
earlier version, **cannot** be distinguished from history that never existed.

There is no shared MariaDB transaction for image rows plus immutable image
operation receipt, no server-enforced per-image 256-bit operation identity,
no immutable per-file byte fingerprint and authenticated status endpoint,
no qualified filesystem partial-move compensation or exact-key
post-commit recovery, and no mixed-version PHP gallery epoch fence.
The legacy `pgu_` controller remains unchanged. Do not treat
`fresh_insert_v1`, `image_client_ids[]` or inventory SHA-256 as operation
identity or server finality. In particular, no local mutex can stop an
already-running old PHP worker, and a missing operation row is not proof
of `rejected_no_effect`. No new upload API or migration was added.

**Both activation constants remain `False`** in
`winapp/uploader/app_linking_ui.py`:
`LINKING_UPLOAD_TARGET_READY = False` and
`LINKING_CREATED_TARGET_READY = False`. No automatic ambiguous-upload
retry, original-source deletion, silent retargeting, `develop` merge,
rebase, squash, force push or FULL test run was performed. The deferred
qualification must evaluate the new code from its **exact future SHA**,
including crash injection on both local ledger publications, same-ID
parallel PHP requests, transaction/rollback, filesystem partial effects,
response loss, mixed-version cutover and all ten rollback/provenance
scenarios from Continuation 7.

## Continuation 11: monotone accepted-work RMW, source preservation and mixed-version fencing design

**Implementation-first checkpoint (2026-11-05).** This continuation starts
from `d5862f86` and retains every previous commit on
`feature/345-browser-approved-linking`. The owner explicitly deferred BASIC,
central Python, FULL, native Windows and MariaDB qualification. **No tests
were run as part of Continuation 11.** Historical green runs for
`d08e4a5d` do not certify the changes listed here.

### New source-level safeguards

1. `JobStateStore.save_job()` now calls
   `validate_import_job_update()` **after** acquiring
   `accepted_work_owner()` and loading the fresh state. Existing job IDs
   retain source kind, labels, original/staged source paths, archive policy,
   item count/order, per-item source identity and SHA-256, nondecreasing
   attempt counts and confirmed byte counts, previous server-result objects,
   terminal confirmation/duplicate states and previously recorded
   uncertain/unconfirmed reasons. An existing started job cannot be replaced
   with an unstarted snapshot. This is **a cooperative stale-writer check**,
   not an HTTP receipt or proof of monotonically complete historical files.
2. `ImportBindings.admit_job()` uses that same job-regression guard before
   its original admission-specific checks and binding-first/job-second
   publication. `ImportBindings.bind()` independently reloads **both**
   job and binding ledgers under one owner, refuses a missing job or
   a newly invented binding for previously dispatched work and verifies both
   snapshots after writing. `ImportBindings.resolve()` refuses to erase a
   binding for a missing, unfinished or uncertain job and verifies that the
   completed job survived the removal. The owner cannot retroactively
   reconstruct work performed by old noncooperating binaries.
3. The legacy API-key connection edit checks its **proposed** target
   fingerprint against all accepted jobs and bindings using the strict
   owner-protected reader. This includes orphan, damaged and
   differently-bound unfinished imports, instead of using tolerant
   `get_job()` on a filtered subset. Explicit retry review likewise obtains
   one strict fresh job/binding snapshot under the common owner; it does not
   use an in-memory GUI job if durable history has disappeared. The final
   worker admission still rechecks both ledgers, because this UI read alone
   is not a lifetime transaction.
4. `accepted_work_owner()` pins the Windows `flights/` directory as well
   as the profile via `WindowsDirectoryAnchor` during each protected
   read/modify/write. A directly reparse-pointed `flights/` child is
   refused, since two different profile owners could otherwise mutate one
   shared imported-workspace ledger. Both native identities are rechecked
   before work and before releasing the owner. The existing path-based
   instance mutex and independent physical FileIdInfo mutex remain intact.
   This does **not** make pathname-based file writes handle-relative or
   protect arbitrary ancestor replacement in the middle of an operation.
5. Automatic age-based ZIP staging cleanup at GUI startup is **deferred**.
   Age and a tolerant currently-visible job list cannot justify deleting
   historical accepted sources if the local ledger was lost or rolled back.
   Explicit clear/rebuild now stop and wait for the previous preflight,
   then use `ImportBindings.discard_unbound_preflight()` to retire only a
   fresh strictly verified, undispatched and **unbound** preflight row.
   Neither GUI action removes staged media. `JobStateStore.delete_job()`
   refuses unilateral deletion of an existing job; the two-ledger method is
   the bounded preflight-retirement path. `stage_zip_archive()` refuses
   to overwrite an existing per-job directory on ID reuse.
   These changes favor retention/disk usage over destructive guessing.
6. `ManualUploadThread.finalize_job()` now keeps ZIP staging originals
   **even after all import items appear locally confirmed**. Previously it
   called `shutil.rmtree()` for the staging directory when the job looked
   successful. A local completion snapshot, including duplicate results,
   does not prove an immutable per-image server operation receipt. The
   original ZIP also remains retained. Operators must manage disk usage
   manually until qualified cleanup and provenance ownership exist.
7. `WorkspaceLease` additionally pins the opened Windows flight-workspace
   directory for the **entire** flight-workflow lease, not just an accepted
   JSON RMW, and checks both the persistent `owner.lock` file identity and
   the pinned directory before reading or writing the lock sentinel and
   after acquiring native exclusion. A directly reparse-pointed workspace
   or redirected/hardlinked lock file is refused. The POSIX lease also
   checks file identity around its existing `flock`. This is not a
   server fence and still needs independent Windows/UNC/session qualification.

### Cross-file crash boundary and impossible local proofs

The accepted import path still performs **two separate atomic replacements**:
first `flights/import-bindings.json`, then `upload_state.json`. The shared
cooperative owner spans both fresh reads, both publications and their
readbacks, but it cannot make those files a single atomic commit:

| Interruption/evidence | Required outcome |
| --- | --- |
| Crash before any accepted binding is published | Existing preflight is not a certificate that a request never happened under an older executable; no automatic resend or retarget |
| Binding published but job not published | Surviving orphan refuses admission, deletion, migration and implicit recovery |
| Both published but readback or process exit is lost | Current pair can be inspected under the owner; **HTTP outcome remains unknown** without server operation evidence |
| One ledger deleted/corrupt/redirected | Strict admission fails closed; do not recreate missing accepted history as empty |
| Both ledgers absent or jointly restored to an older consistent snapshot | No rollback-resistant local witness exists; never infer that accepted work never existed |
| Older writer bypasses owner or legacy PHP request is in flight | No new cooperative exclusivity or DB absence inference applies |

A third independently replaceable JSON, lock-file sentinel, local sequence
counter or client SHA-256 would be **equally rollbackable** and is not an
accepted monotone proof. The added guards prevent certain newly introduced
stale writes and unsafe automatic source deletions; they do **not** close
the joint-rollback, arbitrary ancestor-swap, lost-HTTP-response or
mixed-version cases. The feature admission remains **BLOCKED**.

### Windows physical ownership qualification still required

`WindowsDirectoryAnchor` uses an open Windows directory handle plus
`GetFileInformationByHandleEx(FileIdInfo)` (64-bit volume serial and
128-bit File ID) and excludes `FILE_SHARE_DELETE` for the target directory.
This rejects several forms of direct directory replacement and preserves
a concrete identity across a cooperating transaction. It does not prove
that every intermediate ancestor resolves identically for the entire
mutation, that an unsupported UNC/remote filesystem has stable IDs,
or that all versions/processes share the same security principal and
kernel-object ACL. Real native exercises must cover Windows 8.3/long path
aliases, junction and symlink chains, hardlinks to lock files, UNC shares,
replacement races, alternate profiles, Windows sessions and distinct SIDs.
If FileIdInfo, locking or directory identity cannot be established,
new admission must fail closed, not silently revert to path-only evidence.

### PHP per-image operation design: source-justified, not implemented

The existing legacy `cms_upload_automation_upload()` and linked
`cms_app_linking_upload()` still converge on
`upload_automation_store_images()`. The gallery advisory `GET_LOCK` and
the existing global gallery writer serialize cooperating code paths;
they do **not** commit a request receipt and image row as one MariaDB
transaction. `store_uploaded_gallery_images_owned()` moves original files
before scanner row registration, and the selected scanner updates rows
individually before refreshing derived gallery state. Therefore neither
the old scanner nor an added receipt table, by itself, can prove exactly
once upload effects after a PHP crash or response loss.

The proposed operation contract, **strictly not exposed as an API yet**, is:

1. One random 256-bit `operation_id` per image intent **before** first
   dispatch, durably associated with one installation issuer, stable client
   identity, approving account, target gallery and ordered original/thumbnail
   manifest. Token refresh cannot change this identity. Loss of the local
   operation key prevents automatic retry.
2. Server recomputation of a versioned canonical fingerprint from actual
   bounded uploaded temporary bytes, filenames, sizes, thumbnail order and
   policy, and approved metadata. A client-inventory SHA-256 or
   `image_client_ids[]` never substitutes for server validation.
3. A retained pre-effect MariaDB claim with a unique key on
   `(issuer, client_identity, operation_id_digest)`, bound immutably to
   actor/gallery/manifest and a non-reusable tombstone policy. Concurrent
   same-ID requests serialize on the unique claim; conflicting owner/content
   returns a non-disclosing conflict; pending/unknown never starts a second
   multipart execution.
4. A demonstrably single **MariaDB transaction** registering the selected
   image row(s) and immutable operation receipt, under existing gallery/global
   writer ordering. Filesystem moves and derived thumbnails require separate
   crash-safe identity/recovery design: MariaDB cannot roll back
   `move_uploaded_file()`, orphan files, renames or thumbnail side effects.
   A pre-effect claim with no committed receipt remains pending/unknown
   until reconciled by a qualified server procedure.
5. An authenticated read-only per-operation status after live scope, actor,
   grant and gallery checks. Only independently established server outcomes
   can distinguish committed, pending, conflict or an actually proven
   no-effect rejection. Missing claim or missing row is **unknown**, never
   proof of `rejected_no_effect`. Reconciliation must not attribute an
   inventory match to a particular lost attempt.

Before schema migration/endpoint code, inspect and refactor the scanner's
transaction ownership and all row writes, assess global/gallery lock and DB
connection semantics, define exact filesystem partial-effect recovery,
non-reusable retention/tombstones and failure-injection contracts. No new
public image claim/status route or migration was added in this continuation.

### Mixed-version PHP and legacy `pgu_` fencing

An epoch checked only by new linked requests **cannot retroactively stop**
an already executing old PHP worker. Existing requests must not be killed
or falsely treated as finished on client disconnect. On shared hosting,
new code may coexist with long-running old request code across a deployment.
An old `pgu_` client and the old controller must remain usable with their
existing gallery-scoped privileges and without forced credential migration.

Consequently, any future cutover needs a source-proven common ordering
point honored by **both** versions, including legacy in-flight workers.
The currently shared gallery and global writer mechanisms are candidates
to be preserved and examined, but locking alone is not the required
image-row/receipt transaction, and old code cannot observe a newly
introduced epoch or per-image claim. An additive new-key protocol may
provide operation-specific receipts *only* for requests that entered its
new authoritative claim path; it must not label concurrent or historical
legacy uploads idempotent. A deployment barrier that only waits for new
workers, changes a configuration epoch or infers quiescence from a restarted
client is insufficient. An actually enforceable drain/legacy-ingress plan
must not interrupt old requests or silently remove the `pgu_` workflow.

### Qualification and activation state

Every Continuation 11 change is **implementation only, untested at its new
SHA**. No BASIC, central Python diagnostics, FULL, native cross-process
Windows, two-JSON crash injection, MariaDB transaction or mixed-version PHP
test was deliberately triggered. Required future qualification includes
the exact committed SHA, old/new watcher processes, alias and directory
replacement races, orphan and rollback histories, GUI retry admission,
same-ID PHP concurrency, move/index crash points, response loss and
deployment overlap.

`LINKING_UPLOAD_TARGET_READY = False` and
`LINKING_CREATED_TARGET_READY = False` remain unchanged. There is
no server-enforced per-image claim/receipt/status, no source deletion
replay rule, no automatic ambiguous-upload retry, no silent target/account
switch and no change to the legacy `pgu_` controller or endpoint.


## Continuation 12: immutable local provenance, retained sources and scoped-history RMW

**Implementation-first checkpoint (2026-11-05).** Work continued from
`07f4910188df4731d95b1e4aabfb6b02dd2fefe3` on
`feature/345-browser-approved-linking`. The owner explicitly deferred
BASIC, central Python, FULL, Windows-native, two-ledger crash-injection and
MariaDB qualification. **No tests were run for any Continuation 12 commit.**
Earlier green BASIC #42056240475 and Python #42056240275 concern only
`d08e4a5d`, not the new branch head.

### Production changes implemented in this continuation

1. `0ff424fa`, `winapp/uploader/state_store.py`:
   `validate_import_job_update()` now preserves the original nonzero
   `started_at`, instead of merely checking that it remains nonzero.
   Explicit retry may still clear `finished_at`, but may not fabricate a
   different first dispatch timestamp. `admitted_imports_match_target()`
   refuses a missing binding for **any** retained job with dispatch,
   attempt, terminal, receipt, byte-progress or uncertain-reason evidence,
   including locally completed imports. Unfinished jobs continue to demand
   the exact proposed target binding.
2. `43c3828b`, `winapp/uploader/flight_jobs.py`:
   `ImportBindings.resolve()` still obtains the shared accepted-work owner,
   reads both current ledgers, checks terminal confirmation and rechecks
   retention, but no longer removes the completed job's binding. This
   preserves the original non-secret gallery/key fingerprint for future
   read-only provenance checks. Local completion is **not** a final
   server-side image receipt, and a retained binding is rollbackable.
3. `da7001c2`, `flight_jobs.py`:
   `ImportBindings.admit_job()` requires an existing durable preflight
   row in the freshly read job ledger, rather than using an in-memory
   `ImportJob` to fabricate a new accepted row. Preflight-only jobs may
   still obtain their initial binding after their explicit state save.
4. `580c66d8`, `state_store.py`:
   `JobStateStore.save_job()` refuses to recreate an absent previously
   dispatched job from a candidate that already contains started/finished
   timestamps, confirmed bytes, attempts, server results or dispatch
   states. The fresh never-dispatched preflight creation path is preserved.
   These checks cannot prove that a completely missing historical ledger
   never existed; the activation gates therefore remain closed.
5. `4deb1c29`, `winapp/uploader/discovery.py`:
   all implicit `shutil.rmtree()` calls previously used for rejected,
   empty or over-limit ZIP staging directories were removed. Warnings
   identify retained staging paths. The formerly age-based
   `cleanup_stale_staging()` entry point is now a deliberate no-op.
   File age and currently listed job IDs cannot establish that extracted
   bytes were never accepted by another process or a vanished history.
6. `5afa5db4`, `winapp/gallery_watch_upload.pyw`:
   both cancelled `ImportPreflightThread` paths now retain ZIP staging
   sources rather than deleting them before or during hashing. Temporary
   generated thumbnail workspaces are separate; this change does not
   make their ephemeral scratch cleanup a source-recovery proof.
7. `374a71fd` and `3516666f`, `state_store.py`:
   on Windows the direct profile must not be a reparse point when first
   acquiring `accepted_work_owner()`. Its direct profile path is also
   checked using `lstat()` at existing owner-boundary rechecks, alongside
   the already pinned `flights/` directory and the native FileIdInfo
   identity. A direct redirect is refused. Arbitrary *ancestor* swaps
   are **not** made impossible by this check.
8. `4066ad95`, `winapp/gallery_watch_upload.pyw`:
   `LinkedUploadState.load()` now obtains `accepted_work_owner()` and
   captures an independent deep-copy baseline of the strict target-scoped
   recovery history. `save()` reopens the current history **after**
   obtaining the owner, compares it against that baseline, rejects a
   changed or disappeared record, publishes its replacement, performs a
   strict readback and advances its baseline only after verification.
   This removes a specific stale cooperating full-file overwrite path
   that could otherwise lose newer linked uncertain markers or
   acknowledgements. It is still an independent JSON replacement and
   is not a server operation journal or rollback-resistant evidence.
9. `bc6a08e4`, `state_store.py`:
   `linked_upload_state_path()` now refuses direct Windows reparse
   attributes, including junction-like paths not necessarily recognized
   by `Path.is_symlink()`, for the profile, linked-state directory and
   target-scoped JSON. This supplements, but does not replace, the
   native owner and physical ancestor-chain qualification.

10. `e88bf4c2`, `winapp/uploader/discovery.py`:
    ZIP extraction now uses exclusive `destination.open("xb")` rather
    than truncating an existing staged filename. Failure no longer unlinks
    a potentially redirected or concurrently replaced partial extraction.
    The direct staging root also rejects Windows junction/reparse attributes.
    These are additional refusal/retention guards, **not** a qualification
    of remote roots, replaced ancestors or arbitrary cross-process path swaps.

These eleven production commits are all ordinary fast-forward feature-branch updates.
No upstream history rewrite, new API route, schema migration, legacy
`pgu_` modification, unrelated refactoring or activation change was
requested or introduced.

### Local RMW, crash and rollback qualification boundaries

`flights/import-bindings.json` and `upload_state.json` still use **two
distinct atomic replace operations**, normally binding first and job
second. A shared cooperative OS owner covers their current reads and
readbacks but cannot make the pair one commit. The target-scoped
`linked-upload-state/<binding>.json` is yet another separately replaceable
recovery file; its new compare-before-save guard avoids some stale updates,
but it is explicitly **not** a proposed third monotonic ledger.

| Failure | Safe outcome and remaining gap |
| --- | --- |
| Process dies before the first binding publication | Do not use an absent binding to infer no accepted work ever existed; an old process or rolled-back history remains possible. |
| Process dies after the binding replace but before job replace | Surviving orphan binding is an admission blocker and must be kept for review. |
| Process dies after both replaces but before readback | Later strict inspection can see the surviving pair; the HTTP result is still unknown absent server operation evidence. |
| Either document is malformed, redirected, missing after prior observation or incompatible | Refuse destructive repair, retargeting, implicit retry and cleanup. |
| Both documents disappear or are rolled back together | Neither the current pair, a lock-file sentinel, an added rollbackable JSON nor a local inventory SHA proves prior work never happened. |
| Older noncooperating process writes or removes state | No current cooperative owner can retroactively fence its operation. |

**Source preservation remains intentionally one-way:** neither a
locally terminal job, SHA-256 match, unavailable image row nor a cancelled
preflight authorizes deletion of original or staged source media. Former
staging cleanup call sites are disabled; retained directories consume
disk space and require explicit operator review. The client does not
automatically retry an uncertain HTTP outcome or silently switch gallery,
account or key. A positive remote image-operation receipt protocol is
still missing.

### Windows physical ownership, not a blanket mutex guarantee

The existing path-based Windows instance mutex, physical profile FileIdInfo
mutex and open native Windows profile/`flights/` directory anchors are
**cooperative** components. The additional direct reparse checks refuse
some junction/symlink redirections. They have not been independently
validated across long versus 8.3 aliases, arbitrary ancestor replacement,
hardlinks, UNC/remote filesystems, Windows sessions, different user SIDs,
global mutex ACLs, separated profiles sharing `flights/`, or older
executables that ignore the new locking protocol. A path reopening or a
cooperatively held mutex is not a handle-relative write or an enforced
filesystem transaction. Failure to establish identity must not silently
permit linked target admission.

### PHP source audit and unimplemented per-image operation protocol

The concrete server path remains
`app/services/uploads.php::store_uploaded_gallery_images_owned()`.
It moves each uploaded temporary original via `move_uploaded_file()`
**before** calling
`app/services/image_scanning.php::scan_gallery_selected_uploaded_images()`.
That scanner loops over files, individually invoking
`scan_gallery_image_file_entry()` and its image-row
insert/update operations, then refreshes gallery-level derived state,
paths and covers. The gallery writer uses the existing
`gallery_edit_writer_begin()` / `gallery_edit_model_lock()` exclusion.
This does **not** demonstrate an enclosing MariaDB transaction for image
rows **and** an immutable operation receipt. Existing
`delegated_receipts` carry filesystem identity, not the desired
idempotent operation outcome.

The required **future** server protocol remains design-only:
- Random 256-bit per-image `operation_id`, fixed at first dispatch and
  durably bound to server issuer, stable client identity, account, grant,
  gallery and immutable requested image/derivative manifest;
- bounded server recomputation of a canonical versioned digest from
  actual original/thumbnail bytes and validated metadata, rather than
  trusting local inventory or `image_client_ids[]`;
- pre-effect retained MariaDB unique-key claim with conflict isolation
  and non-reusable tombstone for every operation key;
- image row(s) and immutable success receipt committed in **one** proven
  MariaDB transaction, with separate compensating/recovery state for
  filesystem moves, temporary uploads, renames and thumbnails;
- authenticated, read-only operation status checked against the live
  issuer/account/grant/gallery authority and able to report committed,
  pending, conflict and **only independently proved** no-effect.
  A missing claim or indexed image row remains **unknown**, never an
  authorization to resend the same ambiguous image.

No claim schema, public status/claim endpoint, immutable receipt, or
filesystem partial-move recovery algorithm was implemented. A plain
gallery `GET_LOCK` is not the required receipt transaction.

### Mixed-version deployment and activation blockers

The legacy `pgu_` endpoint and gallery-scoped API keys remain intact.
New workers cannot retroactively impose a new epoch on PHP requests that
entered old code before deployment. Any future migration must inspect a
**common ordering point** honored by already-running legacy and new
writers without interrupting those requests. The current gallery/global
writer mechanisms can be examined as candidates, but they do not create
new per-image receipts for historic legacy requests and do not alone
prove a clean cutover or a mixed-version quiescence barrier.

At the Continuation 12 checkpoint, all of the following remain BLOCKED:
joint local rollback/loss, Windows alias/UNC/ancestor/SID/session and
noncooperating-binary coverage, PHP image row-plus-receipt atomic commit,
durable pre-effect unique claim, exact-operation reconciliation after
filesystem partial effects, authenticated status and safe idempotent retry,
and mixed-version PHP cutover. The feature remains fail closed.

**Activation flags remain unchanged:**

- `LINKING_UPLOAD_TARGET_READY = False`
- `LINKING_CREATED_TARGET_READY = False`

**Qualification policy:** implementation-only. No BASIC, central Python,
FULL, Windows-native, MariaDB, mixed-version integration or crash
injection was run for any new Continuation 12 SHA. Future qualification
must use the exact eventual HEAD and requires the owner's explicit
instruction before a test run.

## Continuation 13: cooperating-history refusal, exact upload provenance and remaining transaction blockers

**Implementation-first source checkpoint (2026-11-05).** The continuation
started from `0e2470fcfe5a7adde1ea69b2301267e77969af79`, which was
165 commits ahead / 0 behind `develop` at merge base
`df36afa1d37e55096242e53cd857a47ba7da1d3a`. The branch history
was preserved through ordinary fast-forward remote commits. The owner
explicitly deferred BASIC, central Python, FULL, Windows-native, crash
injection, PHP/MariaDB and mixed-version integration testing.
**No Continuation 13 implementation has been tested at its new SHA.**
Previous green runs #42056240475 and #42056240275 belong only to
`d08e4a5d`.

### Changes implemented, with deliberately narrow guarantees

1. `49bee91f5b53a9807a3b42d36ef17069e14e187d`:
   `winapp/uploader/state_store.py::merge_upload_sections()` now
   requires a complete watcher-owned baseline and verifies it against a
   freshly loaded `upload_state.json` after acquiring
   `accepted_work_owner()`. The entire resulting document is read back.
   `winapp/gallery_watch_upload.pyw::UploadState.load()` captures this
   baseline under the owner using a strict read, and `save()` advances
   an independent deep-copy baseline only after a confirmed publication.
   A stale **cooperating** legacy watcher may not silently replace
   another writer's `uploaded_paths`, `uploaded_hashes` or `failures`
   sections. Unrelated `jobs` and `recent_events` mutations are retained.
   A conflicting writer now fails closed and needs explicit recovery.
2. `ca11b8028c279b3ebb559235755368f1c0436133`:
   `LinkedUploadState` now obtains its initial preexistence observation
   under the shared owner; the previously separate pre-lock `is_file()`
   observation was removed. Its strict document reader bounds the bytes
   to 16 MiB and compares `lstat()`, open-handle `fstat()`, second
   `fstat()` and final `lstat()` file identities, rejecting hardlinked,
   non-regular, replaced or oversize history instead of inferring emptiness.
   `recovery_marker()` now reads and digests the uncertain marker under
   the accepted-work owner. This does not pin every path ancestor, prevent
   all noncooperating writes, or prove history predating the first read.
3. `e5ad31bc390337c3e0bc294d2343e6dd53c30440`:
   a shared `read_import_binding_entries()` strictly parses the binding
   ledger for both `ImportBindings.entries()` and
   `JobStateStore.save_job()`. A previously absent job ID cannot be
   created as a fresh preflight while a claim for that ID survives in
   `flights/import-bindings.json`, because both reads occur after the
   accepted-work owner is acquired. This closes the concrete orphan
   collision following a crash after binding publication. It cannot
   detect a vanished or jointly rolled-back claim.
4. `5b744f8c5eb8fec472cac95315a792ec062b33e4`:
   `ManualUploadThread.upload_one()` no longer marks a pre-dispatch
   inventory-match recovery as an uploaded `confirmed` result with
   sent-byte progress. This branch now records `skipped_duplicate` and
   states that no HTTP upload was dispatched by that attempt. The
   separately handled post-dispatch legacy/linked exceptions still
   fail-stop as uncertain and do not automatically resend.

These are **source-code safeguards, not qualification evidence**. No
existing comments/docstrings were removed, no new dependency, daemon,
third-party framework or external service was introduced, and no public
PHP endpoint, migration, operation claim or receipt was added. The
legacy `pgu_` API and explicitly chosen legacy connection remain intact.

### Local ledger crash, corruption and rollback matrix

The two security-relevant local documents remain:
`flights/import-bindings.json` and `upload_state.json`.
Binding-first and job-second are separate complete-file replacements,
not one atomic transaction. The linked target's separate
`linked-upload-state/<binding>.json` is another **rollbackable**
file, never a proposed monotonic third authority.

| Interruption or conflicting history | Implemented decision and outstanding proof |
| --- | --- |
| Crash before the first publication | No durable pair can be inferred from the current attempt; a prior missing history or old writer still cannot be ruled out. Do not infer no accepted work. |
| Crash after binding replace, before job replace | Surviving orphan binding blocks fresh ID reuse and target admission. Never regenerate its missing job as an unstarted preflight. |
| Crash after both replaces, before readback | A later strict owned inspection can check the surviving pair; HTTP finality and actual filesystem durability are still unproved. |
| Binding corrupt/malformed, redirected, hardlinked or unavailable | Strict reader rejects admission and mutation. No silent quarantine-and-reset on accepted-work paths. |
| Job ledger corrupt/malformed, redirected or oversized | Mutation and target-admission readers refuse replacement; a tolerant legacy display read is not accepted as mutation authority. |
| Only one ledger disappears | If surviving binding references missing job, the new guard refuses collision and admission; other missing-history shapes remain fail-closed only as far as surviving evidence allows. |
| Both ledgers absent or collectively rolled back | There is no rollback-resistant external witness. The application **cannot distinguish** never-created history from history that disappeared. Activation remains blocked. |
| Older/noncooperating process overwrites a document | Current cooperative owner and baseline do not fence it; its publication can invalidate assumptions before or after a checkpoint. |
| Linked journal deleted, replaced, hardlinked or concurrently changed | After observation, disappearance and individual stale replacement are refused; joint rollback or path/ancestor swaps outside observed identity checkpoints remain blockers. |

A third JSON, sentinel byte, local counter, file hash or inventory match
would be subject to the same loss or rollback. None is proof that a lost
upload never reached PHP.

### Windows filesystem ownership: partial identity, not a path fence

`WindowsDirectoryAnchor` opens the chosen directory with
`CreateFileW(FILE_READ_ATTRIBUTES, FILE_SHARE_READ | FILE_SHARE_WRITE,
FILE_FLAG_BACKUP_SEMANTICS)`, intentionally omitting
`FILE_SHARE_DELETE`. It reads native `FileIdInfo` (volume serial plus
128-bit File ID) and `verify()` reopens the textual path to compare.
`accepted_work_owner()` holds native profile and `flights/` handles
through cooperating ledger RMW; the Windows profile path and direct
workspace entry reject observed reparse points. The linked recovery
file additionally receives strict `lstat/fstat` observations.

**This is not handle-relative JSON publication**. A retained target
directory handle inhibits ordinary direct rename/delete while open but
does not, by itself, ensure that every intermediate ancestor resolves
to the same object during every later pathname-based open/replace.
A check before/after an operation also does not eliminate all races
between that check and filesystem effects. Support for 8.3 aliases,
long names, junction/symlink chains, hardlinks to different lock
entries, UNC/remote filesystems, multi-profile shared workspaces,
different SIDs, session boundaries, mutex object names/ACLs, and
previously launched binaries remains unqualified. FileIdInfo and
cooperative locking must not be described as a universal filesystem
fence. Unsupported or unverifiable identity must block new linked
upload admission.

### PHP per-image idempotence: source findings and required server protocol

The actual linked and legacy controllers continue through
`upload_automation_store_images()` into
`store_uploaded_gallery_images()`. In
`app/services/uploads.php::store_uploaded_gallery_images_owned()`,
`move_uploaded_file()` moves originals **before**
`scan_gallery_selected_uploaded_images()` performs registration.
`app/services/image_scanning.php::scan_gallery_selected_uploaded_images_owned()`
iterates selected originals and calls
`scan_gallery_image_file_entry()`, then refreshes gallery-derived
data. `app/models/images.php::image_model_insert_scan_row()` executes
individual `INSERT INTO images` operations through `db()` and returns
the last insert ID. Existing delegated `fresh_insert_v1` filesystem
proofs and `image_client_ids[]` mappings are not immutable server
operation receipts. The source does not show an encompassing MariaDB
transaction that atomically commits selected image rows and an immutable
per-image operation receipt.

The current global writer ordering point is
`gallery_edit_writer_begin()` /
`gallery_edit_model_lock()`, using a database-scoped
connection-owned `GET_LOCK`. It excludes supported competing writers
but does not make filesystem moves transactional and does not create a
durable operation receipt. The experimental
`GalleryEditFilesystemFence` is disabled, non-integrated and unqualified
for cross-host/shared-filesystem behavior.

**Design only, not implemented:**

1. One server-domain-separated random 256-bit `operation_id` per image,
   persisted by the client before first dispatch. Bind it immutably to
   installation issuer, stable client identity, approving account/grant,
   gallery and a versioned canonical manifest. No operation ID reuse
   after tombstoning, token refresh, or a lost response.
2. Recalculate a bounded canonical manifest on the server from verified
   uploaded temporary originals, expected derivative order, metadata
   policy and source bytes. Client-supplied SHA-256, filenames,
   `image_client_ids[]` or an inventory match are not authoritative.
3. Before a filesystem/image side effect, durably reserve a MariaDB
   unique claim scoped to issuer/client/operation digest, and retain
   binding fields and non-reusable tombstones. Same-key conflicting
   owner/manifest requests must not execute a second upload. A pending
   claim remains pending until qualified recovery rather than being
   silently reset after a timeout.
4. Establish exact PDO transaction ownership and InnoDB engine/schema
   guarantees. The corresponding image row(s) and immutable committed
   operation receipt must be inserted in one **proven DB transaction**,
   while the gallery/global writer ordering covers that transaction.
   A unique index and `GET_LOCK` alone do not prove this. Ensure nested
   image/thumbnail/derived-state writes do not independently commit or
   invalidate the single receipt's atomicity.
5. Independently model pre-commit and post-commit filesystem states:
   moved but unindexed originals, partial moves, orphan files, duplicate
   target names, rename collisions, lost PHP worker/response and stale
   thumbnails. Use stable operation-owned filesystem identities and
   conservative reconciliation, not filename-only overwrite/delete.
   MariaDB rollback cannot undo an earlier `move_uploaded_file()`.
6. Only after the above exists, add authenticated read-only per-operation
   status under **live** issuer/account/grant/gallery authorization,
   distinguishing committed, pending, conflict, and independently
   established no-effect. Missing claim, missing row or gallery inventory
   match remains **unknown**. No new public route or schema has been
   approved in this continuation.

### Mixed-version PHP deployment and legacy compatibility

An already-executing pre-deployment PHP worker cannot honor a new epoch
retroactively. A new-code-only epoch, client process stop, or local
lockfile does not drain such workers. Shared `GET_LOCK` ordering may
serialize some old/new image writes if both code paths demonstrably
acquired it on the same DB instance, but old workers cannot issue
new per-image claims or receipts. It does not prove a complete
mixed-version cutover, database transaction, or an old request's
no-effect state. A deployment protocol must establish actual old-worker
drain/overlap guarantees **without killing requests** and must preserve
the existing gallery-scoped `pgu_` endpoints and credentials. This
remains **BLOCKED**, not implemented or runtime-qualified.

### Source retention, activation and qualification

Manual import source originals and ZIP staging are retained; no
age-only staging cleanup, cancellation cleanup, terminal-job cleanup
or SHA/inventory-driven automatic source deletion has been restored.
Thumbnail-only scratch directories remain a separate temporary-artifact
policy and are not evidence that accepted source media may be removed.
A dropped or uncertain HTTP response has no automatic same-operation
resend and does not silently retarget another gallery, account or key.

The explicit activation gates remain:

- `LINKING_UPLOAD_TARGET_READY = False`
- `LINKING_CREATED_TARGET_READY = False`

**Unresolved activation blockers:** jointly absent/rolled-back accepted
ledgers, incomplete Windows alias/UNC/SID/session/ancestor topology,
noncooperating writers, absence of a server pre-effect per-image claim
and row-plus-immutable-receipt transaction, unreconciled partial
filesystem effects and unknown HTTP outcomes, missing authenticated
operation status, and unproven mixed-version PHP deployment fencing.
These are intentionally preserved blockers, not declared complete.

**Tests are explicitly deferred.** No BASIC, central Python diagnostic,
FULL, Windows-native cross-PID, crash-loss injection, PHP/MariaDB,
end-to-end or mixed-version qualification was run for Continuation 13.
All code at the eventual continuation head must be treated as untested
until the owner explicitly authorizes tests at that exact SHA.
