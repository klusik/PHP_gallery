# Cooperative galleries

The transport-independent domain is implemented in `app/services/cooperative_galleries.php`.
It is dormant: loading services does not probe schema, generate keys, send HTTP requests,
enable a feature, or grant a visitor access. The separate pairing module now exposes
opt-in server friendship endpoints and an Admin page with an in-place drawer interface.
The public-album workflow now includes explicit proposal review, email invitations,
bounded direct verification, source photo catalogs, revocable derivative links and a
shared gallery page. Expansion deliberately creates a new, independently approved
group containing the unchanged existing members plus one new member. It does not
replace the old group or silently inherit its approvals.

## Administrator workflow

1. Enable **Cooperative galleries** in Features on each installation and apply the
   two existing cooperative migrations through the normal migration workflow.
2. In **Settings → Advanced → Friendly galleries**, pair all participating servers.
   Each pair owns separate hidden directed system credentials. A–B and B–C do not
   imply A–C. Real pairing requires publicly reachable HTTPS origins.
3. Open **Album collaborations**, select a public, listed, password-free, non-NSFW
   album, and prepare its reference. The checkbox explicitly includes thumbnails
   and previews; unchecked references retain metadata-only permissions.
4. One participant combines their album with the other reference codes. Everyone
   reviews the immutable member list and scopes and explicitly approves locally.
   **Deliver and verify** runs a finite sequence in the existing panel, stopping
   for consent, a missing friendship, an unavailable peer or a conflict.
5. The coordinator may enter each participant's email and use **Email invitation**.
   Delivery uses the existing Account email configuration (including its enabled
   setting and SMTP/PHP-mail transport). The proposal is delivered before the email.
   The link opens that participant's own authenticated review page. Opening it is
   not consent; repeated GETs never approve anything. Validity ends at the original
   proposal deadline, one day after creation, not one day after every resend.
6. After initial activation, **Open shared photographs**, or the **Shared trip** link on the local album, opens
   the shared page. Pending proposals are not published. Each source retains its attribution and pagination. JavaScript
   loads nearby sections independently; ordinary source and pagination links also
   work without JavaScript. A larger preview opens as a normal image link, using
   the same source authorization, without adding a second lightbox viewer.
7. **Invite another participant** creates a separate proposal with all existing
   albums unchanged plus the new album. Any current member may initiate it. All
   members must approve again and all direct friendships must exist. The old
   collaboration stays intact until its participants explicitly withdraw from it.

## Content and renewal contract

- The cooperative_content.php entry point owns policy, catalog, admission, client,
  derivative-byte sanitization and media
  parts; models own all photo queries. The shared cooperative_activation_allows_scope()
  gate protects metadata and media. Export checks the current source and every
  ancestor, image ownership, direct-file membership, public visibility and NSFW.
  Administrator sessions and visitor unlocks cannot authorize cooperative export.
- The cooperative_content_api endpoint accepts POST with a header bearer. Its
  bounded catalog returns eight photos plus an opaque continuation cursor, a public
  title and encrypted media capabilities. It never returns system keys, original
  file URLs, local numeric identifiers or filesystem paths.
- Client URLs use only the stored paired HTTPS base and fixed cooperative_media
  route. Peer fields cannot choose arbitrary URLs. Server JSON transport retains
  public-address DNS pinning, no redirects and existing size/time limits.
- Media capabilities bind the source installation, group, membership revision,
  source album, requester, peer generation, individual image, scope and lease
  deadline. The source rechecks current grant and image policy at every read.
  Visibility/NSFW/password changes, local withdrawal or local friendship revocation
  immediately block subsequent reads.
- Only existing JPEG/WebP derivatives are served: thumbnails at 600/300 and previews
  up to 1600 pixels with smaller-derivative fallback. Missing derivatives require
  the normal thumbnail generation workflow. New photographs use complete source-path
  identities including extensions. Existing photographs keep their files and metadata;
  legacy files are eligible only when their stem has verified unique gallery ownership,
  including private and NSFW siblings. No mass regeneration is required.
  Indexed, bounded candidate lookups avoid loading the whole gallery per derivative.
  Image copies and gallery clones preserve existing derivative bytes under new names,
  without decoding or re-encoding photographs.
  Explicit deletion or rename removes confirmed shared cache artifacts before changing
  ownership; unknown membership refuses the mutation. A remaining public photograph
  cannot inherit private pixels from a removed sibling. Cooperative media also checks
  that the bounded source bytes remain unchanged before output.
  Cooperative delivery strips JPEG application/comment metadata and WebP EXIF/XMP/ICC
  chunks in memory with an 8 MiB input bound. Malformed or animated containers refuse.
  GET never generates files or falls
  back to originals. Already downloaded pixels cannot be recalled.
- A content request may advance **one** technical verification step, only after
  local approval. The browser resumes bounded work without turning missing consent
  into approval. Waiting/blocked participants stop automatic loading and leave a
  retryable source. No scheduler is required for ordinary on-demand use; the
  existing per-group CLI worker can keep active groups warm. Visible source pages
  refresh every 45 seconds to renew short-lived media links; hidden sources pause
  network refresh. Original gallery ownership and cursor pagination are retained.
- HTTP output is private/no-store and noindex. Images and links suppress referrers.
  Capabilities expire within the 120-second lease; infrastructure logs should avoid
  retaining credential-bearing query strings.
- Withdrawal is durable before direct invalidation messages clear recipients'
  technical leases. Notifications never forge another server's consent. Failed
  destinations remain retryable. Unreachable peers are bounded by the original
  maximum 120-second lease.

## Compatibility and verification limits

Old metadata references and proposals retain their exact permissions. Create a new
photo-enabled proposal to share previews. No new migration is needed for content,
email or successor groups.

Private/password/NSFW albums, originals, a merged chronological timeline, in-place
membership revision upgrades, credential rotation and coordinator transfer are
outside this public-album version. Separate successor groups keep expansion
reviewable without partially changing a working group.

Regression tests use independent disposable SQLite installations, real domain
services, captured mail and confined Chromium fixtures. They do not qualify
deployment-specific HTTPS/DNS, shared-host timeouts, SMTP delivery or MySQL behavior
between live servers. The tests do not modify a live gallery or mail account.

## Ownership and entry points

- `app/models/cooperative_galleries.php` owns all SQL and compare-and-set persistence.
- The service entry point loads validation, credentials, proposals, storage and source parts.
  Consumers require the entry point, never a part file.
- `security_tokens.php` owns random tokens, hashing and reusable context-bound AES-GCM.
- Migration `202609270001_cooperative_galleries_foundations.php` creates four isolated
  tables. It does not modify existing upload/migration credentials.
- `cooperative_storage_status()` reports available/missing/unknown through the existing
  schema inspector. All authority reads and writes refuse missing or unknown storage.
  Revocation intentionally needs only identity, state, revision and inbound-hash columns.

## Identities and storage

`cooperative_instance_id()` lazily allocates a stable opaque installation ID.
`cooperative_album_identity($galleryId)` allocates a stable opaque album ID after the
caller has authorized local ownership; `cooperative_album_local_id()` resolves it
without authorizing access. Deleting the local gallery removes its mapping. Reusing
the local numeric ID must never resurrect the old public identity.

The tables are `cooperative_identity`, `cooperative_albums`,
`cooperative_peers` and `cooperative_groups`. Group state is a bounded JSON aggregate
with a separate optimistic storage revision. This keeps a proposal, its exact consents
and active membership in one atomic compare-and-set write. It avoids partially
committed member/consent tables; JSON is not a remote authority import format.
The membership revision changes only on activation, departure or suspension.
The storage revision changes on every persisted transition, including each approval.

Group storage is accessed through `cooperative_group_create()`,
`cooperative_group_read()` and `cooperative_group_update()`. The update callback is
trusted internal orchestration and should use only the documented reducers.
Never deserialize a remote snapshot and save it as local state. A compare-and-set
conflict requires reload and revalidation; do not blindly replay against changed state.

## Pairing and credentials

1. Register the named remote installation with `cooperative_peer_register()`.
2. Initiate with `cooperative_peer_prepare_credentials()` without a remote key.
   It returns the newly issued inbound secret once. The other server can prepare
   its own key with that received credential, then return its newly issued secret.
   Finish the initiating side with `cooperative_peer_accept_remote_credential()`.
   This preserves the already issued inbound secret and avoids circular dependency.
   If a verified remote key is already available, preparation accepts it directly.
3. Use `cooperative_peer_pairing_credential()` to resume a pending outbound challenge
   and `cooperative_peer_verify_pairing_token()` to check the presented inbound key.
   Record independently verified `local_consent`, `remote_consent`,
   `inbound_verified` and `outbound_verified` facts at the expected peer revision.
4. Only the complete set activates the peer.

`cooperative_peer_record_confirmation()` is a trusted adapter seam, not an endpoint.
The pairing adapter below authenticates an administrator or verifies a challenge/response
bound to the expected peer and credential generation before recording a fact.
Never forward untrusted JSON fields directly as these facts. The pairing module owns
invitation persistence, expiry and replay-safe challenges; these low-level functions
are not substitutes for that orchestrator.

The `pgc_` namespace and dedicated table isolate peer credentials from the ordinary
upload API manager. Inbound credentials are hashed. Outbound credentials are encrypted
and authenticated with both local and remote identity as context. Encryption derives
a purpose-specific key from the existing configured `visitor_vote_secret` and refuses
missing or shorter-than-32-byte material. Changing that secret requires re-pairing;
the foundation does not silently recover undecryptable secrets.

`cooperative_peer_authenticate()` returns only a nonsecret peer principal.
Authentication is not album authorization. `cooperative_peer_summary()` is the
allowlisted administrative projection. Internal model rows are never response data.
`cooperative_peer_outbound_credential()` is for server-side transport only.

Rotation resets all pairing confirmations and pauses authority until verification
completes. Suspension denies access without deleting the keys. Revocation clears
the inbound hash and advances the peer revision, so stale writes cannot revive it.
Revoked peers cannot be revived by rotation; the explicit pairing lifecycle below
must establish new trust rather than silently reusing old consent.

## Proposals and unanimous approval

Reducers take trusted local aggregate state and return new state without I/O:

- `cooperative_group_propose()`: initial membership, one-member expansion, or exact
  membership renewal. At most one pending proposal. It never changes active grants.
- `cooperative_group_approve()`: one independently authenticated participant's consent
  to an exact proposal digest. Calling it twice with the same actor is idempotent.
- `cooperative_group_decline()` and `cooperative_group_expire()`: discard the pending
  proposal without changing existing active membership.
- `cooperative_group_activate()`: require all exact consents and verified current
  direct friendships, then advance membership revision.
- `cooperative_group_leave()`: immediately remove one's own membership and pending
  proposal. The remaining group can continue when its coordinator remains.
- `cooperative_group_suspend()`: invalidate active sharing and pending proposals.
  Resuming requires a new proposal and new consents.

A proposal digest binds protocol version, proposal ID, group/coordinator IDs, base
membership revision, public audience, validity timestamps, every member/album and every
scope. Canonicalization sorts participants and scopes, rejects duplicates and unknown
fields, and accepts only public audience. Existing participants' albums/scopes cannot
be changed as a side effect of inviting a new participant.

A+B remain active while C waits. Neither C nor its album participates until A, B and C
approve the same digest and Aâ€“B, Aâ€“C and Bâ€“C have current verified evidence.
The invitation UI may automatically record the inviter's own authorized approval,
but the reducer deliberately never forges that approval on their behalf.
At present changing existing album/scope grants and excluding another member are
not implemented; they must get explicit consent workflows.

The friendship resolver returns a 64-character lowercase SHA-256 evidence stamp
produced by `cooperative_friendship_stamp()` from BOTH directly verified current credential generations in canonical
pair order. Unknown/inactive/expired evidence returns null or throws.
A boolean true is not accepted. For nonlocal pairs the future adapter must obtain
independent verified evidence; a coordinator assertion alone is insufficient.
Active groups retain these stamps and compare them on every access decision.
Renewed/rotated friendships therefore cannot reactivate an old grant automatically.
A caller can propose the same membership again and collect fresh unanimous consent.

## Read authorization boundary

`cooperative_group_allows()` requires an active exact membership revision, a requester
in that group, a locally owned album with the requested scope, current matching
friendship evidence for the whole group, and a source-policy callback returning
literal true. Unknown evidence and policy exceptions deny access.
It refuses exporting another participant's album.

The source-policy adapter remains mandatory. It must resolve the local album and
enforce the existing visibility, NSFW and media authorization services, including
available/missing/unknown behavior. The current foundation deliberately supplies no
permissive production adapter. Authentication, group policy and source authorization
must all succeed before any response bytes or media URLs are issued.

## Bilateral pairing backend

`app/services/cooperative_pairing.php` owns invitation, consent, challenge, retry and
revocation orchestration. Its sibling directory contains protocol, transport, storage,
lifecycle, inbound and Admin projection parts. `app/models/cooperative_pairing.php`
owns SQL and local transactions. `app/controllers/cooperative_pairing.php` owns request
parsing, administrator/CSRF checks, HTTP status and JSON completion envelopes.
`admin_cooperative_galleries.php` prepares the presentation model and renders the
friendship view; the browser module enhances its ordinary forms in place.

The canonical `cooperative_galleries` capability defaults OFF. The existing Features
and Settings registries discover it; its four routes share the same effective policy.
Disabled service operations stop before schema, identity or network work, preserving
all records. Each participating installation must enable the capability, apply both
cooperative migrations, configure its explicit canonical HTTPS `base_url` and retain
its existing stable encryption secret. Host headers never choose callback origins.

The production transport requires cURL, OpenSSL and a public IPv4 DNS address with a
valid HTTPS certificate. It resolves once, rejects private/reserved/multicast and mixed
public/private answers, pins the destination, verifies TLS and refuses redirects and
environment proxies. IPv6-only and local development addresses fail closed. Messages
and responses are limited to 16 KiB, response headers to 8 KiB, connection timeout to
5 seconds and total request time to 15 seconds. Deployment workers must allow nested
callbacks: a single-worker PHP development server cannot complete real pairing.

### Consent and exchange

1. An administrator on A selects B's canonical address and creates a targeted,
   one-day invitation. The service discovers B's identity, then persists A's incoming
   key hash and encrypted recovery secrets before returning a `pgci1.` code.
2. B's administrator imports that code. B verifies its exact target identity/address
   and asks A to verify the invitation. Import records `received`; it gives no consent
   and issues no B system key.
3. B explicitly accepts. B persists its own incoming key hash, local consent and a
   challenge before contacting A. The directional `pgc_` credentials travel only
   between the servers. They never appear in ordinary API key lists or Admin JSON.
4. Before binding B's proposed key, A calls the already persisted B address and verifies
   B's possession of that key and exact challenge. A then returns its key and challenge.
   B proves possession back to A, verifies A's acknowledgement and completes activation.
   Both installations require the four independent consent/verification facts.

A stolen bootstrap code alone cannot claim another origin, replace an exchange tuple
or obtain A's system key without a matching, explicitly accepted exchange on B.
Each message binds protocol version, invitation and both installation identities.
A completed remote activation can be acknowledged after invitation expiry, but a first
activation after expiry is refused. Expired incomplete exchanges can be revoked.

SQL transactions cover local state changes only. No network call holds their locks.
Stored exchange material permits retries after lost replies without issuing replacement
keys. Concurrent transitions use row locks and optimistic revisions. Recovery errors
are bounded identifiers, never exception messages, URLs or credentials.

### Interface for the Admin UI

All routes use the existing `index.php?page=...` dispatcher:

| Route | Method and authority | Result |
| --- | --- | --- |
| `cooperative_peer_api` | GET discovery; POST exact JSON with Authorization bearer | Minimal identity or scoped pairing protocol response |
| `admin_cooperative_galleries` | GET with administrator session; optional `panel=1` and `after_id` | Prepared HTML page or drawer fragment |
| `admin_cooperative_state` | GET with administrator session; optional `after_id` | Up to 50 safe projections and `next_cursor` |
| `admin_cooperative_action` | POST form with administrator session and existing CSRF token | Canonical Admin mutation envelope plus `pairing` |

Admin actions and inputs:

| Action | Required form fields besides action and CSRF |
| --- | --- |
| `invite` | `base_url`, fresh 32-hex `request_id` reused for retry of that same request |
| `import` | `invitation_code` |
| `accept` | `invitation_id`, current `revision` |
| `retry` | `invitation_id`; respect `next_attempt_at` |
| `revoke` / `decline` | `invitation_id`, current `revision` |

Creation (including explicit replay of the same unexpired invitation) deliberately
includes a short-lived `invitation_code`. The inviter can use Show invitation code
to recover it after closing the panel; ordinary GETs and lists never redisplay it.
Lists expose numeric `id`, opaque invitation/peer IDs, base URL, role, state, peer state,
revision, expiry, `expired`, `attempts`, `next_attempt_at`, `last_error` and allowed
`actions`. They do not decrypt recovery material or return hashes or keys.

States are `invited` (A created), `received` (B imported), `accepting` (B accepted),
`offered` (A verified B), `confirming` (B received A's credential), `active`, `revoking`
and `revoked`. `ok: true` means the requested local operation completed; the UI must
inspect the returned state rather than assume that friendship is already active.
Pending retries use an exponential 5–300 second delay. They are explicitly driven by
Admin `retry`; this phase adds no background worker or automatic browser polling.

Successful mutations carry stable numeric entity IDs, `panel.keep_open: true`, empty
public `contexts` (pairing publishes no album content), and fallback metadata. Forms
opt into `cooperative_ui=1`: AJAX success adds server-rendered `panel_html` to the
unchanged canonical envelope. The browser passes that envelope to the shared completion
coordinator, whose guarded adapter replaces only the originating component. Capture-phase
delegation also covers newly rendered controls. The shared drawer ownership guard blocks
late responses from changing a closed or reopened panel. Submitted writes are not aborted.

The Features card opens the existing right-side panel; global Settings discovers the
specialized page only while the capability is effectively enabled. Both entry points use
the same controller, view and service operations. Import remains separate from acceptance.
The page shows expiry, pending delivery, backoff, safe errors and bounded pagination.
Retry becomes available after the shown UTC time and an explicit status refresh.
Configuration/schema failures are visible; unavailable new-invitation forms are disabled.

Without JavaScript the same POST action renders a normal HTML result, including the
short-lived invitation code when created. No code is put in a URL, log, flash session or
browser storage. Failed forms preserve bounded, escaped input. AJAX errors retain input,
and read-only refresh retains the unresolved invitation request ID and entered values.
Successful creation/import replaces their forms with fresh intent fields. New friendships
still do not grant access to albums or media.

Errors use bounded codes: stale revision/conflicting intent is HTTP 409, premature
retry 429, unauthorized pairing 403, unavailable schema/transport 503, disabled or
unknown pairing 404, and malformed/expired input 400. Admin error codes have the
`cooperative.` prefix and the canonical mutation error envelope. Peer endpoints use
only an explicit Authorization header, never administrator cookies or query tokens.

### Revocation and fresh pairing

Revocation clears local authority before notification. A lost remote acknowledgement
leaves encrypted delivery authority and an exact retryable message; an exact hashed
receipt permits acknowledgement replay after the remote key is erased. Simultaneous
local disconnects can authenticate only the terminal revocation against a retained
hash; normal peer authentication has already been revoked.

If recovery schema or encryption is unavailable, verified minimal invitation/peer
columns still permit local revocation. Its response reports `delivery_status: unavailable`;
remote notification cannot be promised. Unknown minimal revocation storage refuses.

A new explicit invitation may replace a revoked relationship after queued delivery is
finished. It preserves the numeric pairing identity, increases both lifecycle and peer
revisions, and uses a new invitation and fresh credentials. Old invitation messages no
longer resolve. Re-pairing cannot restore old group consent. Rotation of an active pair
still requires a future dedicated protocol; do not call low-level credential mutators
behind this orchestrator's state machine.

## Local album selection and source preparation

`GET admin_cooperative_albums&after_id=<local id>` is an administrator-only,
capability-owned, private no-store JSON endpoint. It returns `ok`, then `state.items`
with `gallery_id`, `title`, `eligible` and a bounded `reason`, plus `next_cursor`.
It lists at most 50 local candidates and uses one lookahead row. Refused candidates
remain visible to the administrator with their reason. No paths, hashes, share tokens,
public album identities or remote album data enter this response. Reading this picker
performs no identity writes and no network requests; it is ready for the album UI.

The canonical `gallery_public_export_policy()` in `gallery_access.php` uses fresh
model reads independently of Admin sessions, password unlocks, share tokens and NSFW
acknowledgements. The album and every ancestor must be public and listed, use normal
access, and have no NSFW restriction. This deliberately excludes unpublished/unlisted
albums even if a direct browsing URL would work. Missing parents, cycles, malformed
rows and paths deeper than 64 galleries refuse export. Complete verified access/NSFW
schema is required; export has no legacy missing-schema compatibility. A confirmed
legacy visibility enum remains readable, but unknown enum inspection refuses.

`cooperative_album_source_member($galleryId, $scopes)` is an internal orchestration
helper whose caller must authorize the administrator's selection. It validates exact
scopes, rechecks source eligibility, and preflights both identity stores before creating
a stable installation/album reference. Its result contains only `instance_id`,
`album_id` and canonical `scopes`, suitable for an immutable proposal. Preparation is
neither consent nor sharing authority. The existing low-level identity helpers remain
internal persistence primitives, not substitutes for this source preparation boundary.

`cooperative_album_source_policy($albumId)` resolves only locally owned albums and
rechecks current source restrictions without trusting an old picker result. Consent
and export adapters must call this again; preparing an identity does not freeze access
policy. Individual image visibility/NSFW and exact group/revision/scope authorization
are still required before any media export. These helpers do not implement a media API,
a peer album catalog, remote approval or group activation. No new migration is needed.

## Initial proposal exchange and direct decisions

The `cooperative_proposals.php` module transports **initial** proposals only
(`base_revision = 0`), with two to 32 installations. A first A+B+C proposal is
supported. Adding C creates a separate initial A+B+C proposal with a new group ID.
The protocol refuses nonzero base revisions rather than importing unverified prior
membership. Existing pure in-place expansion reducers are unchanged.
Ordinary delivery and decision observations cannot activate a group. The separate
verification/activation operations below can establish a bounded local metadata grant;
no operation here authorizes individual media files.

The existing `cooperative_groups` JSON aggregate stores an `exchange` section alongside
the immutable pending body: local decision, locally approved peer generations, stable
local source ID and direct observations. No migration is needed. Neither incoming
messages nor coordinator snapshots may supply this section, approvals or active members.
The local pending approvals map contains only this installation's explicit approval
until finalization constructs a complete verified consent set and activates the group.

### Administrator API

All three routes are owned by `cooperative_galleries`, default OFF, and return private
no-store JSON. Administrator mutations additionally require CSRF and release the PHP
session before any outbound request. There is no browser UI for these operations yet.

- `GET admin_cooperative_proposals` returns a local inbox (`items`, `next_cursor`),
  at most 20 entries with lexical `after_id` pagination and one lookahead row.
  `group_id` instead selects one local proposal. Reads do not contact peers.
- `POST admin_cooperative_proposal_action`, `action=prepare`, accepts a local
  `gallery_id` and JSON-list `scopes`, and returns the canonical `member` reference.
  It reuses the public source boundary; the result is not consent.
- `action=create` accepts a stable 32-hex `request_id` and JSON-list `members`.
  This creates a one-day initial proposal coordinated locally. Its ID, digest and
  expiry persist across retries; the same request with different members is refused.
  Creating a proposal does not implicitly approve it.
- `action=approve|decline` requires `group_id`, exact `digest`, and the displayed
  local storage `revision`. It decides only for this installation's source.
- `action=deliver|refresh` requires `group_id`, local storage `revision` and one
  `peer_id`. Deliver sends the stored immutable proposal and is coordinator-only;
  refresh obtains that participant's own decision. Each action makes at most one
  request, using the stored directly paired HTTPS origin and system credential.
  No arbitrary destination URL, background worker or automatic retry is introduced.

Mutation responses use the canonical envelope with a stable local album entity ID,
empty public contexts and, for explicit UI requests, owned panel refresh metadata.
Plain JSON consumers keep their original response contract.
`proposal` contains the body, digest, local storage revision, own decision, observations
plus `state`, `membership_revision`, `verification`, `authorization_expires_at` and
`sharing_active`. The last value is true only while the local source and bounded lease
remain valid; it is false during ordinary proposal exchange. Incoming observations
are never copied into the remote approval map. They are timestamped diagnostics,
not reusable authorization.

### Peer boundary and consent lifetime

`POST cooperative_proposal_api` accepts only JSON plus a directly authenticated bearer.
GET discovery, administrator cookies, share tokens and forwarded approvals provide no
authority. Exact envelopes contain `protocol`, `action`, `sender_id`, `recipient_id`,
`group_id`, `digest`, and additionally `body` for `offer`. Unknown fields are rejected.
The sender of an offer must be the named coordinator and a participant; the receiving
installation must have its own prepared local source in the proposal. A `decision`
query is allowed only to a directly authenticated member of that exact proposal.

Responses bind both identities, group and digest, and report only the responding
installation's own `pending`, `approved`, `declined`, `expired` or `consent_stale`
state. An effective approval includes that installation's current local revisions for
all its direct friendships within the proposal. Unknown/inactive friendships or changed
generations invalidate effective consent; explicit new approval is required after a
generation change. Source protection is rechecked at approval and when reporting an
approval. Restoration of source visibility permits the unchanged consent only while
its friendship generations and immutable proposal remain valid.

An exact offer retry preserves the recipient's decision, including terminal decline.
A declined initial proposal cannot be reopened: use a new explicit request/proposal.
Expired pending proposals cannot be delivered or newly approved. Already activated
consent remains bound to its immutable document and credential generations; renewing
its short authorization lease does not reopen the initial approval window. Body substitution, even with a
new valid digest, cannot replace an existing group. A lost delivery response leaves the
sender's proposal unchanged and can be retried with the same identity and body.

Transport reuses pinned HTTPS with redirects/proxies disabled and bounded 16 KiB control
messages. It does not run under a SQL transaction. A returned observation is saved only
if both the original local proposal revision and the direct peer snapshot are still
current. This check also applies when the returned observation is byte-identical to an
older one; a concurrent decline cannot be hidden by a no-change optimization.

Unanimous observations are intentionally insufficient for activation. Only the fresh
verification rounds below can finalize local membership. The content layer and
Admin workflow described above build on this authority.

## Fresh verification, activation and metadata authorization

Each installation independently verifies every other participant over its directly
paired origin. The coordinator cannot deliver a preassembled approval package or
activate another server. Existing pending records remain compatible; verification
fields are added to their JSON aggregate lazily. No new migration is required.

The existing Admin action route adds these explicit CSRF-protected operations:

1. `verify_start` takes `group_id` and local storage `revision`. It requires an effective
   local approval, clears any old local authorization lease, and returns a fresh
   `verification.round_id` with a fixed deadline. Starting renewal intentionally pauses
   local metadata authority until the full round completes.
2. `verify_peer` additionally takes `round_id` and one `peer_id`. Before network work it
   reserves a new storage revision and removes that peer's previous receipt. Failures
   therefore cannot leave an earlier successful check in place. A failed operation may
   have advanced the storage revision; reload local state before retrying. One action
   makes one remote request, outside SQL transactions.
3. `activate` takes `group_id`, the current storage `revision` and `round_id`. It checks
   every direct response, the local consent snapshot, source policy and both endpoint
   generations for every friendship edge. Initial finalization uses the canonical
   reducer to create membership revision 1. Renewal preserves that membership revision
   and refuses any changed friendship graph. Retrying a completed finalization with
   the same current round does not rewrite state or extend its deadline.

Peer action `verify` adds a required opaque `challenge` to the normal decision request
and reply. The challenge is the local round nonce; sender, recipient, group and digest
remain bound as before. Old replies, missing challenge support, missing edges, forwarded
claims and malformed responses fail closed. Verification receipts live separately from
ordinary diagnostic observations. Only directly queried participants supply their own
credential-generation maps; a complete graph combines both independently observed
endpoints of every edge.

`COOPERATIVE_VERIFICATION_TTL` is 120 seconds. The round and its resulting authorization
lease share the same expiry, measured from **the start of collection**, not from the
last response or activation. Slow or failed rounds expire without authority. There is
a bounded renewal CLI and a one-step Admin orchestration action (described below).
They preserve the same fixed deadline and never accept consent on behalf of a user. A source with a stale lease denies
metadata reads even though its stored membership remains `active`.

Activation is local and may be partial across installations. An installation without
its own verified active revision refuses source access. Every participant must activate
before its initial pending proposal expires; already active participants can renew
unchanged consent after that original deadline, but a still-pending expired participant
cannot complete the old proposal. The original body/digest are archived locally after
activation so direct decision replies and later renewal remain bound to the same intent.

`cooperative_activation_allows_metadata($groupId, $requester, $bearer, $albumId, $revision)`
is the reusable source-side gate for **local album metadata only**. It authenticates the
direct system credential, requires the exact locally active membership revision and
unexpired complete-graph lease, and rechecks all current local friendship generations
and the anonymous source policy. It cannot authorize a remote album for re-export,
a nonmember, another album, a visitor/Admin session, or an image/preview/original.
The metadata HTTP exporter below uses this gate and restricts the fields it exposes.
Individual media still require additional image authorization.

Local active `decline` immediately clears verification/lease state and suspends local
membership at the next revision before notification delivery. Replays cannot restore it.
An ordinary direct refresh that learns a negative decision or changed verified
credentials also clears the local lease and collected round. Revoking a local direct
friendship, disabling the feature, or losing verified source/schema readiness blocks
local metadata authorization immediately. A remote change not yet observed may remain
unknown only until the existing lease expires: this is a bounded maximum delay, not
instant global revocation. Direct invalidation notifications accelerate revocation
at reachable peers without extending this limit.

Credential-generation changes cannot renew an already active group's old graph even
when pending peers explicitly approve new generations. A new proposal is required;
successor proposals provide explicit re-consent with a separate group identity.
Ordinary source visibility
restoration can reuse unchanged consent only while all other lease and generation
conditions still hold. Photographs are neither transferred nor rendered by this layer.

## Automatic verification progress and scheduled renewal

The read-only Admin projection includes `maintenance` with `action`, optional `peer_id`,
`retry_at` (Unix seconds) and `reason`. Actions are `start`, `verify`, `finalize`, `ready`,
`waiting` or `blocked`. The projection does not perform network work or write storage.
These hints are advisory; execution always reloads the aggregate and validates its
storage revision, source policy and consent.

`POST admin_cooperative_proposal_action` with `action=advance`, `group_id` and `revision`
selects exactly one next operation. It performs at most one direct peer request and
returns the existing canonical mutation envelope plus the new proposal projection.
It never approves or delivers a proposal. Explicitly approved pending proposals can
progress to initial activation; revoked, stale or expired local consent stays blocked.
Browser callers must stop on `ready`, `waiting` or `blocked`, honor `retry_at`, and reload
state after a failure because the failed request may have reserved a storage revision.
Browser wiring remains a separate implementation step.

For an already active group, configure the hosting scheduler on **each installation**
to invoke once per minute, substituting its actual locally stored group ID:

```sh
php scripts/cooperative_renew.php --group=0123456789abcdef0123456789abcdef
```

The CLI is not automatically installed or scheduled. It handles one explicit group per
invocation and refuses pending or suspended groups. No user credential, peer API key,
email address or invitation token belongs in the command. Output contains only the group
ID, effective authorization status, expiry and safe maintenance hints. Exit 0 means
current authority; exit 1 means refusal/unavailability; exit 2 means invalid arguments
or an incomplete/waiting result. `--help` and malformed arguments do not bootstrap the
application. Exceptions never print raw database or transport diagnostics.

A valid lease with more than 60 seconds remaining needs no writes or network calls.
During the final minute the worker starts renewal, which deliberately pauses local
metadata authority until verification completes. It resumes a nonexpired interrupted
round, starts a new one after expiry, and never retains a failed peer's old receipt.
An explicit negative peer reply imposes a 30-second retry delay; the invocation stops
instead of spinning. Transport errors and CAS conflicts also stop the pass for a later
scheduler invocation. Local decline or stale consent cannot be repaired by automation.
The original one-day proposal deadline is separate from these technical lease timings.

Each pass performs at most member-count plus one local steps: start, one request per
other participant, finalization. The existing 120-second round deadline and bounded
transport timeouts still apply. Slow groups may fail to renew before expiry; no stale
access is allowed. Use scheduler overlap prevention for the same group; concurrent
workers remain fail-closed through existing CAS but can cause avoidable renewal failure.
This is a bounded CLI pass, not a resident daemon or a promise of uninterrupted access.
No live scheduler, external email delivery or production migration is configured here.

## Administrator proposal review

`GET admin_cooperative_collaborations` renders a private administrator page or a drawer
fragment (`panel=1`). It is owned by the existing `cooperative_galleries` capability and
is discoverable under Advanced Settings and from the friendship panel. The original
`admin_cooperative_proposals` JSON read endpoint retains its contract.

The bounded review inbox displays the local album title, participants' paired origins,
opaque album references, each proposed permission, local consent and timestamped remote
observations. Remote observations are not presented as fresh authorization. Active
membership and currently usable technical verification are distinct statuses. Missing
friendships, removed albums, expired proposals and suspended membership remain visible.
Opening the inbox never contacts peers, approves anything or creates an empty installation
identity. Album references are necessary because remote album metadata is not yet fetched.

The panel offers explicit approval/withdrawal, coordinator delivery to one participant,
a direct decision refresh and an individual verification step. Deliver and verify
runs a finite sequence across dynamically refreshed fragments. Email invitations,
photo scope selection and successor proposals use the same panel. The CLI remains
available for unattended renewal; content reads can resume verification on demand.

Forms carry the exact displayed digest and storage revision. Explicit
`cooperative_proposal_ui=1` selects the UI response adapter; JSON-only API consumers are
unchanged. Successful UI mutations preserve the canonical entity/context envelope, add
`cooperative_proposals` panel metadata and an owned `panel_html` fragment, and pass through
the shared completion coordinator. Dynamic forms use the existing cooperative capture
handler. Errors keep the current fragment and prompt a read-only refresh before retry;
late responses cannot replace a reopened drawer. No-JavaScript forms render their HTML
result directly. There is no automatic approval, page reload or mutation on GET.

## Composing a proposal from album references

Each administrator selects an eligible local album in the paginated picker and uses
`reference` to prepare a `pga1.` code. The code contains only that installation's opaque
identity, opaque album identity and explicit proposed scopes (metadata, optionally
thumbnail plus preview). It has no API credential,
URL, signature, approval or access authority. It is a reusable locator, not the expiring
invitation: the subsequently created proposal still has the existing one-day deadline.
Anyone can construct a locator; direct friendship and the receiving administrator's exact
local source review remain mandatory. The receiving server rechecks its source on import
and approval. Codes never authorize photographs or imply remote consent.

The coordinator selects its own local album, pastes one remote code per line, and submits
`compose`. The domain rejects malformed/oversized references, duplicates, unsupported
scopes and participants without a currently active direct friendship. It uses the existing
source policy and immutable proposal writer. The explicit photo checkbox proposes
thumbnail and preview scopes; originals remain unsupported. Creation neither approves
nor delivers the proposal. Explicit approval/delivery and complete graph verification
continue through the review panel, including the B-C friendship requirement.

The source picker pages 50 albums at a time and disables ineligible selections. No GET
creates an album identity. Form data stays in the current DOM, without URL or browser-storage
persistence. Read-only refresh and source pagination preserve the chosen album, pasted
codes and immutable request identity; preparing a reference also preserves a separately
started proposal draft. Successful composition resets only its completed draft. A lost
response can be retried with the same request ID and unchanged membership without creating
a duplicate. Changed membership under the same ID is refused. A completed proposal is
included in the returned review fragment even when its ID lies outside the current inbox
page. Ordinary HTML failures retain submitted selection, codes and request ID.

Admin action `reference` takes `gallery_id`; `compose` takes `gallery_id`, `request_id`
and newline-separated `references`. Both use the existing Admin/CSRF action route and
canonical UI mutation envelope. The original `prepare`/`create` JSON interfaces remain.

## Public album metadata endpoint

`POST cooperative_metadata_api` accepts a bounded JSON object with exactly `protocol`
(integer 1), `sender_id`, `recipient_id`, `group_id`, `album_id` (opaque IDs), and
`revision` (positive integer). Authentication is exclusively the direct system token
in `Authorization: Bearer ...`; browser/Admin sessions and query, form or cookie tokens
never substitute for that credential. GET is rejected. The existing JSON reader enforces
the content type and 16 KiB input bound. The route belongs to `cooperative_galleries`.

The source requires the exact active grant and nonexpired verification lease. It also
requires its current anonymous public export policy, including every ancestor: private,
unlisted, password-protected and NSFW sources are refused, as are unknown schema states.
Local source policy and credential authority are checked before and after reading the
label; a changed group storage revision during the read refuses the response. This
endpoint does not initiate peer requests, renew a lease or write any records.

A successful response contains `ok`, `protocol`, reversed `sender_id`/`recipient_id`,
`group_id`, exact membership `revision`, `album: {album_id, title}`, and
`authorization_expires_at` from the already checked lease. The title is bounded to 512
Unicode characters. There are no numeric database IDs, raw model fields, folder paths,
password fields, descriptions, photograph lists, image addresses or credentials in the
response. Clients must treat the title as untrusted text and must not infer media access
from a successful metadata read. No peer metadata consumer or public rendering is added
in this stage.

Replies use the shared private/no-store JSON, noindex and nosniff headers. Missing or
invalid authority (including expired grants, wrong membership/album or withdrawn access)
returns a bounded 403 `metadata_unauthorized`; invalid protocol is a bounded client error,
while unavailable export data returns 503 `metadata_unavailable`. Raw exceptions are
never returned. No production peer is contacted or endpoint deployment performed by the
source implementation or its isolated tests.

## Optional future extensions

- In-place membership upgrades instead of explicit successor groups.
- A richer remote album picker and a merged chronological photo timeline.
- Protected audiences, originals, replication and remote write access.
- Active credential rotation and coordinator transfer/recovery.
- Deployment-specific qualification using independent HTTPS/MySQL installations
  and actual configured mail delivery.

No migrations are applied to a live installation as part of source implementation.
When deployed, use the existing migration lifecycle.

## Verification

`cooperative_galleries_foundations_test.php` covers A+B+C unanimity, missing edges,
pending isolation, expiry, replay, digest tampering, revision mismatch, source-policy
refusal, departures, generation binding and authenticated encryption.

`cooperative_galleries_storage_test.php` executes the migration's table definitions
with MySQL-specific syntax adapted only for an isolated in-memory SQLite fixture.
It tests durable IDs, peer isolation/rotation/revocation, stale writes, schema refusal,
narrow revocation and deleted-gallery identity reuse. This is not a live MySQL
migration/concurrency qualification.

`cooperative_pairing_workflow_test.php` runs both real pairing services on independent
in-memory databases with an in-process transport. It covers consent, hostile bootstrap
use, retries after lost accept/confirm/revoke replies, expiry, simultaneous revocation,
re-pairing generations, narrow revocation, OFF/schema refusal and real controller
Admin/CSRF/envelope behavior. `outbound_http_transport_test.php` exercises production
transport decisions through controlled DNS/cURL responses with no external networking.
`admin_cooperative_galleries_browser_test.mjs` uses the shared isolated Chromium runner
to exercise production drawer and friendship modules: all actions preserve the URL and
open panel, dynamic replacements stay intercepted, duplicate submits are blocked, errors
and refresh retain input, and late responses cannot overwrite a reopened drawer. The PHP
workflow also verifies actual rendered fragments, explicit acceptance, escaping and the
non-JavaScript fallback. These tests do not qualify live HTTPS or MySQL locking.

`cooperative_album_sources_test.php` exercises actual source policy, local model,
member preparation and Admin controller on disposable SQLite: inherited restrictions,
changed/deleted sources, cycles/orphans, typed schema refusal before writes, exact scopes,
pagination, projection, capability OFF, method and administrator boundaries.

`cooperative_proposals_exchange_test.php` uses independent SQLite databases for A/B/C
and an unrelated peer D, real source/credential/proposal services and actual Admin
controllers. It covers missing direct edges, lost delivery responses, exact retries,
independent decisions, forged/forwarded claims, terminal decline, changed protection,
credential generations, concurrent decline, unsupported expansion, expiry, OFF and
missing/unknown schema refusal. Transport is an in-process substitute: live HTTPS and
MySQL concurrency are not qualified by this fixture.

`cooperative_activation_test.php` reuses the shared isolated exchange fixture without
rerunning the proposal suite. It verifies nonce/edge rejection, incomplete and failed
rounds, independently activated A/B/C, exact metadata authority, expired lease denial,
renewal without membership changes, completed retry identity, source protection,
local/observed remote decline, concurrent local decisions, changed B-C generations and
feature/schema refusal. Expiry scenarios use controlled fixture state without sleeps.
Live HTTPS and MySQL locking still require deployment qualification.

All tests are discovered by the central audit. Run the audit profiles prescribed
in AGENTS.md; do not substitute individual test loops.

Network verification and public remote catalog reads reserve finite nonce-bound
ownership in the existing group aggregate before contacting peers. Concurrent
requests wait; failed/crashed operations retain a finite thirty-second cooldown.
Successful remote catalogs have one-second admission spacing per group. These
reservations cannot extend the fixed verification or media-ticket deadline, and
late replies cannot clear a newer owner or restore withdrawn consent. Local catalog
reads do not consume remote admission. No remote catalog cache is used.

`cooperative_maintenance_test.php` verifies one-request progress steps, explicit-consent
boundaries, active-only renewal, no-op fresh leases, fixed renewal windows, restart after
lost responses, negative-consent backoff, expired round replacement and concurrent decline.
It also exercises the actual Admin action envelope and capability-OFF no-storage behavior.

`admin_cooperative_proposals_test.php` covers real review HTML, escaping, exact consent
fields, read-only/empty rendering, UI mutation envelopes, stale submissions, capability
OFF and the no-JavaScript fallback. `admin_cooperative_proposals_browser_test.mjs` exercises
all five proposal action types, repeated dynamic controls, unchanged URL/open drawer,
errors and stale completion after reopening with the real shared browser modules.

`cooperative_composition_test.php` covers reference parsing, scopes, duplicate/missing
friendships, protected sources, immutable retries, canonical UI responses and retained
HTML drafts. The cooperative browser fixture covers reference/compose actions and draft
preservation across source pagination, unrelated reference creation and successful reset.

`cooperative_metadata_test.php` exercises real metadata export from disposable approved
A/B/C installations: exact response allowlist, directed credentials, pending/expired and
wrong grants, protected source refusal, Unicode bounds, unknown schema, local revocation,
OFF-path query avoidance, read-only behavior and actual controller method/header/content
refusals. Success-path transport remains a service-level fixture, not live HTTPS qualification.
