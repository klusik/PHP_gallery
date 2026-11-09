# Compatibility lifecycle and retirement register

## Identity linking and legacy uploads

WinApp direct linking JSON and media requests use a truthful stable
`PHPGalleryUploader` User-Agent. A deployed HTTPS verification page was observed
to reject the same public discovery request without this header while admitting
it with the client identifier. This is a general HTTP interoperability repair;
there are no provider-specific exceptions, browser impersonation, relaxed TLS,
redirect, cache, response-type or authorization checks. The client retains
separate safe HTTP/transport and server-unavailable diagnostics. Discovery can
still refuse linking when installation readiness is unavailable; a successful
classic API upload does not establish browser-linking readiness.

The Link gallery address prompt accepts a host and installation path without
a scheme, defaulting only that user input to HTTPS before normal validation.
Explicit direct localhost HTTP remains supported. Protocol and stored-issuer
validators still require their existing explicit canonical URLs.

The configured linking issuer is confirmed against the raw public request
path, rather than a hosting rewrite's internal `SCRIPT_NAME`. Exact origin,
mount spelling, HTTPS/direct-localhost policy and the legacy explicit issuer's
precedence remain enforced. The direct `public/index.php` entrypoint remains
supported at that same mount. Browser start, consent and login return paths
use the verified public issuer. Owner-only grant inspection retains its
permanent safe-public-request fallback when issuance settings are invalid;
it does not acquire linking authority or change grant ownership. Real deployed
upgrade prevalence and rewritten-hosting end-to-end approval remain unknown.

Browser-approved linking remains experimental and unqualified. M1 implements
version-1 readiness discovery and a browser handoff that reuses hashed
request/PKCE storage without a schema migration. The handle is short-lived and
non-authorizing; after ordinary Admin login, CSRF-protected session binding
leads to explicit consent. Only approval or cancellation starts the existing
one-use PKCE loopback flow. Core readiness requires the verified exact issuer,
independent secret and complete core schema; unknown optional capability
storage hides only that operation. M1 source qualification is green at
`15978bc6`: BASIC `42098837922` and WinApp `42098837757` (Ubuntu 724 pass,
12 skip; Windows 734 pass, 2 skip). The original gallery-scoped `pgu_` upload path
is independent. An explicit new scope can list the ordinary Admin's galleries,
including private and unpublished ones; older identity-only grants do not gain
that permission. Selecting a gallery alone is not upload authorization. A
separate explicitly approved upload scope covers all ordinary Admin galleries.

Native gallery typeahead adds two temporary, expressly approved search
bundles that fit the original scope columns. It reuses gallery and tag
storage. An existing listing grant or a server without search readiness
keeps the original paginated GET and a local title filter. The same fallback
keeps the picker usable beyond the 10,000-row catalog budget. Keep basic
listing for older servers and for permissions that do not include search.
Deployed catalog sizes and mixed-version search use are unknown.

The WinApp links chooser retains one live approval per exact installation
in memory. The browsing view and the upload target have separate bindings,
including the session captured by an active worker. Expiry and explicit
unlink affect the matching session. Profile history stores only canonical
recent addresses in `recent-gallery-links.json`, up to 20. Profiles without
the file can use the existing saved destination hint. On macOS, startup may
restore a transient listing grant from the user's Keychain within its original
expiry, after server identity verification. The application uses the login
Keychain for source launches. If the vault is unavailable, the live in-memory
session remains usable. Compatible WinApp links can now request explicit
rolling consent and rotate a saved credential from Keychain or Windows
Credential Manager at startup. Older clients still support interactive
browser approval.
Installed multi-link use is unknown.
[Apple Keychain implementations](https://developer.apple.com/documentation/technotes/tn3137-on-mac-keychains).

Existing linked upload target selection is enabled for a verified local Windows
profile after native mode/vault/restart qualification at `4fcdd4b5`. After
restart, the saved linked mode is disconnected and needs explicit selection;
only an explicit choice can return to the saved API key.
On a verified first-run profile, explicit upload-target selection initializes
local settings before publishing linked mode through the existing two owners.
The API-key URL and credential stay separate. Pending flights are checked
against the saved destination even without a live session. Unbound import
previews do not own a target; dispatched work and missing or corrupt binding
evidence still block a change. Unlinking invalidates the live target without
choosing legacy mode. Installed Windows coverage for these paths is pending.
The separate created-gallery shortcut requires the original receipt lease,
fresh status, current issuer/account/scope and independent target verification.
Disposable HTTPS evidence covers a lost completed reply, same-key replay,
explicit resume and upload to the exact child. Native receipt publication and
disconnected restart passed on Windows at `9eaa718a`. Installed client and
deployed prevalence remain unknown.
For one current unknown upload, inspect the gallery and original source before
review. The optional hash probe observes bytes only; it does not confirm the
request or prove absence. Review defaults to No and preserves the marker,
original and ZIP. The same path or bytes remain blocked across restart, while
other eligible sources can continue. No resend or source deletion follows from
review. A disposable HTTPS fixture processed two manual Import items and one
Watcher item through the actual PHP/MySQL 8.0.44 upload pipeline; one stored
upload reply was lost. Matching inventory did not confirm or resend it, and
source files remained. This observation does not qualify activation.

Windows Watcher has a separate confirmed-source deletion owner. New or missing
settings enable deletion, while a saved explicit off choice remains off.
Only a successful response for the current transfer followed by durable local
acknowledgement can reach it. It compares the captured source identity and
digest through a native handle that excludes writers and replacement, then
deletes that same opened file. Failed, uncertain, inventory-only, restarted or
replaced sources retain their existing recovery behavior. An unavailable or
busy native handle retains the file and reports a warning. This policy applies
to both linked and legacy Watcher uploads. Manual imports and source ZIPs are
outside it.

The native deletion owner currently supports Windows. Other platforms retain
sources because a path-only unlink cannot provide the same opened-file deletion
boundary. Keep that retention path until a platform-specific owner is qualified.
Real-world prevalence of concurrent source replacement is unknown. Hosted
fixtures cover same-file success and retention boundaries, and desktop evidence
must identify the exact candidate and use disposable sources.
Linked target activation and worker admission require a fixed local Windows
drive or an owner-controlled local macOS volume and an unredirected profile.
The macOS source launcher uses a private file and native workspace lock for
the second non-secret mode record. Loss or mismatch of one record blocks
upload work. Both files share the same profile, so loss or rollback of the
whole profile cannot be detected. After restart, reapprove a live session
and select its exact target. Older incompatible processes sharing that
profile must be closed. Point-in-time admission cannot fence external
filesystem changes. Deployed macOS usage is unknown.

The Mac source profile retained a frozen queue with one file after a confirmed
upload. Rechecking that same path and digest every scan hit the shared server
request limit, leaving Stop waiting and the UI offering Begin based on its
empty legacy key. Within one Watcher lifetime, an acknowledged path/digest
pair now stays settled for its captured gallery. A changed digest and a
restarted worker still check the gallery. A failed linked inventory or
expired grant stops capture with the original queue and a recovery reason.
The verified mode record supplies the original issuer and non-secret binding
after restart. Retrying uses that target. Explicit local closure waits for
the workers, records abandonment and preserves the queue, original photos,
reports, uncertain-upload history and credentials. The existing legacy API
key editor and its explicit local cleanup remain supported. Deployed adoption
and installed Windows click-through remain unknown.

The same Mac profile retained a v1 target lock after its uploader closed.
The v2 marker keeps the old filename as an exclusion fence for older
processes and binds it to an OS-locked local file. A matching marker can be
reopened under that native lock after process exit. Unverified markers,
including v1, still require operator inspection because their live owner
cannot be proven. A macOS process-exit check exercised recovery and live
contention. The original Mac v1 lock was archived after checking it had no
open handle. This does not establish remote completion, repair uncertain
transfer evidence or qualify power-loss recovery.
The shared upload service retains the legacy API's gallery-scoped `pgu_`
authentication, result envelope and upload behavior.
Real disposable HTTP upload passed in all four pairings of archived
`df36afa1` and `15978bc6` clients and servers, including inventory, scanner and
thumbnail handling. Deployed version prevalence remains unknown.
Keep the legacy upload path available for newer clients with older supported
galleries and older clients with newer galleries. Actual mixed-version upload
coverage, deployed version prevalence and safe retirement evidence remain
unknown, as are installed native-client prevalence and mixed-version linking
behavior; never remove the fallback based on version numbers alone. Owner grant
revocation works across stored issuers for the same ordinary
Admin account within a shared database, without broadening bearer scope. See
`docs/APP_LINKING_PHASE_0.md` for outstanding security and deployment gates.

Browser linking accepts direct HTTP for a saved `localhost` installation on
the same computer. This supports local installations with an HTTP website
address, including an explicit port and a mounted path. PHP requires direct
loopback endpoints, and WinApp connects only to loopback for this scheme.
Temporary and rolling credentials bind to the exact HTTP issuer. Remote
galleries require verified HTTPS. Separate legacy offline grants remain
HTTPS-only and keep the 30-day absolute and seven-day inactivity limits.
Rolling grants extend both existing deadlines for seven days on authenticated
use or rotation. Consumed rolling hashes remain replay evidence for at least
seven days, subject to the existing 4,096-hash family cap. The database
transition fixture covers expiry, renewal and replay. Installed Windows and
mixed-version deployment coverage are pending. Keep transient approval for
older servers until deployed usage supports retirement.

MAMP's Apache `mod_fastcgi` can drop the bearer header on the PHP request
after approval. The gallery's `.htaccess` preserves WinApp linking bearers in
the CGI environment using `mod_setenvif`. The linking controller's existing
header validation remains authoritative. Keep this rule while supported
FastCGI installations can strip the header. The prevalence of affected
installations is unknown.

The unintegrated two-copy upload-mode foundation does not yet repair restart
mode recovery or change the supported `pgu_` path. Linked target activation is
disabled in the meantime. Its fixed
credential namespace is not multi-profile safe, and its generation comparison
protects only processes that cooperate with the required leases. Loss of both
copies is indistinguishable from an unmigrated legacy profile; matching rollback
of both copies to an earlier generation is also undetectable from these records.
Windows API behavior and Windows tests for this foundation remain unverified.
Its version 2 path binding checks exact lexical spelling, not Windows file
identity. The separate work-admission owner is also unintegrated and protects
only operations explicitly registered within the same process.
The `--once` path uses the ConfigStore mode port and coordinator before its
legacy scan. Unavailable vault or ambiguous, linked and first-setup evidence
returns nonzero before scanning; verified legacy and unmigrated legacy remain
eligible. This guard holds an in-process lease for the scan, but does not cover
GUI workers or fence other processes. It rechecks mode evidence before each
inventory and upload endpoint attempt and redirect; an in-flight request can
still finish and the check is not atomic with network transmission. The port
preserves a string `--config` spelling before `Path`
conversion and checks it against both path owners; `Path` callers supply only
their already-converted spelling. Verified unmigrated legacy paths, including
UNC, do not need a v2 binding. Lexical spelling is not proof of native identity;
aliases and native file identity remain unqualified.

Legacy uploader requests carrying a gallery API key refuse redirects to another
host, an unapproved port, or from HTTPS to HTTP. Same-host redirects on the
same effective port and the standard HTTP-to-HTTPS upgrade remain eligible.
Installations relying on other redirects must update their configured URL;
their prevalence and real mixed-version impact are unknown. Existing direct
API-key uploads remain supported. Windows and deployed behavior require
qualification.

The GUI's legacy workers now use the shared mode owner and captured
configuration. An unavailable or mismatched mode refuses credential-bearing
work; settings changes can be refused while admitted work is active. Legacy
global path/hash history is retained but no longer authorizes a skip for a
different connection. Old jobs with unverified duplicate decisions require a
new preflight. Watched source-photo deletion and legacy source-ZIP deletion are
temporarily disabled; saved preferences are retained. Saved-key Finish now
holds an exact `legacy_revoke` work ticket across positive server confirmation
and its asynchronous GUI acknowledgement. Only that sole current ticket can
atomically become the existing configuration-save reservation. Every HTTP
attempt, including gateway retry, checkpoints the original configuration.
Operation, endpoint/key binding and opaque ConfigStore generation must match
before clearing the key. Ordinary Stop has no revoke authority and keeps the
key; a running Stop cannot be upgraded by toggling a flag. Failed/uncertain
local persistence remains an error without claiming rollback of server revoke
or clearing newer credentials. Restart never reconstructs revocation consent
or treats retained local credentials as proof the server key still works.
Linked revocation remains a distinct lifecycle. These restrictions affect existing users; prevalence
and Windows behavior are unknown. The separate experimental pgu journal is not
integrated and restart after an uncertain transfer is not crash-safe.

The active ConfigStore uses the configuration snapshot primitive for ordinary
loads, saves and migrations. A malformed or unverifiable configuration now
blocks startup instead of becoming defaults; a confirmed missing file retains
first-run behavior. Its 4 MiB document limit, single-link requirement and
positive device/inode checks can reject existing installations; their prevalence
and Windows behavior are unknown. Parent aliases and reparse points have not
been qualified. A readback is not an atomic CAS against older or unrelated
writers and does not establish power-loss durability. This does not integrate
the separate two-record upload-mode foundation or cure its restart fallback.

Delegated upload success now requires fresh per-original scanner insertion,
current target row and observed single-link file identity, including after
automatic rename; ambiguous evidence refuses the acknowledgement after possible
disk effects. The client requires one positive image ID for its one-image
request and the exact `upload_evidence: fresh_insert_v1` acknowledgement.
Older delegated servers cannot supply that guarantee and leave an upload
unresolved after a possibly successful write, while the separate `pgu_`
transport and response retain their existing behavior. Filesystems without
usable inode identity may also leave delegated uploads unresolved. Prevalence,
real mixed-version usage
and safe retirement evidence are unknown. These checks do not exclude external
filesystem writers or guarantee durability. Database leases coordinate only
cooperating application writers; shell or FTP writers can race target selection
and the move. File identity uses inode, size and second-resolution modification
time, so an external same-size rewrite within that second can go undetected;
the acknowledgement is not byte-exact proof.

The experimental `galleries:create` source requires separately approved exact
scope and verified wide storage; existing grants and `pgu_` keys do not gain
creation rights.
The narrow owner refuses conflicting paths, canonical catalog drift and more
than 10,000 catalog rows including the proposed gallery. Slug search checks at
most 128 candidates; neither count limit is a time limit. A server claim and
client intent preserve ambiguous attempts for
reconciliation instead of replay; a confirmed local receipt remains until
explicit acknowledgement. Its local handle binds the receipt to the original
intent, so a stale UI selection or acknowledgement cannot consume a later
operation even when gallery fields match. The operation key is not displayed.
Real Windows behavior is unqualified. The client rechecks it with the server
before using
the created gallery as an upload target. The server checks current ID, title
and parent against that original receipt; a changed or unavailable reference
does not permit replay. New galleries retain normal/listed access and no
scheduled publication where those fields exist. Database commit does not roll back filesystem
writes, and local flushes do not establish power-loss durability. Actual
database, HTTPS and Windows behavior is unqualified, and no retirement of the
manual upload path is justified by this source.

Linked creation now requires the exact reservation migration before writing a
folder. A pending or malformed reservation temporarily blocks both covered
Admin in-place import workflows, discovery photo moves and unmanaged-folder
deletion, picture-manager copies, metadata-organizer moves, moving galleries to
Trash, Trash restoration, consistency repair and
browser consistency publication, ordinary Admin creation and
another linked create, regardless of the
target path, avoiding filesystem alias assumptions. This temporarily blocks
otherwise valid discovery disk actions even for unrelated folders; prevalence is
unknown. Completed reservations do
not block and their active path key is cleared in the gallery/receipt commit.
Picture-manager cleanup is skipped if it cannot verify writer-lease ownership;
metadata-organizer move failures retain new destinations for explicit review.
The ownership observation is not atomic with subsequent filesystem work.
An uncertain pending reservation is never automatically removed, including on
close of a client intent; manual recovery is not yet implemented. A caught failure
proven before the first filesystem attempt can atomically close the owned claim
and reservation after verified rollback and renewed authority; uncertain
ownership or any attempted mkdir keeps pending. The `pgu_` upload path remains
available. Confirmed missing reservation storage preserves legacy
Admin behavior only under the deployment assumption that reservation history
was not lost by an inconsistent restore; partial or unknown schema refuses.
This is not a filesystem fence: old runtimes, already running writers after a
lost DB lease, unguarded move/delete/restore, shell/FTP, shared storage with
independent databases and inconsistent restores are outside its guarantee.
The global query's `LIMIT 1` caps returned rows, not scan work or elapsed time;
completed reservation history and its query cost can grow without a timeout.

Before linked creation, the client verifies a fully written staging intent and
publishes it exclusively without overwriting a previous key. A crash may leave
an unused stage or stale profile lease; the latter can block later work. Real
Windows behavior and directory-entry persistence after power loss remain
unqualified; the change does not establish safe automatic retry or recovery.
The completed creation receipt is separately staged, flushed, read back and
published exclusively without replacing an earlier receipt. Identity and link
count are checked around publication and cleanup; uncertainty retains the
pending intent and any published receipt for explicit reconciliation. This
does not atomically exclude unrelated local writers or qualify Windows,
hardlink behavior or directory durability after power loss.

Photos now preflights this existing profile-wide journal before a new create.
A pending operation is reconciled read-only only with its original issuer and
Admin account. A completed operation can be acknowledged locally after an
explicit user action, including when it belongs to a different site. This
reuses the exact bound-receipt check and profile lease; the foreign gallery
never supplies current gallery or upload authority. The current session,
account and selected direct parent must still match before a new dispatch.
A same-site/account request with the same normalized title and parent checks
the completed result instead of sending a replacement. Corrupt data, failed
acknowledgement and an unknown server result remain retained. This recovery
keeps the journal format and server ledger unchanged and does not establish
safe automatic retry, installer migration or power-loss durability.

Explicit linked-create abandonment records a permanent key tombstone without
claiming no filesystem effects. An older runtime may report this new ledger
state as unknown but must not replay it. Existing upload claims and states are
unchanged; deployment and downgrade prevalence are unknown. Retain tombstones
for the life of the operation identity.

Visibility recovery preserves existing gallery move and deletion behavior:
same-filesystem moves can retain descendant symbolic links without following
them, and directory deletion can unlink them. The cross-filesystem Trash copy
already rejects such links. The v2 marker binds unchanged gallery columns and
the exact original/private/target sidecar hashes. Default operator inspection
is read-only; an exact current review fingerprint permits only eligible
sidecar reconciliation and marker retirement after synchronized readback,
without a database row or revision write. V1 markers remain inspectable only.
A disposable HTTPS PHP/MySQL 8.0.44 run observed public/private/unpublished
flows, fresh GET verification, stale revision and schedule refusal,
password/unlisted/NSFW preservation and interrupted recovery on both sides of
database commit. At exact candidate `8de8e50a`, BASIC run `42107055888` and
WinApp run `42107055797` passed and the client/server visibility gates are
enabled.
Each change requires explicit approval and fresh confirmation; stale revision
or schedule conflicts cause a read-only refresh without replay. Older servers
do not advertise the optional permission. The current CLI inspection and exact-
fingerprint recovery behavior is documented in
`docs/APP_LINKING_PHASE_0.md`. Installed GUI, MariaDB and deployed evidence
remain unknown. Unrelated filesystem writers and power-loss durability remain
outside the observed qualification.

The embedded Photos reader accepts a live `galleries:list` link for public
photo reads. The server verifies the registered scope, issuer, role, live grant
and public export policy. Direct non-NSFW photos require every ancestor to be
public, listed, normal-access and non-NSFW. Legacy `media:read` grants remain
valid, including on older servers that require that suffix. Older servers can
refuse photo reads from ordinary listing links; the Photos pane handles that
response inline. `presentation:read` remains explicit for settings and tracks.
Both paths reuse the existing schema and verified issuer transport. The thumbnail owner
uses a larger configured derivative or the existing public display rendition
when a deployment lacks the smallest thumbnail. It retains the same binary
limit and can serve as long as existing uploads use different rendition sizes.
Deployed usage of this fallback and mixed-version combinations is unknown.

The separately approved `media:admin` scope adds direct administrator media
reads when the live grant and current schema allow them. Public-only and
legacy listing grants keep their existing policy. The linking policy and
native media owners retain those paths for saved grants and older
installations. Restricted media uses transient delivery. The current
administrator role is required for every read and mutation. Creation and
visibility retain their explicit scope and authoritative server checks.
Deployment usage of administrator media and rolling grants is unknown.

The separate detailed Information selectors use the existing Admin Photos
scope. The workspace can show the gallery's direct inventory or the photo
descriptor from an older server when extended fields are unavailable. These
owners preserve mixed-version browsing and its current disclosure policy.
Contextual Watcher capture requires the verified temporary-key table. The
ordinary linked and classic Watcher transports remain supported on older
servers. Deployed metadata and contextual capture usage is unknown.

Photos gallery editing delegates to the existing browser Admin editor owner.
The native link carries only the exact installation and numeric gallery ID.
Current web servers consume the optional `open_panel=1` GET hint after normal
Admin authorization and open the existing drawer on the Galleries dashboard.
Older servers ignore the hint and retain their ordinary full-page editor.
Keep this credential-free handoff permanently while mixed web/client versions
are supported; it grants no web Admin session or extra native linking scope.
Deployed usage is unknown. Native URL/session fixtures and drawer navigation
fixtures cover the handoff; actual browser login and panel rendering require
separate desktop qualification.

The native cache keeps its legacy flat object format and public-media admission.
Explicit retained galleries use separate hashed directories in the same cache
owner. They are excluded from ordinary LRU entry/byte limits and its flat scan
budget. Unpinning applies normal quota policy; failed copy preparation retains
the pinned originals. Pinning an unfinished preload preserves its preference
and successfully prepared entries without claiming complete coverage. Retention
never relaxes live-link checks or the default no-store/restricted-media rules.
Local availability is checked from authorized descriptors; it is not offline
authentication. Usage of retained galleries is unknown. Cache/worker fixtures
own quota, restart, failure and cancellation regression; native UI acceptance
is separate from hosted Python qualification.

Ordinary thumbnail bytes now have a separate child pool and independent
configurable shared 4-GiB default/262,144-entry quota; full/detail media and audio retain the main
1-GiB/4,096-entry pool. The canonical process/file lock and gallery pin/private
preload markers remain shared. New retained thumbnail filenames carry a fixed
rendition prefix; legacy unprefixed files are classified only when an exact
authorized thumbnail key is known. Lazy migration retains body, digest and
validation age. Contains/read/revalidate preserve path and no-follow checks.
Unpin capacity planning reserves every destination across both pools and
verifies them together before retained cleanup, so later copies cannot evict
earlier copies from the same conversion. Copy failure retains originals;
cleanup failure keeps the existing marker and remaining retained files for
recovery. Disk layout compatibility remains supported while prior caches exist;
usage is unknown and no older cache file is guessed from its opaque hash.

Preload diagnostics now share the pure Photos formatter with the native viewer.
Listing, local-cache, transfer and verification failures retain safe source and
response facts, and terminal events retain the actual counts. Background
preload facts survive an album change only within their exact approved session.
The host receives queued safe messages without worker calls into Tk. Optional
diagnostic callback failure cannot change the operation's outcome. Cache-policy
refusals expose only finite header decisions and bounded numeric metadata;
raw headers remain excluded. This diagnostic extension preserves the existing
wire protocol, cache admission, authorization and on-disk format. Current
production diagnostics identified repeated Cache-Control fields before image
decoding; their exact values and installed-client acceptance still require a
manual observation.

Native media accepts equivalent Cache-Control directives across repeated or
split fields, with exact cache markers, authorization and Vary checks retained.
Ordinary photo HTTP/cache reads and explicit preload still require private,
must-revalidate and an explicit 60-second age.
Additional ages cannot be shorter than 60 seconds, and never extend the local
60-second freshness cap. Unknown or conflicting directives do not grant disk
storage; existing no-store responses remain transient. Audio normalizes only
its equivalent private/no-cache policy. This permanent interoperability path
supports heterogeneous HTTP response formatting rather than a hosting vendor.
It follows the first-origin-age option with a stricter local cap described in
[RFC 9111](https://www.rfc-editor.org/rfc/rfc9111.html#section-4.2.1).
Production source diagnostics subsequently identified conditional HTTP 304
responses without the required cache marker. The transport still rejects those
responses. When a saved entry caused the conditional read, the media owner may
make one unconditional authorized read, sharing the existing legacy no-store
retry budget. It never accepts the rejected response as authority for cached
bytes. The new response passes the unchanged complete validation; encoding,
authorization, throttling, other failures and cancellation do not enter this
recovery path. This is permanent interoperability support for incomplete
conditional responses, not a server-specific exception. Focused session
regressions cover the retry budget and refusal boundaries; installed recovery
remains subject to manual verification.

The Photos gallery catalogue retains its initial ten-page burst, then continues
one page every five seconds through the same cursor and single worker. This
client pacing keeps the initial tree responsive while leaving server request
headroom. No server limit or protocol changes. Session changes and destruction
cancel scheduled work; errors stop automatic continuation until explicit retry.
Catalogue fixtures own those timer and identity boundaries. Live production
catalogue size and traffic are unknown. The client first mounts an optional
last-complete local snapshot, bound to verified issuer, account and exact
approved scope. Windows uses user-scoped DPAPI and refuses redirected/nonlocal
profile storage; POSIX uses private owned files. Integrity failure, corruption
or unavailable storage is a miss. One background reader and a serialized
latest-pending writer keep this optional I/O outside Tk. Only complete results
replace the atomic snapshot; partial/failing pages never prune cached-only rows.
Cached and complete-tree UI updates yield in short batches and retain unchanged
rows. The account-scoped last explicit gallery preference reuses existing
versioned tree preferences; version 1 expansion states remain supported.
Cached navigation still performs independent live media authorization; new
upload and create actions require fresh gallery confirmation. Cached metadata
never grants media, upload or mutation access.
Source review of the reported new-gallery Watcher no-op found that `5406d8a0`
added fresh-row admission to the action while confirmed creation only inserted
the display row. The current-session create and recovered-status paths now
admit that exact confirmed row before rendering and selection. The action
retains freshness, current descriptor, live-session and upload-scope checks;
refusal stays visible in Photos instead of silently dismissing its context.
Cached-only rows and stale or expired creation results remain ineligible.
Native fixtures cover the creation-to-button transition and its negative
boundaries. Installed Windows and mixed-version deployment acceptance of this
repair remain unverified; no schema, server endpoint or credential format changes.
Focused Windows diagnosis also observed obsolete cache-probe Tcl callbacks after
workspace teardown. An immediate probe now cancels its pending delayed callback
before clearing that timer reference. A native lifecycle fixture checks removal
from Tk's pending queue. This observation does not establish the cause of a
separate whole-suite hosted timeout or installed prevalence.
An additive `galleries:tree-v1` server capability exposes a bounded complete
tree and an account/scope-bound revision on the existing listing route.
Current authority is rechecked before an unchanged envelope can omit rows.
There is no new consent scope, schema or persisted server revision. Missing,
oversized or unavailable optional trees leave the original page contract
available; a cached tree never grants remote access. The separate complete-tree
fixture controls external storage boundaries, while the existing gallery-detail
database fixture exercises the real authorized query and revocation. HTTP and
installed-client behavior require separate verification. The client negotiates
the exact capability before requesting the optional tree. Missing capability
or unsupported, malformed, oversized, unavailable or transport-failed tree data
falls back to the existing page reader over the prior snapshot. Authentication,
grant rejection, cancellation and rate limits never enter this fallback.
An unchanged reply is accepted only against the exact locally retained revision.
Safe fallback diagnostics retain the bounded operation/status/reason without
titles, URLs or tokens. This old-server path is permanent mixed-version support;
deployed adoption and current installed performance are unknown.
The catalogue activity label and smooth spinning ring appear above the tree and
share the existing initial/read/continuation lifecycle. They do not estimate
completion and stop on completion, failure, disconnection or destruction.

Ordinary Photos grid reads request thumbnail renditions only. Selected detail
and adjacent-photo reads belong to the open lightbox; explicit gallery preload
retains its independent thumbnail/detail path. Closing the lightbox prevents
new adjacent reads and clears queued speculation. An already running bounded
read may finish, with stale display results still fenced by selection identity.
The server's existing missing-thumbnail rendition fallback remains supported.
Direct thumbnail preparation preserves byte/pixel bounds, matte cropping and
LANCZOS sizing without an intermediate PNG encode/decode round trip. The
session request gate, authorization, cancellation and cache admission remain
unchanged. A bounded initial thumbnail burst precedes the optional background
revision probe; explicit photo/page requests and active audio retain priority.
Continuous viewport changes cannot indefinitely postpone this initial probe.
No additional network concurrency or process is introduced. Installed loading
latency remains unmeasured.

Live photo grids coalesce repeated viewport notifications and retain a bounded
adjacent-row band of Tk thumbnails. This reduces image allocation churn during
small scroll reversals without treating retained pixels as new access authority.
Visible thumbnails remain first and use the same media/cache/cancellation
owners; scrolling does not request detail photos. Refresh and teardown cancel
or invalidate pending display work and discard obsolete photo identities.
This is client-side support for existing media endpoints, including web 1.0.3,
with no new renderer, GPU extension, process pool or server capability.
Copied render counters distinguish event bursts, applied updates, image
allocations/releases, cache hits and callback duration. A bounded serial
thumbnail batch now publishes each ready raster without a GUI polling round
trip between items. Ready results and decoded-memory hits apply in bounded
GUI slices. Foreground work, viewport changes, refresh and close interrupt or
fence the remaining work; stale rasters are explicitly released. HTTP 429
still stops thumbnail admission until the existing explicit Retry action.
The session request gate remains the authentication/renewal/transport owner;
this change neither adds parallel network requests nor changes cache proofs.
Passive numeric observations separate disk, gate admission, renewal, media
transport, decode, queue wait and Tk application. Deterministic fixtures
measure scheduling and bounds; real affected-profile scroll latency and
visual artifacts still require manual qualification. Sampled frames from the
reported Windows recording show serial thumbnail arrival and pending tiles;
an original-resolution frame around 2.6 seconds also shows clipped duplicate
thumbnail strips inside the viewport, below their correct row. This establishes
a transient visual artifact in that recording, without identifying its
application, Windows or capture cause. Neither its post-change removal nor
application FPS has been verified.

The same existing web 1.0.3 routes now support an independent public disk-only
thumbnail lane and a byte-bound decoded LRU sized for wide viewports. Owner:
`native_gallery_viewer.py` and the existing `LinkingSession` cache authority.
Private Admin bytes remain in the live-revalidation path. A miss falls back to
the existing serialized reader because stale, absent or ineligible local bytes
cannot establish access; this is permanent older-web support, not a new cache
permission. Visible-first eviction and refusal of full-cache speculative
overscan preserve recent history within 16 MiB/four-byte-pixel accounting and
256 entries. Retained Tk images still cover only the admitted row band.
The session transport may reuse one fully consumed HTTP/1.1 media connection
and verifying SSLContext, retaining both serialization gates. Its 15-second idle
bound is an initial resource policy. One stale borrowed GET can reconnect only
before a response; incomplete/rejected bodies and mutations are not replayed.
Server closure, deadlines and cancellation retain the original close/failure
path, permanently supporting hosts without keep-alive. Socket transfer and pool
epochs prevent a late former cancellation owner from closing a successor.
Explicit thumbnail-only preload covers a direct gallery without recursion,
uses current public cache proofs, yields to foreground demand and respects
429/Retry-After. It does not redefine full preload completion or private access.
Explicit preload now processes thumbnails in a single cache/transport pass;
final verification reuses only exact same-operation file witnesses with safe
ancestry, key/pool, identity, size and mutation-time checks. Publication records
opened-file metadata through the same API as verification, preserving Windows
creation/change-time semantics rather than comparing unlike timestamp sources.
Failed writes and
no-store replies supply no witness. FIFO replaces the existing one-slot gates
without increasing transport concurrency. Nonurgent warm/disk queues no longer
keep foreground yielding active. Listing/checks/waits retain immediate activity
and cancellation; counters distinguish current hits, processed and confirmed
publications. Metadata and thumbnail phase/counter scopes remain separate.
The ordinary pool's SQLite accounting is a rebuildable physical inventory hint,
not access or integrity proof. Dirty intent commits before filesystem changes;
incomplete or changed directory generations recover on a bounded cancelable
worker before quota admission. An inaccessible/corrupt index remains unavailable
and cannot admit ordinary writes. Legacy media files migrate lazily without
deletion or freshness renewal. Unknown installed mixed-version usage requires
continued support; no older file is inferred from its opaque hash. Cooperating
processes use the existing lock and atomic replacements. Arbitrary external
in-place cache edits are outside accounting guarantees. The storage widget uses
physical ordinary files including headers, excludes pins/detail/audio and never
implies offline authorization. The 16-MiB/256-item decoded bound is unchanged.
`photos.info_width` adds a defaulted version-1 layout field; old JSON remains
supported indefinitely. Optional process RAM is Windows working set or macOS
RSS, excluding children; per-process VRAM is unsupported, never a measured zero.
Existing native regressions cover these controls and retain safe aggregate
fixture measurements. Real hosting reuse, installed latency, affected-profile
source build identity and transient Windows scroll repaint remain unknown until
an agreed live test; the isolated settled-render fixture alone cannot retire
the embedded-widget renderer or establish an artifact fix.

The control immediately left of Watcher status names thumbnails explicitly
and totals ordinary plus typed retained thumbnails with matching byte scope.
Its anchored detail separates the ordinary quota from Keep preloaded bytes.
Retained statistics
reuse bounded per-directory generation snapshots; a changing or unsafe
inventory remains unknown and never authorizes media. Current-process RAM is
Windows working set or macOS RSS, with no child/GPU attribution. Recent album
metadata can appear immediately from the same unexpired session and original
60-second observation window, while a fresh server read follows. No returned
rows are filtered by client visibility. This is display metadata, not permission
to bypass the binary owner. Equal refreshes replace proof timestamps instead
of retaining expired ages. Larger JPEG thumbnail fallbacks request a smaller
decoder raster before conversion, preserving original safety and tile bounds.
Installed latency, the changed header render and fresh runtime qualification
remain unverified. Existing unfiltered Admin access remains server-owned.

Legacy Watcher identity is retained only for a verified saved upload binding;
same-mode refresh does not erase the title. Credential and upload-mode changes
invalidate it, and Photos navigation cannot provide an upload identity. A
server title can be displayed without an available folder hierarchy. Neutral
current-session copy follows cached game detection while actual historical
flight artifacts keep their identity. Hiding simulator-only controls retains
saved recording/UTC preferences and does not alter worker admission.

Single-click Photos selection and the persistent information pane remain
presentation over the existing metadata owner. Context information uses the
same request helper, permission-filtered groups and value formatter. A bounded
same-session LRU has a one-minute lifetime and is invalidated after relevant
refreshes and mutations. One inspector worker retains only the latest pending
selection; late session/gallery/selection results cannot repaint current data.
Grid selection never requests the full photo. Double-click/Enter retains the
existing lightbox owner; local fullscreen bindings support both letter cases
while preserving text input and command shortcuts. Selection/range limits and
server mutation authorization remain unchanged.

Windows fullscreen shortcuts interpret the native Tk state independently of
X11: NumLock is Mod1 and ScrollLock is Mod3, while Alt has a separate logical
mask. Lock flags and a keyboard without a numeric keypad remain supported;
Control/Alt combinations and text entry remain reserved. A bounded, separate
shortcut trace exposes only fixed keys, state/focus categories and the handling
outcome. This platform mapping is permanent support, not a Tk upgrade
requirement. The actual user confirmed NumLock enabled on Tk 8.6.15; previous
generated events without lock bits did not cover that rejection.

The existing 36-million-pixel RGB allocation guard rejected camera originals
even when their encoded responses fit the media byte limit. JPEG/MPO detail
decoding now requests a reduced decoder raster before RGB allocation and
checks the same pixel budget on that raster. This decoder reduction is also
requested for ordinary JPEG/MPO sources above the existing display target but
below the allocation ceiling. The real 4096-by-3072 fixture observes the
smaller RGB input rather than inferring improvement from an output resize.
Other formats never gain an
unbounded full decode. A pixel-budget refusal alone may request one currently
authorized thumbnail preview through the existing media owner; no new server
capability is needed. Auth/cancellation/throttling/corrupt-content failures
retain their original refusal. Transfer limits, decompression-bomb protection,
render/cache bounds and selection fences remain. Typed decode facts retain
format, dimensions, byte count, stage and reason without exception text or
paths. Current production photo dimensions and affected usage are unknown
beyond the reported guard failure; manual qualification remains separate.
The initial authorized page no longer waits for optional presentation settings.
Its later serialized request follows foreground work and a bounded first
thumbnail turn. Errors in those optional settings leave photos available;
generation/cancellation still discard late results. This permanently supports
older or slow presentation providers through the same media owner, without a
new endpoint or grant. A green preload means verified encoded storage, not
decoded lightbox readiness. The reported MacBook behavior at `8146e1b1` remains
live performance evidence only; the changed shared paths need installed macOS
acceptance, including HDR, separately from Windows/Linux fixtures.

The Tk event dispatcher yields between complete FIFO handlers after a bounded
message count or cooperative elapsed-time budget. It never merges or discards
domain events, upload acknowledgements, revocation or target-generation checks.
One long handler can still exceed the budget. Recent-activity table redraws
share one pending callback while redaction, in-memory history and writer
submission retain their original order. Filter changes render current data;
shutdown cancels pending presentation work and deferred closure resumes one
dispatcher. No additional thread, process, wire protocol or persistence format
is introduced. Numeric diagnostics support later measurements; actual installed
latency and improvement remain unmeasured until manual qualification.

Explicit Admin preload extends the existing media owner with the additive
`media:admin-preload-v1` capability, descriptor fields and a fixed binary opt-in
header. It preserves older public validators and no-store browsing. Private
entries and saved opt-ins use a separate issuer/verified-account namespace;
private display accepts a fresh live-page proof bound to the current session
generation, account, exact target, opt-in and current non-NSFW eligibility.
Thumbnail reuse requires the exact strong preload ETag within its original
60-second proof window. Ordinary full HTTP reads and explicit preload require
a verified body digest and their own response age within 60 seconds; the
existing 200/304 path refreshes that age. A full viewer may separately request
an explicitly downloaded snapshot with `use_preloaded_snapshot=True`. It reuses
only the hash-verified file for the exact issuer, verified account, gallery and
photo while saved full-media intent and a live catalogue proof no older than
60 seconds remain valid. The saved body's HTTP age does not expire this
viewer-only use, and neither its stored time nor HTTP freshness metadata is
rewritten. When the metadata proof expires, the viewer renews the small photos
page for that photo group without fetching the full binary. Missing or corrupt
files return to the normal server read. Decoded detail stays bounded at 15
seconds. Renewal, closure, known grant rejection and explicit local opt-out
invalidate reuse authority. Remote revocation or NSFW changes after the last
page cannot be detected before a new authenticated observation; this bounded
window replaces the former per-binary private revalidation. Stale or missing
proof requires authenticated catalogue renewal before any saved-version display.
Complete non-NSFW ancestry and verified
schema are required; missing rows, cycles, unknown flags and authorization
changes fail closed. The viewer flag is off by default outside full detail
reads. This behavior uses the existing link, saved intent and API; it adds no
server API, grant, offline access or migration. The supported WinApp 1.0.4 and
web 1.0.3 do not require a new capability. Older
servers remain transient for restricted media permanently while mixed versions
are supported. Usage is unknown. Server/client fixtures cover persistence,
identity separation, revocation and refusal boundaries; real Windows private
preload acceptance remains separate from hosted qualification.

A supplied macOS source snapshot, reported against `51a74685`, observed 29
decoded-cache refusals, zero decoded bytes, 29 network-sourced tiles and 58 HTTP
requests, with 33.49 seconds of cumulative media transport and only 252.69 ms
of decode.
Both supplied reports contain the same Photos sample. The blanket public-only
reuse gate rejected Admin preloads despite their current catalogue proof;
these observations justify the generation-bound reuse path. They do not
measure the changed implementation or identify the exact headers that caused
the existing unconditional-refresh fallback. An absent optional presentation
(HTTP 404) is handled as unavailable settings rather than a failed photo grid.

A later supplied macOS sample reports seven cold-view HTTP requests and seven
local misses, zero decoded images and zero accepted network sources. The two
reports repeat that sample. Generic transport wrapping hid the binary refusal
stage; the exact hosting header mismatch remains unknown. Eligible interactive
Admin reads now negotiate the same advertised verified binary protocol used by
preload, independently from the explicit opt-in still required for local full
private-media persistence. This supports first viewing on capable servers without
manual preload; older or ineligible descriptors retain the restricted lane.
Length, framing, validators and body integrity retain the same acceptance rules;
refusals now preserve a fixed stage without header values or response content.

Background cache/preload page reads can supersede a mounted descriptor's proof.
The same account/photo/strong validator may use a newer real ledger observation,
with freshness measured only from that observation and its epoch. Caller ages
never extend it; removed cursor-range rows are invalidated. Explicit full
preload now revalidates stale stored full responses rather than skipping them
after physical verification alone. Foreground detail/page work precedes refresh,
superseded image operations cancel, and adjacent lightbox preparation precedes
grid thumbnails. Cancelled gate tickets withdraw without waiting for the current
owner. Both preload modes honor foreground demand before listing pages and
image reads. One network lane and existing freshness/byte bounds remain. The user
confirmed fast cached thumbnails before these follow-ups; their changed cold
load, lightbox latency and cancellation behavior lack new runtime evidence.

Viewed eligible Admin thumbnails now persist automatically under their verified
issuer/account key in the ordinary bounded thumbnail pool. Fresh page proofs
are registered without a saved full-media opt-in; the ledger records that
independent opt-in separately so thumbnail reuse cannot authorize full media.
New writes still require the server's cacheable admin-v1 response and digest;
no-store/NSFW-ineligible responses remain transient. With no account opt-in,
read/write use no gallery retention context, so another account's gallery pin
cannot bypass the ordinary quota. Full private photos keep their saved-intent
requirement. Their ordinary HTTP and preload reads retain the existing body-age
limit; full-viewer snapshots use the separate fresh-proof policy above. Missing
or corrupt files still fall back to the server path.
The user observed zero stored photos throughout transfer followed by 29 at the
end. Full preload now publishes cache-confirmed complete-photo counts during
transfer, followed by final reconciliation. Storage mutation events coalesce
into the existing observer and can publish ordinary totals before retained/RAM
work finishes. The user also reported very slow full-image navigation; selecting
the image already being prefetched now adopts that result instead of cancelling
and repeating it. Separate numeric detail/prefetch source and byte counters
distinguish cached reads, 304 responses and body downloads without identities.
The user later reported that only two photos felt fast while the rest remained
slow. This is a reported symptom, not a controlled measurement; it does not
establish how broadly the viewer snapshot path improves navigation. macOS
acceptance of catalogue renewal followed by hash-verified full-body reuse
without another binary request remains unverified.
Changed automatic persistence, live counts and full-image latency still need
installed macOS acceptance; no fresh runtime qualification is claimed.

Explicit Admin Photos approval can upgrade the same previously saved rolling
link. The native linking and vault owners compare the current binding and
replace it only after identity verification. Saved public grants still open
with their approved permissions until an upgrade. Older servers keep their
existing linking capabilities. Mixed-version deployment usage is unknown.

Native photo deletion uses the saved `media:admin` Admin Photos grant.
The server rechecks the exact live grant, issuer, Admin role and complete
target batch under the writer lease. The historical `media:delete` bundle
remains accepted for already-issued grants. The native and browser owners retain the existing
four-field visibility and media forms for older clients.
Older servers support the manual Photos refresh. Automatic refresh requires
the Admin revision selector. Deployment usage and installed regression
evidence for both features are unknown.

When the selected detail asset exceeds 16 MiB, the media owner may serve an
already-generated display WebP or JPG under the same validations and size cap.
The fallback does not generate files. If no eligible rendition exists, detail
remains unavailable. A read-only MAMP service probe opened 140 of 141 eligible
photos. Twelve of the 13 oversized assets opened via derivatives that preserved
their aspect ratio. The remaining 48 MP JPEG had no eligible rendition.
Authenticated HTTP verification is pending.

The default-on Subgalleries switch displays direct child folder cards above
the selected gallery's photos. A cover is the first authorized direct
thumbnail, with a neutral folder fallback. Photos keeps listings in memory
and uses the current link grant. The native client may prefetch detail pixels
into bounded memory. The photo grid and default lightbox fit trim detected
black bands for display while preserving the scene's aspect ratio. The
original-frame toggle restores the full source in the lightbox. The wheel
and trackpad zoom in either view. F and F11 toggle fullscreen, with controls
appearing briefly on pointer movement.
The native Tk input owner uses packed TouchpadScroll axes where available.
Legacy MouseWheel events and X11 wheel buttons remain supported for Tk
builds without precise packets. Installed host and device usage is unknown.

On macOS 15 or later, the native lightbox can retain bounded encoded detail
bytes and decode compatible HDR data through Core Image. The AppKit owner
requires a Tk build with a native window bridge, PyObjC and an EDR-capable
display. The Pillow/Tk fallback preserves the photo viewer on Windows, older
macOS and Tk combinations, SDR screens and sources without usable HDR data.
Older and derived renditions may only contain SDR. Both rendering paths
remain supported for those inputs and environments. Deployed HDR usage is
unknown.

WinApp requests up to 64 photo descriptors with
`X-PHP-Gallery-Photo-Page: 64`. Servers that ignore the header retain their
eight-photo pages and cursors. Requests without the header retain that same
eight-photo contract.

The per-user disk cache keeps opted-in photo, thumbnail and audio bytes under
hash-only keys. Photos retain their existing issuer, gallery, image and
rendition keys and file format. They use `X-PHP-Gallery-Photo-Cache: 1`,
`Cache-Control: private, max-age=60, must-revalidate`, and
`Vary: Authorization, X-PHP-Gallery-Photo-Cache`. After 60 seconds, reuse
requires authorized revalidation. A matching strong validator refreshes the
file's validation time without rewriting its body. An opted-in current
catalogue page may supply the strong ETag of an independently public bounded
thumbnail. A matching disk entry can be reused immediately. The bounded
decoded thumbnail cache belongs to the live Photos session and follows the
current catalogue's validation. A legacy no-store 304
causes one unconditional fetch. A refusal yields no stale body.

Audio persistence requires `X-PHP-Gallery-Track-Cache: 1`,
`Cache-Control: private, no-cache`, and
`Vary: Authorization, X-PHP-Gallery-Track-Cache` on a complete authorized
response. Before using disk, WinApp fetches the current permitted playlist,
requires its explicit `track_cache: true` capability, and checks that the track still
matches its verified SHA-256, byte size and MIME type. Older playlist responses
without the capability use online track delivery even after a downgrade.
The main detail/audio cache holds up to 4,096 entries and 1 GiB. The independent
ordinary thumbnail pool holds 262,144 entries with a configurable shared
4 GiB default, so main-pool pressure
does not evict thumbnails. Each pool evicts its own least recently accessed
entries and retains no credentials or playlist metadata. WinApp's media
transport owns the older no-store and
eight-photo behavior while mixed versions are supported. Deployment usage is
unknown.

The previous separate-reader release was checked with disposable HTTPS and
hosted Windows Tk at `1844648a`. Native audio was skipped because the hosted
runner had no output device. Installed Windows and deployment evidence for the
embedded workspace remain separate.

Native photo descriptors can add a coordinate pair only when the selected
gallery's effective EXIF map policy is enabled and GPS schema is available.
This follows the existing public map disclosure policy; no paths or altitude
are exposed. Disabled maps, missing coordinates, or missing/unknown GPS schema
preserve the old descriptor shape. New clients accept old servers that omit
coordinates, and old clients tolerate the extra fields. Deployed use of this
optional metadata is unknown.

Separately approved `gallery:read` permits a selected gallery's ID, title,
revision and stored visibility, access mode and listing mode to be read.
It does not report inherited access or change older grants. An older server
may lack this capability; the client reports it unsupported or unavailable
without silently reducing the requested scope. The saved `pgu_` upload path
remains separate. A read that has passed its live grant and role query may
finish after concurrent revocation. Actual Windows, HTTPS and database
qualification for this increment remains outstanding.

Existing explicit app-linking issuer and secret overrides remain supported.
Removing the issuer override can change the installation binding for current
grants. Removing the secret override changes the pending request and rate-limit
key unless the same bytes are otherwise provisioned. Keep both during normal
upgrades. Installation and update provision a database key for installations
without an override. Legacy override usage and deployed migration coverage are
unknown. The ordinary `pgu_` upload workflow retains its separate credentials
and authorization. Its disposable workflow regression remains the compatibility
owner.

The scope-capacity migration widens four app-linking scope columns from exact
64-byte to 255-byte binary storage without changing grants. Current short-scope
readiness accepts either verified width, including a partially completed
migration; unknown or unexpected metadata refuses the affected operation.
Separate wide-capacity readiness requires all four widened columns and grants
no new permissions. DDL can stop between tables and is replayable, not atomic.
Older runtime code that insists on exactly 64 bytes can fail closed after the
schema widens; automatic narrowing is unsafe and unsupported. Actual upgrade,
downgrade and mixed-database-version evidence remain unknown.
The callback limits each metadata-lock wait to at most five seconds, preserves
stricter existing values and verifies restoration when it reduces the original
session value. This is not a total DDL
deadline. Its advisory lock ends before the migration runner writes the
version checkpoint, so it does not itself serialize concurrent checkpoint
writers.

The optional offline scope issues rotating refresh families only with explicit
browser consent; legacy grants gain no new scope. When explicitly selected and
Windows secure storage is available, the client stores a refresh credential in
the current user's Credential Manager and keeps only its issuer, account ID and
approved scope in a separate non-secret binding file. Reconnection is a manual
action: a cross-process lock and pending fence protect the single rotation,
current identity verification and replacement write. An uncertain response or
storage failure blocks reuse rather than retrying the old refresh. Explicit
local forget removes the saved credential and binding, without revoking the
server grant or necessarily ending the current session. A new browser approval
may be needed; the legacy `pgu_` configuration is unchanged. The Windows owner
and its crash fence have not been qualified on Windows or after power loss.
In the linked upload
source, target-bound local history and a per-target exclusive lock
prevent reuse of unrelated legacy history; uncertain transfers remain pending
for manual review, without automatic replay or source deletion. A validated
live reply can replace the write-ahead uncertainty only for the same positive
attempt, unchanged source identity and hash, and the exact prior uncertain
state with no prior receipt; the reply must confirm one uploaded image, report
a nonnegative scan count, and carry a positive image ID after the server's
gallery and `fresh_insert_v1` checks. Unknown or lost replies, hashes or
inventory never clear the marker. This does not permit retry or deletion and
does not establish exactly-once behavior. Linked flight
data stays local and cannot implicitly use a saved legacy key for AI or PDF
upload. The new bounded server recovery probe supplies positive file-byte
evidence only: `present` does not prove registration, and `unknown` is not
absence or permission to retry. The client does not yet use that response.
Application leases do not atomically exclude external filesystem changes, and
the scan checks its time budget only between calls, not during a blocked call.
Filesystem, proxy, mixed-version and real Windows behavior remain
unverified and must not be inferred from source compatibility alone.
Source review found that a genuine current linked-upload success was rejected
when its durable row still held the write-ahead uncertainty. The validator now
allows only the constrained same-attempt transition described above. Installed
native-client prevalence, behavior of older or mixed-version clients, and real
Windows/deployed server use remain unknown.

## Gallery root filesystem aliases

`gallery_filesystem_path_inside_root` keeps configured gallery roots usable
when the operating system resolves an alias, such as macOS `/var` to
`/private/var`. For a missing leaf, it checks the resolved existing parent
against the resolved root; unresolved existing entries and parents outside
the root are refused. This supports existing configurations permanently;
the number using aliased roots is unknown. These observations do not prevent
another process from changing a filesystem ancestor afterward. The
`image_path_symlink_containment_test.php` fixture covers aliases and escape
attempts where symlink creation is available; hosted and live-platform
results must be reported separately.

The Admin tag sidecar repair keeps following symbolic links to existing files
inside the configured gallery root. Targets outside that root, or targets whose
containment cannot be verified, interrupt the repair before its completion
checkpoint. This repair still reads the legacy `gallery_path` configuration
key, while the documented example and operational defaults use `galleries_root`.
When the legacy key is absent, the repair can return without scanning and the
Admin controller can still record its completion checkpoint. The number of
affected installations, their existing checkpoints and usage of the legacy key
are unknown. Do not silently substitute the current root: that could initiate
new bulk writes. A separate transition needs usage and checkpoint evidence,
an explicit repair plan with bounded marker-aware preflight under the shared
writer lease, safe handling of supported links and a retryable completion
checkpoint. No retirement of the legacy path is justified yet. The repair
rechecks target identity and original bytes on a non-truncating write handle.
Unlike the former unrestricted writer, it refuses sidecars with multiple hard
links or an unavailable positive inode identity; in-root symbolic links to a
single-link file remain supported. Prevalence of hard-linked sidecars and
storage without usable inode identities is unknown; retain this safety boundary
until evidence and an equally safe compatibility method justify changing it.
It does not atomically exclude concurrent external filesystem changes, including
edits to the same inode after the comparison. An error or interruption after
truncation can leave partial bytes; decoding and re-encoding the entire JSON
document can change formatting and numeric representation. It does not provide
visibility-marker recovery.

## WinApp native text and display scaling

Desktop view preferences are independent of upload state. Geometry saves retain
the last trusted Photos tree width while the pane is unmapped or restoring;
the temporary widget default is not a user preference. The pane keeps its own
validated width through delayed startup and remapping. Invalid display geometry
falls back to current bounded placement without touching credentials or queues.

Linking transport admission waits within the caller's original total deadline
for the existing single worker slot. Cancellation and blocked DNS still retain
that slot until the actual network worker ends; admission never creates a
replacement worker behind a canceled caller. This permanent compatibility path
prevents transient gallery-switch failures while retaining the existing network
resource bound. Native Windows logs identified the formerly immediate busy
refusal before any media request; delayed DNS/cancellation regressions cover the
handoff and once-only cleanup.

The shared theme selects an installed UI family rather than installing fonts.
Windows prefers Segoe UI Variable Text, then Segoe UI; a native Tk family remains
available on other platforms. Windows text retains its native GDI smoothing
policy. Supersampled control/cache/recording artwork avoids emoji substitution;
Pillow-free source launches retain usable glyph controls. The theme owns these
permanent fallbacks because dependencies and installed fonts vary across source
and packaged runtimes. Installed prevalence is unknown.

The Windows display bootstrap requests system awareness only for an unaware
process before creating Tk windows. An existing manifest/host context is never
replaced. Native API refusal keeps the original toolkit path usable. System
awareness deliberately matches Tk 8.6 startup scaling; automatic layout reflow
between differently scaled monitors is not claimed. Native display/ClearType
appearance, fractional scaling and monitor moves need manual qualification.
No OS display or smoothing preferences are changed.

Contextual `GlassCard` surfaces opt into native child-window clipping on
Windows. This removes the fixed dark matte from their rounded exterior while
preserving ordinary cards and the existing inline focus/dismissal owners.
The theme permanently supports a flat opaque contextual fallback when native
region APIs are unavailable or refuse a shape. A resize fallback clears a
previous native region before returning to rectangular rendering. This path
keeps controls usable without introducing transparent-image rendering or a
new dependency. Supported-platform prevalence is unknown; retirement requires
equivalent corner, resize, ownership and fallback coverage on those platforms.

## WinApp update process exit

The updater permanently retains verified downloads, installed-path validation,
helper readiness and post-install version verification. Active Watcher work now
pauses between acknowledged photo operations and flushes the original recorder
before helper launch. A bounded 120-second wait postpones an unresolved update;
the game-presence observer remains independent. The profile-bound one-use
checkpoint preserves target, exact scope, session and the original file
boundary. A worker admission barrier prevents upload before durable checkpoint
consumption. Stop/Quit, different targets, changed scopes, invalid evidence and
uncertain uploads never gain automatic continuation authority.

Old helpers did not record running intent or pass a nonce. Their permanent
compatibility path validates the actual installed version transition, original
installer bytes and fresh helper markers, then offers explicit **Resume
Watcher** only for an unambiguous API session without an interrupted accepted
upload. Those old records cannot establish automatic resume consent. Existing
credentials and files are retained; Abandon is not the continuation path.
Installed upgrade acceptance and rollback/power-loss behavior remain separate
from hosted controller, recorder, queue and helper fixtures.

Updater diagnostic records preserve bounded transport causes and owned source
sites before the worker-to-Tk boundary reduces failures to a concise status.
Cleanup warnings retain the original update failure and never grant authority
to delete unrelated bytes. Fixed fields exclude credential-bearing URLs,
response bodies, arbitrary exception messages and local paths. The updater
retains its last failure for copied diagnostics after later successful work;
this support boundary does not change trusted hosts, integrity checks,
checkpoint admission, continuation consent or publication policy.

## Consistency evidence and imported disk names

Consistency inspection permanently retains explicit unknown/manual findings for
unavailable/refused decoders, ambiguous legacy derivative ownership and content
similarity. A path-derived naming hash is not content provenance. The RGB review
threshold is heuristic; production false-positive/negative rates are unknown.
It never authorizes automatic replacement or orphan deletion. Supported legacy
JPG caches remain recognized even when current generation policy prefers WebP.

Import now rejects conflicting sidecar naming authority, retaining readable
titles only when their sanitized identity agrees with disk. Newly imported
physical leaves are canonicalized through the existing guarded subtree mover;
occupied destinations refuse the import. Existing unrelated legacy discrepancies
remain until explicit consistency repair. Regression evidence is owned by the
consistency model fixture and disposable HTTP import/repair journey; source
implementation does not establish production data health or every codec.
Completed successful repair checkpoints retain a reusable remaining-finding
list. Older completed jobs adopt the same behavior from their server-owned
queue; no fresh photo/disk scan is required. Only confirmed task findings are
removed; manual, unselected and failed work remains. Exported receipts persist.
New batch admission requires the current sequence to prevent delayed replay.
Catalog/source leases still refuse external changes; interruption remains
explicit uncertainty rather than automatic write replay.
Consistency thumbnail repairs permanently require the configured browser
pipeline and supported browser decoders. Missing capabilities or unsupported
RAW/HEIC sources stay explicit failures; no automatic server generation is
permitted. Existing System tools retain their explicit server/browser choice.
Ticket ownership, source checksum, upload acknowledgements and persisted-file
verification protect resume after uncertainty. Hosted HTTP/Node fixtures own
regression evidence; codec prevalence and production throughput remain unknown.
Shared updater error reduction remains available through the lightweight job
reader and native legacy worker includes. References, safe messages, exception
identity and retry classification are unchanged. Only explicit installer owners
acquire the full worker; optional subsystem diagnostics retain unknown values
when that owner is absent. Runtime-plan and discovery-budget regressions protect
both lazy public reads and complete Admin/background loading.

## WinApp gallery title metadata

The authenticated upload inventory response exposes additive `gallery_title`
metadata independently of `gallery_folder`. WinApp keeps the physical identity
for existing consumers and uses the title for presentation. CMS title saves
derive the physical leaf with the existing slug sanitizer and move the subtree;
the root title and path map commit together. Creation uses the same derivation
and rejects collisions rather than allocating a different suffixed identity.
The connection
test and import preflight refresh both values. `GalleryIdentityLabel` retains
the folder leaf when older servers omit the title; installed server-version
usage is unknown, so this compatibility fallback remains supported. A renamed
human-readable title requires the updated inventory API. No schema or access
policy
changes, public-page scraping or additional network requests are introduced.

## WinApp optional game context

`game_presence.py` owns label selection: SimConnect first, then offline exact
process rules restricted by `windows_game_processes.py` to the current account's
SID and existing limited query rights. Missing/denied APIs, catalogs or
identities,
unknown games and ambiguous matches permanently retain general game wording;
they never gate watching, recording, reports or key handling. The bundled
Discord snapshot is incomplete; profile JSON additions require no app rebuild.
These fallbacks support ordinary games and PCs without SimConnect. No usage
evidence justifies retirement. Hosted fixtures own deterministic behavior;
the maintainer reported Cyberpunk detection during watcher operation on
2026-10-29, while exact game build/account rights and deployment prevalence
remain unknown. SimBrief controls follow cached flight detection without
unloading plans, and local abandonment uses matching game/flight vocabulary.
Live simulator transitions and the revised control layout remain manual checks.

## Gallery photo-card format and Display transport compatibility

The physical-gallery photo-card format defaults to the existing responsive
layout (4:3 desktop, square narrow cards). `gallery_grid.php` owns this
permanently supported default: unset or malformed stored/imported metadata
must not change existing galleries. Explicit selections affect photo previews
only; originals, lightbox and cover cards retain their existing behavior.
Both responsive and progressive picture structures consume the same crop slot.
gallery.json/Trash preserve explicit formats and legacy snapshots use default.
Display switches keep the native full-form fallback without JavaScript;
enhanced writes use revision ownership, canonical completion and no automatic
retry after uncertainty. Usage across deployed galleries is unknown. Hosted
format/editor/browser/HTTP regressions own future evidence; BASIC is source
feedback only, and desktop/narrow visual confirmation remains a separate check.
The compact editor owns native disclosure support when the browser lacks the
Popover API. Keep that fallback for ordinary help access; no browser-usage
evidence currently justifies retiring it.

## Lightbox content fit and original-frame support

Owners: `lightbox.js` and `lightbox-zoom-model.js`. Content fit is a
browser-only
enhancement of the existing viewer. Missing Canvas support, denied/tainted pixel
reads and differently framed derivatives permanently use the full frame.
Uncertain edges keep their extent while independently proven borders can fit.
No CORS/access policy or protected-media URL is
changed. Downloads, thumbnails, stored originals and no-JavaScript links retain
their existing behavior. This capability fallback has no planned retirement;
browser/device usage is unknown.

The detector uses at most 256 by 256 sampled pixels (256 KiB RGBA), three-line
minimum bands, at most 30% trim per edge and at least 40% retained per axis.
Each line requires opaque channels at most 20, mean at most 12 and standard
deviation at most 6. Two content lines need at least 35% pixels at level 40 or
above. Matching opposite bands (within two sampled pixels) permit darker
transitions even when neither edge passes that strict test: a dim transition
needs at least 15% pixels at level 24 or above and a level range of at least 12
on each of four lines. The range requirement
retains flat dark fades. With matching bands, at least one edge must pass one
of those transition tests independently. It may corroborate an opposite flat
or sparsely lit edge only when its mean rises by at least 12 byte levels above
the immediately adjacent matte line on each of four content lines. This step
starts at the boundary without the two-line ramp allowance. Two unqualified
mean steps cannot corroborate each other; uniform dim frames, gradual dark
boundaries and unmatched dim edges retain their existing protection.
Up to two dim boundary lines may precede a strict or dim transition window;
those lines remain inside the crop. Decisions
are per edge, so an uncertain natural dark side does not reject valid top/bottom
bands. Text remains visible; an uncertain caption edge can remain in full while
the opposite matte fits. A genuinely black
photographic background may still resemble a matte. The original-frame toggle
remains available even when no matte is found. It retains the session selection
across navigation and resets zoom on a deliberate mode change.

An incomplete axis additionally probes opaque bands with mean at most 4,
retaining the ordinary channel ceiling 20 and deviation limit 6. Matching
independently measured widths (within two sampled pixels) are only a symmetry
hint: each cut must raise the mean at least six levels
above its adjacent exterior line on each of four content lines. At least one
cut must also show 15% pixels at level 12 or above and a range of at least 12
on all four lines, starting at the same boundary without a ramp. This stricter
exterior model localizes the black-to-near-black step that the ordinary band
scan can absorb. Keeping the channel/noise limits while tightening the mean
tolerates isolated compression specks at a blended boundary. No missing or
unequal opposite band is invented, two uniform dim edges cannot corroborate
each other, and the ordinary asymmetric path
remains supported. Sampling, cache and trim limits are unchanged.

When that stricter pair fails and its widths differ, one shared candidate may
retry at the larger measured width. The existing two-pixel tolerance bounds
the refinement; each original band scan must already admit every additional
line under the unchanged opacity, channel, mean and deviation limits. Both
four-line immediate mean steps and the spatial-texture requirement are then
rechecked at the new cut, with no ramp allowance. A gradual brightness ramp,
an excessive width difference or a nonblack intermediate line cannot authorize
this refinement. Independently proven asymmetric edges remain supported.

Regression sources cover synthetic noise, dark/black/transparent frames,
asymmetric/four-sided mattes and subtitle contamination, with a browser JPEG
fixture for fitting/override/zoom/fullscreen/cache and pixel refusal. The
reported
concert screenshot originally failed because the lower content edge had about
20% bright pixels, a downsampled mixed boundary line, and dim side gradients.
The corrected detector was inspected on that exact authorized image in normal,
2560x1080 fullscreen and a 390x844 viewport: both horizontal bands fit, full
image width and natural side gradients remain, and analysis was about 1.8 ms on
the authoring machine. Synthetic
desktop preview observed about 1.9--3.7 ms for the initial sample on the
authoring
machine; this is not a low-end-device or whole-slideshow guarantee. Real mobile
performance, broader film/game corpora and assistive
technology acceptance remain manual coverage. Cache retains at most 64
rectangles, no pixels, and is discarded at setup teardown or page reload.

The reported Mass Effect screenshots 0078/0079 exposed matching black pillars
beside dim content: neither edge of 0078 passed the bright test, while only
the right edge of 0079 did. The paired rule now recognizes both boundaries
without requiring an independently bright side. The Node/JPEG corpus covers
both dim edges, a bright/dim pair, both axes, unequal dim bands and unchanged
uniform dark frames; the actual public image samples remain local diagnostic
inputs rather than shipped photographs. Broader game-scene false positives
and real-phone performance remain unknown; original-frame support is permanent.
Local production-viewer diagnosis fitted both reported samples to 16:9 with
both pillars removed at desktop and 390x844 sizes. Initial canvas analysis was
about 8--21 ms on the authoring machine, including raster sampling; this is not
a low-end-device guarantee. Simbrieftest navigation and fullscreen slideshow
were inspected with its soundtrack explicitly disabled. The compressed fixture
passed at both sizes, including crop/override/cache/zoom and both-layer fades.

The additional Mass Effect samples 0003/0004 expose two distinct transition
limits: the first has too few level-24 pixels on one content edge for all four
lines; the second meets a nearly flat dim wall whose range is below 12.
The paired mean-step rule addresses those edges using independently qualified
content beside the opposite matte. Synthetic vectors cover both axes and
orientations, retained gradual boundaries and unqualified flat pairs; compressed
browser patterns cover sparse illumination and a flat wall. Actual screenshots
remain local diagnostic inputs. Broader dark-scene false positives and
real-phone
performance remain unknown; the original-frame override remains permanent.
Local Chromium inspection of their authorized 1600-pixel previews with the
worktree viewer fitted both to the exact horizontal interval [0.125, 0.875]
with full height at 1440x900 and 390x844. Rendered image identities and
screenshots confirmed both scenes and subtitles remain visible. The inspection
replayed captured production HTML because the local server had begun returning
503; it does not establish live-installation or hosted FULL qualification.

Samples 0051--0053 expose the remaining near-black cases: a starfield whose
dark edge has too little mean rise for the ordinary rule, a scene whose left
content is partly absorbed by the ordinary scan, and a mostly flat near-black
edge opposite a sparsely lit textured edge. The stricter symmetric-pair path
requires measured black bands, immediate steps on both sides and persistent
texture on at least one. Synthetic regressions cover both axes/orientations,
compression-like noise, absent/unequal bands, uniform fades, isolated points
and gradual boundaries; browser JPEG patterns cover the starfield and dark
interior. Real screenshots remain local diagnostic inputs. Broader dark-scene
false positives and real-phone performance remain unknown.
Live Chromium inspection fitted all three reported previews and originals to
[0.125, 0.875] horizontally with full height. At 1440x900 and 390x844, all three
retained their scene and subtitles through original-frame override, full-source
zoom and fullscreen. The prior 0003/0004 cases still fitted both pillars.
Initial
analysis was about 9--13 ms on the authoring machine; this does not establish
low-end-device performance or hosted FULL qualification. A captured production
Canvas sample also exposed a level-9 compression speck on the outer boundary;
the stricter mean preserves the real cut while retaining the channel/noise caps.

The public and local 0059 pages supplied byte-identical originals and detector
modules but different 1600-pixel WebP previews. At the public sampled exterior
column, mean 5.49 exceeded the stricter mean limit 4; the local mean was 1.87.
The public stricter scan stopped one line early, so its immediate six-level
step failed and only the independently bright right edge fitted. The shared-cut
retry uses the ordinary scan's already verified black line and rechecks the
true content transition. Both observed previews remain local diagnostic inputs;
synthetic vectors cover their means on both axes/orientations, one/two blended
lines, excessive depth, nonblack contamination and gradual transitions. The
browser corpus additionally uses two lossy WebP variants of a synthetic scene.
Broader encoder/device coverage remains unknown; original-frame support and
the existing sample/cache/trim budgets remain permanent.
Local Chromium inspection with the candidate model returned [0.125, 0.875]
and full height for both actual previews and their identical original, at
1440x900 and 390x844. Original-frame override, full-source zoom and fullscreen
retained the measured scene. The public page used a temporary browser-only
model substitution; production files were unchanged. Real-device and broader
encoder acceptance remain manual coverage, not proof from these observations.

Manual and slideshow transitions retain outgoing crop geometry during loading.
Target geometry is prepared without a visible full-frame intermediate. The
incoming cover overrides global image maxima, which otherwise shrank its
full-source box in fullscreen and misaligned the crop. Both layers now fade,
so exposed old content outside a differently shaped incoming image does not
disappear abruptly. Cancellation restores opacity only for its owning cover;
viewport/map/fullscreen changes and final decode remeasure the target.
Complementary fades now use `plus-lighter` on the incoming cover. Normal
source-over alpha compositing attenuated the outgoing image twice, producing a
25% brightness dip at equal half opacity. Additive light preserves the intended
weighted blend while retaining the exposed-edge fade for different geometries.
The cover now belongs to the transparent, isolated figure containing the live
stage. Isolating only the outer overlay included its background in the additive
blend: an actual local slideshow's opaque cover was about 5 sRGB levels brighter
than the stable image at identical geometry. Removing it caused a second pulse.
The local 37-to-38 sequence was captured before and after this correction with
an empty soundtrack. Its mean end-of-fade color difference fell from about
5 levels to below 0.3 levels; image resampling still differs at individual
edges.
The existing Chromium owner also compares screenshot patches before/after real
automatic cropped-to-original and original-to-cropped handoffs at 1440x900 and
390x844. Its decode acknowledgement gate holds the fully opaque cover for a
capture, then releases the unchanged production commit. The one-level channel
tolerance admits raster rounding without admitting the observed background jump.
The decorated manual 3D carousel permanently retains the outer cover: its
perspective changes fixed-position coordinates and its raised stage would hide
an inner cover. The shared lightbox owner keeps that existing presentation path;
slideshow disables these decorations and uses the isolated figure in every mode.
The CSS lightbox owner permanently retains ordinary opacity compositing in
engines without this blend mode; there is no browser usage evidence authorizing
removal of that fallback. The content-fit browser fixture checks the real
computed blend mode and transparent isolation during manual and slideshow
transitions. Broader GPU and
real-device brightness acceptance remains manual coverage.
The actual local 6-to-7 transition was inspected with the soundtrack explicitly
disabled: outgoing/incoming opacity was about 0.795/0.205 during the blend.
The focused fixture passed manual and automatic transitions, including a narrow
viewport change during the fade. Real-phone performance and broader geometry
corpora remain manual coverage; these local observations do not qualify FULL.

## Retained modal isolation during slideshow

Owner: `public/assets/gallery-modules/public-modal-lifecycle.js`. Automatic
navigation and transition child-list mutations previously restored every
covered branch's original `inert` state and immediately reapplied isolation.
The browser fixture observed 38 property writes and 38 attribute mutations on
one retained gallery branch. The owner now reconciles the desired sibling set:
unchanged branches retain isolation without writes, removed/moved branches are
restored before new baselines are captured, and new branches are isolated.
Pre-existing inert state and nested editor/map focus remain supported.

The user observed underlying gallery cards flashing in Chrome native S mode
with cropping both enabled and disabled. Discrete internal-browser screenshots
of live 0.148.4 showed an opaque full-viewport viewer and native backing, but
could miss the short display artifact; they do not disprove that observation
or establish a Chrome compositor defect. Removing the demonstrated background
state churn is independently verified; broader display/GPU acceptance remains
manual coverage. Native `::backdrop` now explicitly matches the `#050505` shell.
The first supplied 60 fps recording was subsequently identified as live
0.148.4, not the local candidate. Frame 252 at 4.200 seconds showed one frame of
the page header over the fullscreen photo, before the first photo crossfade.
On 2026-10-30 the user then tested the corrected localhost and reported no
gallery-page flash, supplying a separate local recording. This is local
acceptance evidence, not proof of a particular browser compositor cause or of
the new patch on every live browser/GPU combination.
Touch swipe styling also retains the incoming opacity transition instead of
replacing it with transform-only timing. Existing CSS fullscreen fallback and
image access, decoding, crop and cancellation owners remain supported.

The public UX owner verifies zero retained-background writes, including actual
navigation, cover-like child changes and nested/moved layers. The lightbox
browser owner uses selected public/mobile production styles, not the full
authenticated bundle or generated Theme/custom CSS. It checks shell DOM
geometry/computed isolation every animation frame and compares a corner
backdrop pixel patch at a paused fade and handoff, including trusted manual S
native entry, CSS fallback and touch. Actual Smart Gallery acceptance remains
separate. Local negative/positive browser checks do not replace hosted
exact-candidate qualification.

After live 0.148.5 the user confirmed physical-gallery playback worked but
Smart Gallery still flashed. A new 60 fps recording contains six page exposures
near photo changes, lasting one or two frames, with HUD controls hidden. Thus
the previous localhost acceptance did not establish Smart Gallery acceptance.
Smart source metadata recreated a blurred source link on each photo; the owner
now reconciles that link without mutation when its source is unchanged.
Fullscreen additionally marks covered page branches to suppress painting with
CSS visibility while preserving layout. Marker restoration is separate from
inert state, protects newer modal paths and native dialog hosts, and runs on
fullscreen class changes, exit and teardown. This is a rendering defense, not
proof of a particular Chrome/GPU defect. The supplied local Smart route was
inspected with audio absent. On 2026-10-30 the user replayed the feature on
Windows and macOS and reported no page flashing on localhost. Acceptance on
the newly deployed live patch remains separate. The reported SDR/HDR handoff
appearance is outside this change; decode and tone-mapping behavior remain
unchanged. Native and touch fixtures now include populated Smart source links
and explicit background-paint checks alongside existing physical-gallery lanes.

## Lightbox fullscreen exit and split-map lifetime

Owner: `public/assets/gallery-modules/lightbox.js`. Native browser fullscreen
exit can consume Escape without delivering a page keydown. Both that event
and the explicit F/control exit use the same normal-viewer cleanup: dispose
Leaflet and its resize observer, hide split controls, clear fitted-map geometry
and unavailable state, cancel map-photo selection and stop the slideshow.
CSS/mobile fullscreen remains supported; page-delivered Escape leaves that
shell with the same cleanup. M retains independent map toggling.

Split-map payload, Leaflet-load and layout continuations retain a render
generation. Closing the split invalidates their generation, including across
rapid fullscreen exit/reentry, so late completion cannot reopen the pane.
The existing native-refusal CSS fallback is permanent capability support;
supported-browser/version usage remains unknown and no retirement is proposed.
The registered browser fixture covers exit-event cleanup, CSS Escape and
late runtime completion with synthetic media and a Leaflet boundary.
Real browser-chrome Escape, mobile devices and production acceptance require
manual verification; synthetic fullscreen events do not prove those paths.

## Telemetry preference transports

Standalone report delivery keeps its existing Admin-only URL, segment filters,
HTML body and safe suggested filename. Its controller now sends private,
no-store inline HTML so the Reports link remains visible in the browser's new
tab. The former forced-attachment disposition conflicted with that documented
interaction. HTTP clients can still read/save the same HTML response; native
links also work without scripts. Schema failure status/text remains unchanged.

Overview navigation uses existing public photo/gallery URL owners and their
normal access checks. Its telemetry service uses central schema inspection to
verify route/cache-identity columns before the model reads them. Unverified
columns leave ranking names unlinked and preserve the original read-only
counts; do not guess a different public image slug. This remains permanent
support for pre-path installs and transient observation failures; current
deployment/version usage is unknown. The controller owns link preparation.
Daily SVGs and the native daily-data disclosure work without JavaScript.
Missing date rows and absent retained session-day rows remain gaps, while
observed zero counters retain their zero baseline. Independent retention may
leave headline totals without corresponding daily detail.
Preview icons permanently reuse the Images editor's hover/focus/click/Escape
owner and ordinary thumbnail serving. With no script, native photo/gallery
name links remain available; preview enhancement usage is unknown. Opening a
preview requests a bounded derivative and retains existing media protections.

The telemetry controller and `admin-telemetry-workspace.js` enhance one-key
native forms with immediate, authenticated CSRF-protected saves. Confirmed
readback supplies canonical values; unavailable readback, timeout or malformed
completion locks only that form and offers passive reload without write retry.
The unchanged complete-form POST remains supported for existing consumers.
Native scoped Save forms and help disclosures permanently support no-script
and missing-Popover environments. Usage of those environments is unknown; no
retirement cutoff is inferred. Isolated controller/browser fixtures cover the
transport states; real devices, assistive technology and production acceptance
remain separate from hosted qualification. Rollup/purge keeps its explicit POST
and existing storage/service safety boundaries.

## Trash preferences and confirmation support

`admin_trash.php` and `admin-trash.js` own the compact preference form and its
native help disclosures. With JavaScript, the existing authenticated POST/CSRF
settings route saves both switches and numeric fields together, then reads the
canonical Trash fragment so deadline/pause evidence stays aligned. A guarded
refusal returns persisted values. A lost/malformed response or failed follow-up
read locks Trash controls and offers only passive reconciliation; no write is
replayed or presumed rolled back. Existing fresh-retention rearming and schema
guards remain with the service. Restore, purge and Empty Trash keep their
independent confirmations and bounded lifecycle workflows.

Without JavaScript, the classic Save/POST and native help remain permanent
supported transports. Popover enhancement is optional; unsupported browsers
retain readable inline disclosure content. Older-browser/no-script usage is
unknown, so there is no retirement cutoff. Desktop/narrow preview does not prove
real-device, assistive-technology, failure-path or destructive acceptance. The
registered rendering/browser fixtures own isolated regression coverage; hosted
FULL and disposable-installation lifecycle acceptance remain separate evidence.

## Dashboard compact help and action labels

The dashboard views and `admin-dashboard-workspace.js` own the compact
Maintenance Content pilot. Its server-rendered question marks are native
disclosures. JavaScript promotes only their help bodies to native auto-popovers
when supported, preserving Escape/outside-click dismissal and trigger focus.
Absent JavaScript or Popover API support, native disclosures remain usable.
Without JavaScript, explicit Save/POST forms remain the permanent compatibility
transport; enhanced switches save immediately with visible feedback, serializing
each form. Unknown completion blocks further writes until a passive reload and
never replays a possibly completed mutation. JSON refusals reconcile
authoritative
values without bypassing authentication, CSRF, capabilities or GPS schema gates.
The existing domain controllers own both transports; independent GPS override
reset and path regeneration keep their explicit workflows. Canonical factory
metadata is shared with the Settings registry and adds no schema/storage probe.
Older-browser usage is unknown, so no retirement cutoff is inferred.
Local acceptance covers density, help/viewport bounds and Public search
OFF/reload/ON with its original value restored. Hosted fixtures additionally
cover refusal, coupled crawler values and lost responses. No-script paths,
other persistent local settings, real devices and production remain unverified.

## Central Settings contextual help

Settings owns the native light-dismiss help in `admin_settings.php`,
`admin-settings-workspace.js` and `admin-feature-settings.css`. Browsers without
popover support permanently receive inline descriptions and no inert question
marks. This presentation fallback preserves help and native forms without
requiring JavaScript or changing persistence. Usage of older browsers is
unknown;
there is no version-based retirement. Desktop/narrow acceptance covers native
popover positioning, Escape, outside-click dismissal and switch label geometry.
The unsupported-browser fallback and system forced-color rendering have source
review only; real-device and production acceptance remain unverified.

## Admin feature autosave and complete-form support

The feature-policy service permanently supports the complete revisioned Admin
form for direct requests and browsers without JavaScript. Scoped autosave
preserves absent preferences instead of applying complete-form OFF semantics.
Both transports use the same canonical setters, authentication, CSRF and
registry revision. The compatibility owner is `feature_flags/admin.php` and the
Admin Features controller/view; removal would require evidence that supported
non-JavaScript/direct-form consumers no longer exist. Usage is currently
unknown, so support is retained. Factory reset uses new-installation defaults
where declared and changes masters only; no schema or subordinate-setting
reset is introduced. Partial domain failures reconcile persisted health, and a
lost response requires a passive read without automatic mutation replay.
Local acceptance covered save/reload, dependency recovery and factory reset
with the original preferences restored. Hosted PHP/HTTP/browser contracts cover
scoped preservation, adverse requests, fresh defaults and lost-response reads;
production deployment remains unverified until installation.

## Failed updater jobs

Explicit Admin recovery permanently supports overwrite-only FTP deployments,
local file differences and old stuck jobs. Reset/finish FTP and Install stable
supersede active and gate-owner jobs under real worker/runtime locks, retain
original records, backups and marker bytes, and complete forward migrations.
Stable recovery overwrites all declared files even at the current version;
when enabled, all manifest diagnostics are warnings, including missing,
malformed, stale or
version-mismatched metadata. Content warnings retain relative file/hash
evidence;
metadata warnings retain the exception owner and reference. Neither vetoes
forward recovery. Staging independently pins the selected actual source bytes.
Reset closes ordinary admission before draining existing readers so visitor
traffic cannot starve takeover; actual writes retain the exclusive runtime
lease.
Safe paths, required files and runtime ownership remain enforced. Publisher
content sweeps and FTP diagnostic sweeps are currently disabled by
`APPLICATION_UPDATE_PACKAGE_INTEGRITY_ENABLED = false` until explicitly enabled;
the verifier is retained. Actual copy hashes, DB/backup recovery evidence and
obsolete-file policy remain separate. Explicit recovery has no
automatic previous-database rollback; a newer manual tree prevents old package
replay. The fallback owner is the existing updater service and remains supported
while FTP and historical job records are supported; no retirement is inferred.

The 0.144.4 live incident confirmed a modified
`public/assets/styles/utilities.css`
and a manual completion stopped before migrations (reference `FF5BC089547B`).
The old retry incorrectly selected download for FTP work. Regression covers this
CSS warning, missing FTP manifest, malformed stable manifest, correct retry
stage,
worker contention, admission closure with a live reader, same-version stable
overwrite,
old gate takeover, retained extras/backups, all-stage pause and delayed browser
responses. Live deployment of the repaired version and production database
compatibility remain unverified until the operator installs and finishes it.
Server-policy reconciliation keeps its dependency-light legacy bootstrap root
when no root is supplied; the full updater passes its validated installation
root.
This preserves old migration callers without loading the full updater into an
already active old PHP request. The reconciliation service owns this permanent
compatibility default; removal requires proof those bootstrap callers are
retired.

Owners: update lifecycle/recovery services, early runtime isolation and the
schema-inspection SQL model. Recovery covers failed background, Admin/API and
Advanced commit-ID jobs while preserving their origin and saved preferences.
Three transient retries use 60/120/240-second backoff; file restoration becomes
eligible 600 seconds after the first failure. A killed slice is converted into
bounded failure recovery rather than replayed indefinitely. Running paused
manual work retains its owner.
An admitted failed-job retry may autonomously finish later slices while keeping
its original manual trigger; it does not enable takeover of ordinary paused
work.

New jobs pin a full trusted GitHub commit and preserve actual staged identities.
The retained optional verifier can check complete core-policy manifest coverage
when explicitly enabled. Non-core assets retain the canonical
package policy and exact-byte raw SHA-256 identities; original snapshots also
use raw hashes so binary/line-ending mutations cannot disappear in text
normalization. Legacy moving-branch failures without immutable identity
cannot receive a different package on retry. Reference `F05D71A153A2` is
non-retryable even when its original record says otherwise. Persisted legacy
jobs
retain their manifest compatibility reader; it is never applied to new jobs.
The English service fallback remains supported for direct CLI consumers without
a translation owner; normal Admin uses all four maintained catalogs.

Ordinary package activation closes admission before requesting the exclusive
runtime lease, including jobs already waiting at `activate` after an FTP code
replacement. Existing readers may finish; new ordinary requests receive 503
before acquiring a shared lease. Rollback ownership/evidence rules are
preserved.
The live 0.144.6 to 0.144.7 job demonstrated repeated runtime waits; the fixture
now covers an actual background job with an existing reader, independent new
reader refusal, disabled sweep cursors and successful completion after release.
The updated behavior has not yet been verified on production hosting.

The live 0.144.8 to 0.144.9 test exposed a stopped private chain at an owned
pre-activation drain, with no migration intent or activation writes and an empty
Admin session name. The updater owner now preserves configured session names
after session close and retains paced private successors for progressed,
gate-closed runtime waits. This is permanent support for visitor-triggered
post-response updates; public visits cannot restart work while gated.
No-progress
worker-lock contention and failed jobs still stop. The HTTP fixture covers the
real early gate and successors. The live 0.144.10 to 0.144.11 update completed
with the Admin Updates page open; its completion record reported the target
version, five copied files and no migrations. This establishes that deployment
path on production, without independently proving operation with no Admin page.

The early-runtime owner permanently supports a self-contained English HTML
maintenance fallback for browser GET/HEAD requests. Application locale and Theme
storage are unavailable while files are replaced, so inline fallback tokens use
the browser's light/dark preference. The existing three-second retry interval
also refreshes GET documents; machine and POST replies remain plain and cannot
replay a form. The gate, HTTP 503 and authenticated recovery admission remain
unchanged. Actual HTTP fixtures cover headers, representation, HEAD, no lease
admission and return to the same URL after gate removal. The maintainer reviewed
the local demo; production verification of this presentation remains pending.

Pre-activation failures release only the active pointer. Post-activation
rollback
uses one independent child and the verified original snapshot, preserving both
identities, archives, hashes and diagnostics. It requires protocol-1 runtime
isolation, no ordinary-runtime-use witness, fresh matching actual schema/ledger,
proven zero migration effects and complete verified backups. Migration intent
without a completed zero-work receipt is unverified. Missing legacy evidence or
unsupported target protocol stops automatic file rollback. Database migrations
are never reversed and ordinary application data is never restored from a dump.
Failed rollback cannot spawn another rollback. Failed-source artifacts remain
protected from pruning; completed child diagnostics also have a compact source
receipt. A bounded 32-commit exclusion list and highest failed stable version
prevent automatic reinstall of known bad sources.

The early-runtime owner permanently imports old `cache/updates/activation.json`
markers before admission, exclusively creates the canonical marker without
overwriting another owner, and preserves exact legacy bytes in private receipts.
This bridge supports older workers during an in-place upgrade. It can retire
only after evidence proves no supported installed worker publishes the old name;
that usage evidence is currently unknown. Empty manual markers remain supported.
The marker is excluded from Git and all package/rollback ownership. Existing
HTTP fixtures cover manual presence/removal, old marker import and active jobs
without maintenance; real updater/SQL fixtures cover critical refusal and public
admission during paused cleanup. Production transition acceptance is pending.

The runtime lease is permanent cooperative protection for Gallery HTTP/CLI
processes. The sole public gate is root `maintenance.lock`. New jobs persist
`runtime_committed` after migrations and required finalization, close the
rollback window and reopen before physical cleanup. A paused or failed cleanup
does not recreate maintenance or claim an isolated rollback baseline. Existing
legacy gates retain their original completion semantics. Authenticated
Admin updater/private ticket admission retains all later authentication checks.
Unknown storage and ordinary runtime use refuse unsafe restoration. External
writers require operator coordination. Gated failed jobs need hosting CLI cron
or an authenticated Admin opportunity, since denied visitors cannot run the
scheduler; no idle backoff timer is promised.

The reported 0.144.1 failure and manual file rollback demonstrate the deployment
bootstrap limitation: publishing code cannot replace an updater that never
installs it. Existing stuck jobs lack the new proof. Use coordinated manual
forward repair in `docs/AUTOUPDATE.md`; copying updater files, editing a job
target or merely restoring files cannot prove database compatibility.
Disposable fixtures exercise real SQL replay, locks and file activation.
Live hosting, external writes, production data compatibility and actual
deployment acceptance remain unknown; no usage assumption retires legacy
readers.

## Browser-local Admin navigation

- Owner: the shared Admin chrome view, `admin-navigation.js` and
  `admin-navigation.css`.
- Protected behavior: navigation groups, capability filtering, ordinary URLs and
  keyboard access remain available on desktop and narrow screens. The desktop
  starts expanded; its icon-rail preference stays in installation-scoped browser
  storage. Mobile opening is transient and does not change that preference.
- Permanent fallback: browser privacy policy or quota may reject localStorage
  reads/writes. Keep the current document's choice in memory and fall back to
  expanded on a new document; do not replace this with database persistence.
  Without JavaScript retain visible grouped navigation. When ResizeObserver is
  absent, retain initial and window-resize header measurement. These paths may
  retire only after an explicitly reviewed browser-support-floor change;
  storage-denial and scriptless navigation are permanently supported.
- Regression evidence: production-markup Chromium scenarios cover the rail,
  reload, denied storage, responsive disclosure, focus and sidebar replacement.
  Both desktop states reach their last account action through document scrolling
  without an independent sidebar scroller; the mobile panel remains bounded.
  BASIC does not execute those scenarios; hosted FULL remains the execution
  authority. Real assistive-technology and physical-device usage are unknown.

## Browser-local visitor progress

Initial evidence seeding belongs to the independent migration model, not a new
function on the already included runtime gallery model. A reported live upgrade
failed with reference `F05D71A153A2`, matching the absent runtime seed function;
the packaged source contained that function. The upgrade regression reproduces
the failure with an older loaded model and also
rejects dependence on a previous seed symbol or runtime attachment adapter.
Migration-specific limits and reads remain frozen to preserve pending/replayed
upgrades. This compatibility boundary has no retirement date: old installations
can upgrade directly. Actual hosting recovery remains installation-specific.

Owners: existing gallery/Smart SQL models, gallery lookup policy, public card
controller, shared layout and native visitor browser module. Keep stable
namespaced gallery IDs and a versioned installation path in localStorage; a
future Viewer account must not be confused with the separate Admin identity.
Cross-device/account synchronization remains outside this format.
Root and rewritten public front controllers use the canonical request mount for
the same history key. Windows mount aliases are case-normalized; case-sensitive
hosts retain distinct installation paths. The live localhost journey exposed
and verified the root/public-script alias correction.

Blocked/corrupt/quota-exceeded storage permanently supports volatile tab-local
operation without invented visits or New facts. Web Locks are preferred for
atomic local updates; storage-event union and ordered reset epochs remain the
supported fallback for browsers/profiles without usable locks. Random reset
nonces are local concurrency data, never anonymous visitor identifiers and never
sent to PHP. Neither fallback has a retirement date; deployed usage is unknown.
Optional per-record reset order extends the existing version-one format without
discarding previous visits. A newer branch reset overrides stale direct-open
flags while content baselines still merge monotonically. Later genuine opens
within that reset order are retained. The branch action never creates New or
acknowledges existing New; shared Smart identities affect all their placements.
Missing/unknown index schema and dirty/oversized evidence preserve ordinary
gallery navigation and explicitly omit New evidence. Do not replace an
unavailable
watermark with generic gallery/image modification timestamps or visitor writes.
The public panel also omits technical availability warnings and does not claim
that unknown evidence means no new content. Existing bounded server diagnostics
remain unchanged. Notification-only acknowledgement preserves visit flags and
monotonically advances known baselines. The separate reversible bulk visit
action keeps those baselines, uses ordered record stamps and remains usable
without content evidence; it never clears installation history or reset epochs.
Automated regression sources cover policy, persistence and rendered panel
behavior. Real multi-device, private-browser, touch and assistive-technology
acceptance remains installation-specific; BASIC does not qualify those paths.

## Persistent short presentation links

Owners: existing gallery model, lightbox-settings service, public/Smart
controllers and presentation browser modules. Newly shared links use immutable
gallery-scoped version-one snapshots and globally unique eight-character codes.
The surrogate primary key and full fingerprint remain independent of future
settings fields. Keep explicit decoders for supported document versions; do not
reinterpret stored defaults when adding options. Codes have no automatic expiry
and never grant gallery access. Retain reservations even if a gallery is deleted
so an old code cannot be reassigned.
Canonical URLs start at the application root: `/presentation=<code>`, or
`index.php?presentation=<code>` without rewrite. Destination identity lookup
does not read settings or grant access. Playback retains the compact address.
New/edited/generated gallery URL slugs reserve bare eight-character hex codes
and presentation prefixes, using `gallery-` to disambiguate. Display titles stay
free. Existing persisted addresses are not rewritten during ordinary reads;
future regeneration applies the reservation policy.

Legacy `presentation=1` and `autoplay=1` parameter snapshots remain permanent
support for previously distributed links. Ordinary galleries and legacy links
remain usable before the new migration; short-link creation/resolution refuses
missing or unknown storage. The selectable-URL clipboard fallback remains
supported. Automated fixtures cover persistence, collisions, scope, schema
refusal and asynchronous ownership. Deployment-wide usage, real-phone playback
and assistive-technology acceptance are unknown until manual qualification;
source BASIC does not establish them.
Password/age forms retain the short code and return only to a presentation
bound to the same gallery after the existing grant succeeds. If link storage
fails at that final return, the access controller permanently supports returning
to the ordinary gallery rather than undoing a valid grant. Full installation
acceptance of these protected-gallery transitions remains a manual check.

## Central cache continuation and stop controls

Owner: the existing Maintenance Center and cache registry services, plus the
Maintenance Center browser module. A central job grants at most 32 short cache
slices before later tasks continue. Due/retry dates, original files, pins,
manual preview approval and durable deletion checkpoints remain authoritative.
Legacy single-cycle checkpoints resume by their admitted ID; registry work
resumes by admitted region keys. Both formats remain supported until the
existing cache migration owner proves legacy debt drained. No checkpoint reset
or schema migration is introduced. Running job rows from the earlier executor
have no slice counter; their first updated request starts this bounded allowance
and retains their existing task index and cache journals.

The browser accepts pause/cancel while a step is pending, serializes the control
after that response, and consumes a cancellation flag before stopping. This is
permanent support for persisted running jobs, including jobs surviving service
restarts. The maintenance, registry and asynchronous-control fixtures cover the
decision boundary, disposable deferral/status failure and request ordering.
Live Mac/Apache/MySQL recovery and cancellation acceptance remain unverified.

## Legacy music detachment and unbacked catalog cleanup

Owners: gallery music mutation/repository parts, the music catalog model/service
and Maintenance Center. Catalogued local originals are preserved and verified
centrally before detachment, retaining their logical song identity. Missing or
unverified originals refuse the operation before index publication. A confirmed
pre-catalog schema retains original-only deletion permanently for old supported
installations; unknown or partial schema cannot select that compatibility path.

After relocation, a freshly reviewed maintenance task prunes at most 20 logical
songs per step when no asset row, active gallery reference or dirty-snapshot
reference remains. Album/tag-only metadata may be removed; audio bytes and
registered assets are preserved. Missing/unreadable bytes behind a registered
asset remain a restoration/diagnostic problem rather than proof of disposable
metadata. Existing catalog locking, revision changes and FKs own this operation.
Repository and maintenance fixtures cover retention/refusal, re-add, dependent
metadata cleanup and protected references. The separate Mac report supports the
former legacy removal sequence; recovery of already deleted bytes is unknown
and requires an original or backup. No automatic audio recovery is claimed.

## Public viewer focus, compact geometry and text projection

Owners: the existing public lightbox/map controller, shared public modal
lifecycle, public CSS, description projection and translation services.
The modal stack isolates covered content while preserving its exact prior inert
state, restores openers and gives Escape to the topmost map before the viewer.
Native dialogs retain browser-owned focus. Late work cannot acquire focus in
an unloaded document. Compact mouse/keyboard layouts and actual touch-device
layouts remain supported through the same viewer; no gesture pipeline or media
authorization route is replaced. No-JavaScript links and pagination remain
permanent support.

Public text projection retains authored captions and titles. Filename-derived
alt names use a localized gallery/photo-number fallback without changing stored
metadata or configured visible filenames. Description snippets retain link
labels
and code text while removing rich-text syntax; result rendering still escapes
them. EN/CS/DE/SV cardinal forms use the existing translation helper; catalogs
opt in with cardinal keys while keys without variants retain their base text.

Floating widgets retain configured coordinates and their established in-flow
fallback when primary previews or controls leave no safe space. Theme selection,
typography and backgrounds are preserved; component text uses their foreground
and panel tokens. Real-phone, screen-reader and non-Chromium evidence remains
unknown until the corresponding manual checks are performed. Automated browser
gestures establish deterministic event routing, not physical-device acceptance.

## Visitor scheduler and optional hosting transport

Owner: `request_scheduler.php` with the existing task services and private
maintenance controller. Visitor-triggered scheduling is permanent primary
support for ordinary shared hosting; external cron never becomes a prerequisite.
A site with zero PHP requests has no wall-clock execution guarantee. Unknown
schema/ownership and unreadable receipts fail closed without replacing reliable
state with success. Actual WEDOS/live traffic and worker-pool measurements for
this scheduler transition are unknown until installation qualification.

The existing standalone registration APIs and updater-continuation bootstrap
helper remain supported for legacy CLI/fixture consumers. Production startup
uses only the central entry; retaining these APIs does not register duplicate
callbacks. Their retirement requires a complete consumer audit and regression
evidence. Native POST fallback, query-string routing and explicit token
revocation remain permanent support for no-JavaScript/non-rewrite hosting.
Hosting calls retain a separate 60-second admission window and shared task
locks. They never bypass owner deadlines or affect visitor/manual cadence.
The live-preview pause is browser-local and does not disable work. Its stale
body/visibility behavior and future-only relative labels have regression
coverage; network/ICMP flood mitigation remains the hosting provider's layer.


## Visitor-triggered publication and unchanged access policy

Owners: gallery publication service/model, existing gallery editor writer and
maintenance bootstrap. Confirmed pre-feature schema keeps existing galleries
usable without scheduling; missing/unknown deadline/index storage never permits
automatic publication or explicit schedule writes. This is permanent support
for code-before-migration upgrades, retired only if the installation lifecycle
later proves all requests must have current schema. Deployment prevalence and
live latency evidence are currently unknown.

The enhanced native input uses the browser timezone and previews its exact UTC
transport. No timezone preference is persisted. The server still validates UTC
only. Missing JavaScript/timezone support retains the UTC-labelled native
control as permanent no-JavaScript compatibility, not a guessed server zone.
The browser chooses the earlier repeated autumn hour for a new edit; the UTC
preview exposes that choice. Unchanged stored later occurrences are preserved,
and nonexistent spring wall times fail validation before submission.
Stored private/unpublished galleries retain their current direct-link policy
until execution. Scheduled Publish restores `public + listed`, matching the
owner's clarified requirement that it behave exactly like explicit manual
Publish. The initial unlisted-preserving implementation was superseded: the
manual editor couples unpublished visibility to unlisted, so preserving that
flag prevented ordinary scheduled galleries from ever entering discovery.
Password, NSFW, token and parent access policies are unchanged. An unrelated
unchanged full-form save still preserves independent unlisted state. Existing
PDF
publication markers and the shared zero-wait writer lease remain authoritative.
Unknown schema, ownership errors and invalid PDF children retain their pending
deadline for a later eligible visit. No schedule is imported from sidecars or
restored from a deleted gallery's old deadline.

Work is bounded to 16 candidate identities per eligible visit. Larger backlogs
catch up across visits; a scalar `gallery_publication.scan_cursor` in existing
app settings advances an indexed keyset scan so blocked rows cannot permanently
starve later deadlines. The gallery timestamp remains the sole pending list;
the cursor contains no queued work or independent per-gallery status. Media
requests and requests that do not execute PHP do not trigger publication.
No-traffic time produces no execution. Sidecar failure is reported with a
bounded diagnostic; database visibility remains authoritative, matching the
existing editor sidecar-write boundary. Hosted BASIC is source evidence only;
runtime, migration, browser and deployed behavior need their own qualification.

## Administrator music transport and paged playback

Owners: the existing Music Manager Controller/View, music catalog Service,
shared HTTP response helper and exclusive `gallery-music-player.js` owner.
Administrator playback adds no persisted setting or public player markup.
The public gallery stream wrapper remains supported and delegates to the same
verified-original HTTP responder as the new global Admin preview. Its existing
GET/HEAD, Range, If-Range, private-cache and opaque-missing semantics remain
permanent; no migration of URLs, identity or original files is required.

Album playback retains one bounded catalog page and its global revision.
A changed snapshot stops rather than mixing membership generations. Pause and
seeking preserve the current original; Stop, teardown and editor replacement
invalidate pending Play/page work. Unknown duration disables seeking rather
than estimating. Mute is browser-memory state shared across current-page scopes
and never changes gallery defaults. JavaScript is required for the new controls;
existing no-script editing/upload forms remain supported.

The Admin repeat control cycles All, One and Off, retaining the existing All
default. Current-control state preserves a list's choice when exclusive playback
switches scopes; fresh/replaced markup resets it. Public playlists without the
Admin control permanently retain their repeat-all default. Repeat One restarts
the selected original locally; explicit Next/Previous remain available, and
changing a mode never activates stopped audio or changes a paused cursor.
Existing bounded original-error handling remains supported in every mode;
repeat does not create an automatic retry loop for unavailable files.

Regression owners are the music browser, Admin manager browser, manager request
boundary, public media HTTP and repository database fixtures. Deployed browser,
codec and device usage remains unknown. Silent adapters and muted interface
acceptance are distinct from audible playback qualification. No support path is
being retired.

## Direct album uploads and retained native forms

Owners: the existing global Music Manager Controller/View/browser module,
`music_catalog` Model/Service and `music_repository` Service. Album uploads
retain the existing catalog-song Add control and all gallery upload entry
points. The native multipart form is permanently supported for environments
without JavaScript; its aggregate selection must fit the hosting POST/file
limits. Enhanced uploads send one original per request and retain each canonical
acknowledgment. Unverified responses stop the queue and lock replay until a
passive reload; no automatic retry can duplicate a pending write.

Exact original-byte reuse applies only to explicit upload admission and never
collapses existing manually split/merged catalog identities. Existing MIME,
source-size, schema-readiness and live-gallery quota policies remain owners.
A proven SQL rollback permits deletion only of the attempt-owned, verified,
unregistered original; ambiguous commits retain bytes permanently until the
ordinary storage owner can prove safe cleanup. Songs with zero album
memberships remain catalog entries backed by their central originals.

Regression owners: `music_repository_database_test.php` and
`admin_music_browser_test.mjs`; deployed usage and real network-failure rates
are unknown. The required hosted matrix and silent localhost interface check
must be reported separately from codec/device playback. No fallback retirement
is proposed.

## Shared music originals and live gallery assignments

Gallery music failures retain a non-secret response/Admin-log reference, with
PHP error logging when the Admin writer is unavailable. This permanent fallback
preserves diagnostics during database/log-storage failures without changing the
mutation outcome. Missing originals and dirty legacy sources have distinct
refusals; no compatibility path assigns unverified bytes or trusts dirty usage
metadata. Regression owners are the music service and repository database
fixtures; the reported failure on a separate Mac has not been directly observed
in this Windows environment.

The editor's catalog preview is a permanent administrator-only read surface.
It resolves verified central or existing legacy bytes without promotion or
assignment and shares the established GET/HEAD/range music streamer. Public
gallery delivery retains its existing authorization and download policy.
Chooser duplicate prevention is presentation policy: directly assigned and
live album sources retain their independent stored semantics. Batches recheck
current membership and reuse canonical single-item mutations rather than
introducing a second persistence path. Evidence is owned by the existing local
Music browser and repository database fixtures; deployment-wide usage is
unknown.

Owners: gallery music model/service and its private projection/migration parts,
the central repository service, catalog SQL model/service and Maintenance
Center.
V1 gallery indexes and local immutable originals remain permanently readable.
Legacy permission, order and preference edits preserve v1 wire format and its
revision semantics until a central source or live album upgrades the index.
New uploads use `data/music/`; v2 sidecars preserve direct references, live
album
groups, ordered source identities and interrupted local cleanup. Public reads
never migrate files or hash every original. Destination gallery authorization
and saved download/visibility flags govern explicitly assigned shared music.
No public repository asset endpoint or portable foreign logical ID is trusted.

Schema migration is forward-only and does not relocate existing files. Only an
maintenance task selected in an approved plan switches verified old originals to
central
references and removes positively matching local bytes. It processes one source
per step, retains stable track/song identity, and stops on uncertainty with its
last successful cursor intact. Assignment/removal/Trash do not delete central
assets. SQL and both filesystem storage locations must be backed up together.
The proposed music move defaults on; administrators may deselect it before
approval. Analysis remains read-only. On the maintainer's localhost, job 7
reported three moved originals (16,059,961 bytes); all three central files
matched their SQL sizes and SHA-256 digests, with no local audio copies or
pending cleanup entries. This observation does not establish deployment-wide
migration coverage.
There is no evidence that deployed legacy indexes have all been converted;
retirement is not authorized. Production migration timing and device playback
remain unknown until deployment acceptance. Hosted regression coverage must
include legacy/v2 reads, live album changes, interrupted migration, access,
shared-file retention, accounting and in-place editor actions.

The former `admin-edit-documents` and `admin-edit-images` tabs remain permanent
bookmark aliases for Upload media/PDF and Upload media/Images. The gallery
editor
URL owner preserves the drawer request and active language; the shared browser
tab owner resolves old Images hashes. Existing bookmark usage is unknown;
removing these aliases is not authorized. The image preload retains its original
container and bounded transport inside Upload media, so loading photos does not
disable music/PDF tools. Artwork and presentation controls retain native form
association with the guarded settings form, including the top disk Save button.
The existing mixed-file uploader, PDF opt-in conversion and exact retry owners
remain authoritative. Live upload/browser acceptance is recorded separately from
hosted BASIC source checks; deployed usage remains unknown.

## Optional public attachment usage projection

Owners: presentation schema policy, gallery attachment-usage reader and worker,
and the existing Maintenance Center attachment owner. Migration
`202610200004_gallery_attachment_usage.php` adds optional derived snapshots for
public PDF/music card counts. A ready empty snapshot means known zero; absent,
unknown or dirty state is not zero and omits that media-kind badge. Public
rendering reads the projection only, applies current visitor access, visibility,
hidden-item and capability rules, and never opens or hashes original files.
Cards place nonzero PDF/music badges in a compact, right-aligned stack below the
photo-count badge; when that badge is enabled, the opened gallery's action bar
places the same counts inline immediately to its left.
Digests are unique per media kind. Existing originals and sidecar indexes remain
authoritative. No usage or deployed-data coverage baseline is assumed.

Supported application mutations mark the affected projection dirty before a
source change and publish a replacement snapshot after successful changes.
Unknown schema blocks the write; confirmed-missing optional schema may omit the
projection. Manual filesystem edits bypass those hooks and require
reconciliation before counts can be trusted. If the durable completion receipt
is absent, the first successful public HTML GET for home, gallery, or Smart
Gallery queues a media-only initial pass after the response through the
authenticated configured-origin `site_maintenance_cron` transport. The
`app_setting` key `gallery_attachment_usage.background_state` stores its cursor
and receipt. Each worker request runs one existing batch of at most 25 galleries
and queues continuation while work remains; later public requests can recover
interrupted or busy work after the bounded lease or retry delay. Only a full
pass without failed galleries records completion. This is one-time bootstrap,
not periodic or broad maintenance. Maintenance Center also provides a
default-selected optional manual legacy reconciliation with a persisted cursor,
at most 25 galleries per step, at most one legacy OFP candidate hash per step
and a 25 MiB hash cap. When an OFP manifest has no valid digest, the worker
stores
`ofp_pdf_sha256` through atomic manifest replacement under the shared persistent
root lock. It also serializes music reads with the private
`.gallery-music/.lock`;
busy locks defer a gallery. Newly created galleries with no source metadata may
receive insert-only ready-empty rows. Analysis does not inspect originals or
write projections. Failed galleries remain dirty and omitted for a later retry;
original PDF/audio bytes are unchanged. Retain this optional projection only as
a cache of authoritative source data; no deployment-wide completion or
performance claim is established.

## Logical music catalog and media-typed tags

Owners: existing physical playlist model/service, logical catalog model/service
and shared tag model/service. Existing JSON indexes remain authoritative for
bytes, visibility, download permission and playlist order. The SQL catalog is
an additional metadata layer; filename-based identity inference remains
unsupported. The explicit repository workflow above is the only audio relocation
owner. The global extension preference defaults OFF when
absent on both new and upgraded installations. Explicit custom titles retain
their text. Older image tags retain IDs, globally unique slugs and assignments.
Missing media schema preserves only the documented legacy image path; unknown
schema never authorizes catalog or typed-tag writes. These are permanent
compatibility policies; no usage evidence supports retiring them. Live hosting,
large-library timing and real-device playback evidence remain unknown until
qualification and deployment acceptance. SQL and filesystem writes cannot
form one transaction, so the physical index remains playback authority and
bounded reconciliation repairs derived links without authorizing stale bytes.

## Quick-tag Escape handling in native fullscreen

Owners: `quick-photo-tags.js` and the public lightbox fullscreen owner. The
observed macOS Chrome workflow allowed native fullscreen to consume Escape
while the quick-tag row was open, bypassing the page handler. In JavaScript
fullscreen, Chrome's Keyboard Lock API can capture Escape while the row is open;
the owner releases the lock when the row closes, and unlocks after any pending
request settles. Safari 26.4 supports
`requestFullscreen({keyboardLock: 'browser'})` for quick-tag-capable viewer
entry; Escape reaches the page while the browser retains a 1.5-second
hold-Escape fullscreen exit and automatically releases the lock on fullscreen
exit or tab switch. See the
[Chrome Keyboard Lock documentation](https://developer.chrome.com/docs/capabilities/web-apis/keyboard-lock)
and
[WebKit's Safari 26.4 notes](https://webkit.org/blog/17862/webkit-features-for-safari-26-4/).

Where the lock is unsupported or rejected, keep native browser behavior:
fullscreen may exit first, which closes the row without automatically
re-entering fullscreen. Do not add a permission-prompt workflow. This fallback
preserves access to browser fullscreen exit and remains supported unless
measured browser coverage and verified alternatives justify retirement. Browser
fixtures exercise the API path and page-dispatched keys, but cannot prove
OS/browser-reserved physical Escape handling. The user reports that ordinary
quick-tag behavior works on Chrome for Mac. The earlier fullscreen Escape
regression is observed; post-fix physical Escape behavior in Chrome and Safari,
and deployed browser usage, remain unknown.

## Deferred lightbox first-photo initialization

Owners: `lightbox-deferred.js` and `lightbox.js`. The deferred entry reveals
the canonical loading shell before importing and setting up the full viewer.
During that interval the overlay can be visible with no committed photo ID;
setup-time photo-order reconciliation must leave that shell alone until the
normal navigation owns the first photo. The production lightbox fixture now
loads through the deferred entry with the real loader markup and covers this
ordering. Its focused Chromium regression passed; the hosted full workflow
remains the qualification authority. Keep the empty-identity guard scoped to
the initial-loading shell so ordinary active-photo reconciliation remains
unchanged.

## Visitor-started automatic stable-update continuation

Owners: request maintenance, lightweight updater job lookup, deferred updater
background service, and the existing site-maintenance controller/transport.
The first admitted GET/HEAD after the saved discovery interval may create a
stable job; a successful response hands its complete installation to sequential
authenticated HTTP workers. Anonymous visitors and admitted robots share the
same interval as signed-in users. Rejected SEO requests exit before maintenance.
No browser JavaScript or external scheduler is required. Existing 5/15/30/60
minute preferences, disabled discovery, pinned beta code, stage validation,
activation semantics and GitHub backoff remain authoritative.

Request-driven continuation and optional CLI remain permanent fallbacks for
restricted loopback, single-worker development servers, lost handoffs and
hosting termination. A queued or executing lease lasts 120 seconds; failed
dispatch admission retries no faster than thirty seconds. A failed worker stops
the chain and retains the existing sixty-second visitor retry policy. No idle
PHP process waits for a future release. One visit can complete a healthy
installation; no traffic cannot initiate discovery of a later release.
Registered regressions cover real multi-slice cleanup and ticket/lock/recovery
boundaries. Production provider, TLS, host process-pool and activation timing
remain unknown until deployment. No database schema, release assets, manual
updater workflow or recovery data are retired.

## Windows startup, shared profile and tray availability

Owners: `winapp/uploader/windows_startup.py`, `gallery_watch_upload.pyw` and
`installer.iss`. Missing legacy `start_with_windows` preferences default to
true;
explicit false survives migration and upgrades. Only frozen Windows apps own
the per-user Run entry. Source/non-Windows execution permanently remains usable
without registration. Helpers and runtime/version probes retain early dispatch;
`--once` refuses concurrent profile writers. Windows mutex/event ownership is
profile-scoped across sessions and releases on clean exit or process death.

A logon launch withdraws its root before painting. If tray creation is missing,
fails or stays unavailable for five seconds, the launcher restores the window.
This permanently protects access in stripped-down source environments and shell
initialization failures. Retirement requires an alternative visible entry point
that cannot strand the process. Explicit relaunch restores the same window and
preserves current workers; import recovery waits until the window is opened.
Central regressions cover registry fakes, state-admission ordering and tray
fallback; native Windows subprocess cases cover event delivery and crash
cleanup.
Installed logon/UAC/uninstall and cross-session usability evidence is unknown
until manual qualification. No installer, existing profile or previous binary
artifact is retired by this source change.

Integration's `develop-integration.mjs` also owns pre-FULL installer-version
admission. Complete trees compare distributed WinApp inputs against current
main; tests/readme/instructions retain their existing build-impact exclusion.
Runtime changes require a higher numeric X.Y.Z version than main, while multiple
integrations of an already higher upcoming version remain supported. Complete
version blobs are hash-bound; missing/ambiguous evidence blocks instead of
assuming no impact. Selection and final publication recheck main, and retained
execution cannot waive a newer published baseline. Registered negative fixtures
cover same/lower versions, deletion/rename/mode changes and main movement.
Release remains an assembly of protected develop, without a new release gate.
Native installer/autoupdate acceptance remains separate from these policy tests.

## Explicit release-start dispatch

Owners: `begin-release.mjs`, `start-new-release.mjs`, `begin-release.yml` and
`release-qualification.yml` under `.github`. The selected Begin Release
workflow revision and current protected develop source remain independent.
Explicit workflow dispatch permanently supports GitHub's suppression of
GITHUB_TOKEN-created push events. Bot initialization requires server-bound
initiating run/attempt/version and original maintainer permission. Ordinary
push initialization and external-note origin reuse remain supported. Lost
dispatch responses permit reuse of an unmoved ref; active work and advanced
release refs are preserved. Registered real-Git fixtures cover creation,
retry/duplicate suppression, source separation and adverse handoff metadata.
Live first-release dispatch/permissions remain unmeasured until a separately
authorized release. No existing tag, origin, review or published asset is
retired.

## Release-note provider availability and external editorial work

Owners: `.github/scripts/patch_notes_handoff.php`, `patch_notes_ai.php` and
`release-qualification.yml`. External evidence/response support is permanent:
maintainers may use another AI or work without Copilot entitlement. Automatic
requests are capped to one evidence slice plus synthesis by byte/file/context
limits before provider work. A quota or provider failure preserves all source
evidence in a 30-day handoff and reports NOTES_PENDING, without inventing
qualification or publishing an incomplete release. Source hashes and existing
editorial validation protect continuation; filesystem/build failures remain
mandatory. Hosted regression owners cover routing boundaries, complete evidence,
source/response drift and dispatch-origin reuse. Actual provider consumption,
quota exhaustion, first-install GitHub input UI and live continuation acceptance
remain unknown until a maintainer release exercises them. No historical release,
tag, approved tree, asset or source-qualification protocol is retired.

The same owners retain contextual issue references in the canonical prompt and
external bundle. Per-commit changed paths preserve the reference-to-change
context; old style examples and routine release bookkeeping confer no current
issue relevance. Fixed historical references are no longer added to new origin
commits or PR wrappers. Already completed notes and historical commits remain
authoritative; there is no issue-number blacklist. An exact
`NOTES_REVIEW_REQUIRED` model response permanently supports explicit inability
to extract/consolidate evidence or produce grounded notes, including a material
unresolved issue relationship. Existing validation routes it to the same
source-bound JSON handoff. Deterministic fixtures cover signal rejection and
unchanged transport/provenance; live model judgment and use remain unknown until
release acceptance. Retain external support while provider/context limitations
or maintainer editorial review may require it.

## Telemetry storage and historical aggregates

Successful public HTML visits now supply private telemetry-only worker requests
through the existing maintenance endpoint and configured installation origin.
The site-maintenance service owns admission, attempt throttling and shared
socket
transport; the controller owns token/method validation. Retention still belongs
to the original telemetry worker. Single-worker HTTP regressions establish real
handoff and security/opt-out behavior; production TLS loopback and worker-pool
impact are unknown until deployed. External cron remains supported permanently
for low-traffic or restricted-loopback installations. No traffic cannot create a
PHP wake-up. Response-finishing support improves detachment; without it only the
bounded transport remains in visitor shutdown, never the retention SQL itself.


The telemetry rollup service now rotates a persisted allowlisted first-target
cursor and visits independent targets in one-batch rounds. Missing or obsolete
cursor values permanently restart the scheduling order without altering
retention
eligibility; unavailable settings refuse the pass. Defaults increase to ten
seconds,
5000 rows times eight batches per table and a five-minute completed interval.
Existing local runtime-limit overrides remain authoritative. Production deletion
throughput and cron cadence are unmeasured; the Maintenance Center four-second
slice and archival safety checks remain supported.


Owners: telemetry settings/rollup services, telemetry model and
`202610190001_telemetry_compact_indexes.php`. Missing independent SQL retention
permanently supports pre-seed installations with the documented seven-day
default;
unknown reads never authorize purge. Existing administrator hourly/daily choices
are preserved. Unknown metric families retain their dimensions permanently until
an explicit reporting contract permits normalization. No measurement totals,
photo identity or bot segmentation are retired. Historical daily compaction does
not reconstruct from partially purged hourly data. Production savings and DDL
lock duration are unknown until deployed. Hosted regressions cover populated
migration replay, logical uniqueness, archival/compaction idempotence,
historical
source independence and rollback on a real constraint failure.

## Optional WinApp dispatch and maps (0.6.0)

The additive plan/actual owner is `winapp/uploader/flight_comparison.py`.
Analysis schema 4 and report template 11 preserve prior PDFs/delivery receipts
while identifying new statistical editions. Existing JSONL and dispatch
snapshots permanently support missing pressure, Zulu-calendar, parking, navlog
speed/wind/stage and additional schedule fields as unknown. Their absence never
becomes a host-clock substitute, estimated fuel mass, invented block event or
zero deviation. Retirement would require evidence that retained recordings and
their regeneration no longer depend on these decoders; usage is unknown.

Numerical flight totals require matching endpoints, one takeoff/landing and
complete relevant original intervals. Intermediate flightplan matches require
ordered positions within 2 NM; the older 10 NM visual overlay remains supported
independently. Route-position graph estimates are explicitly distinct. Pressure
comparison requires both transition references; optional channels cannot break
core acquisition. Histogram P95 is approximate (overflow lower bound), and
graph decimation does not alter statistical accumulators. Estimated OUT/IN
events require stationary parking-brake/motion evidence and never identify a
stand. Central `test_flight_comparison.py`, `test_flight_simbrief.py` and
`test_simconnect_flight.py` own regressions. Live simulator/provider/add-on
acceptance, usage and print acceptance remain unknown until measured.

Template 11 retains original point rows when an airport occurs in both navlog
and endpoint. Only verified duplicate airport anchors may share event time;
unrelated intermediate matches keep strict ordering. This repairs an observed
case where complete takeoff/landing evidence was suppressed by the duplicate
arrival. Regression coverage retains both the duplicate and a rejected
coincident intermediate alias. Grouped presentation preserves original phase
statistics and weighted coverage. The renderer permanently supports labelled
AMSL display from older recordings without using it as pressure altitude, and
planned-only UTC when simulator UTC is absent. This keeps historical recordings
useful without inventing unavailable sensor fields. Retirement requires evidence
that retained recordings no longer need this display path; usage is unknown.

Language rendering uses spawned isolated processes through `flight_render.py`.
The parent prepares shared map backgrounds, retains credentials and joins every
child before delivery or terminal error/cancellation. Running atomic editions
may finish after cancellation; completed files remain available. Process startup
errors require visible retry rather than a hidden serial fallback. This protects
consistent font state, tile-cache ownership and complete-set delivery. Central
render/workflow tests cover real child overlap and failure/cancel barriers;
frozen Windows startup and live speedup remain unmeasured. The existing launcher
freeze_support remains the compatibility owner for packaged process startup.
History groups multiple versions numerically newest first without filesystem
changes. Catalog numbers are authoritative; passive legacy numbering preserves
the existing mtime-derived display ordering until explicit catalog organization.
Legacy support remains permanent while retained PDFs depend on that layout;
usage is unknown. Stable file/revision IDs preserve selection and receipts.

Owners: `flight_simbrief.py`, `flight_navdata.py`, `flight_maps.py`,
`flight_ui.py`, `flight_data.py` and `flight_report.py` in `winapp/uploader`.
Legacy sessions without simbrief.json remain valid. Dispatch values are planned
evidence and never overwrite simulator measurements. The OFP snapshot
participates
in the analysis checksum; the template identity prevents reuse of earlier
layouts.
Explicit local regeneration retains all previous PDF revisions and receipts.
Version 0.6.1 fits both map extents independently of print DPI and omits the
redundant capture-scope paragraph. Existing PDFs remain unchanged; actual
printed-map acceptance remains unknown until reviewed. The observed RJTT–RJFR
recording contains 57 repeated remote positions near 0 N, 90 E between local
Japan positions after simulator resume. The report map owner excludes only
blocks bounded by >=50 NM jumps, whose neighbors are <50 NM apart and whose
points are >=50 NM from both neighbors. Both maps disclose omitted positions.
Raw telemetry, analysis and unbounded relocations are preserved permanently;
this presentation rule does not establish invalid simulator telemetry globally.

SimBrief identity is stored only after a successful explicit fetch; archived
JSON/XML imports need no account. The next session consumes a staged plan once.
An explicit historical load/file selection attaches without a second prompt;
a changed active session
rejects late results. A separate AIRAC-scoped SQLite cache retains exact
provider
fix coordinates, including ambiguous identifiers; it does not guess unresolved
airway points. Snapshots remain authoritative after cache eviction.

OSM tiles use TLS, an identifying User-Agent, attribution, a seven-day cache and
bounded displayed-viewport requests. Missing tiles retain an explicitly labelled
route-only background. This permanent fallback supports offline postflight work;
its retirement would require an equivalent offline report path. Polar map
latitudes are clamped by Web Mercator; original coordinates remain in
the saved raw data. Gallery credentials and pilot identity never enter tile
URLs.

Regression owners: `test_flight_simbrief.py`, `test_flight_ui.py` and
`test_flight_report.py`. These fixtures do not establish live SimBrief account,
native Windows layout, live simulator or printed PDF acceptance. Real usage
evidence and live provider qualification are currently unknown.

Report maps now allocate native pixel dimensions from PDF points at 300 dpi;
routes, legends and attribution stay vector. Background tile failures never
synthesize ground data. The overview is faded and the final route comparison
uses full contrast. Fetches remain confined to the requested viewport, with a
45-second budget and a stop on the first provider failure. Old low-resolution
reports are retained; template 7 requires a new edition for the changed layout.

Dispatch snapshots add optional flight identity, loading/fuel/schedule fields
and timed fix altitude/fuel. Defaulted dataclass fields preserve old snapshots;
missing old data requires an explicit reload/import, never a silent latest-OFP
substitution. New filename reservations may add flight number and ICAO pair;
existing catalog reservations and migration labels remain unchanged.

`flight_report.py` aligns planned curves only through detected takeoff and
captured simulator time, converting known OFP kg/lb values into displayed mass.
Declared gaps prevent bridging. If anchors/clocks cannot align the plan, the
report owner permanently supports a labelled position comparison for recordings
that began airborne: at least two ordered OFP fixes must match captured legs
within 10 NM, with no extrapolation across jumps/gaps. It compares at positions
and does not invent a takeoff time. Retirement requires equivalent comparison
for airborne-start recordings. Missing matching data/units suppress the overlay
with a visible explanation. Purple dashed plans remain distinct from amber
recorded-gap
interpolation. No delay score, actual passenger count or unseen fuel is
inferred.
New regressions cover source-pixel density, vector routes, simulator-rate
mapping,
unit conversion, legacy snapshot defaults and preservation of old filenames.

Saved-flight regeneration automatically uses an already loaded OFP before the
worker starts. With none loaded, the saved session's OFP remains unchanged.
It creates all four editions without a second confirmation. The next-flight
plan remains staged and historical attachment status is shown
separately. This corrects the observed case where an accepted main-window load
was mistaken for a historical attachment. Focused replay of the affected
captured
flight and an isolated retained OFP confirmed positional profile availability;
it does not qualify a fresh provider fetch or full native UI acceptance.

The SimBrief identity sheet uses the existing dark theme, inline validation and
owner-relative positioning. Main controls retain only the staged/current
flight's
accepted plan and airport pair; failed reloads leave accepted evidence intact.
Saved-flight headings toggle direction with UTC-aware time/count/name sorting,
preserving native expanded groups and multi-selection. Missing pypdf is a
permanent catalog fallback to unknown pages, owned by `flight_jobs.py`; it keeps
old PDFs accessible during source-environment repair. Its retirement requires
an equally independent saved-file recovery path. Missing renderer packages are
reported as dependency repair tasks by `flight_workflow.py`/`flight_ui.py`.
Local diagnosis on Python 3.14.4 recovered page counts for 20 existing PDFs
after
installing the already-declared requirements; original files were unchanged.

The independent update helper publishes a typed result after its durable
completion marker. Only a matching installed version followed by successful
restart closes progress automatically. Reboot-required outcomes and failures
stay visible. `update_helper.py` owns that lifecycle; installer privileges,
checksum validation and restart identity are unchanged. Fake-native
regressions exercise all three terminal outcomes. Live installed-update and
Windows dialog acceptance remain separate.

## Manual flight PDFs and compact recorder readings (0.5.3)

Finish flight is visible below Target gallery, including at compact widths.
Idle API-key mode shows enabled Begin even with a saved key or Photos browsing
link, independently of game detection. It opens the existing API connection
editor; explicit linked upload mode retains its own reconnect flow. Active,
pending and draining sessions keep their existing lifecycle action ahead of
setup, and manual imports retain their controls. This changes discoverability
without changing key persistence, target admission or finalization.
Finish authorizes PDF creation and final revocation without confirmation.
Keep locally and Retry preserve that same process's accepted, unsubmitted intent
for the same saved endpoint/key;
cancellation, changed credentials and restart never reconstruct consent.
An ordinary Stop still keeps the key. Recovery buttons wrap into three columns.
The exact Finish worker owns automatic UI completion: progress closes only
after durable local key cleanup, with no success message box. Manual local
generation similarly creates four retained editions and closes successful
progress. Failures/cancellation stay visible. Missing takeoff/landing no longer
requires a local-only decision; reports retain incomplete facts while required
delivery receipts still gate revocation. No live-key revocation was performed
for source verification; native one-click acceptance remains separate.

The expandable browser opens individual PDFs and uploads selected edition
subsets across retained versions of one flight with the
current saved key, including a replacement key after local-only completion.
Inventory must match the confirmed gallery folder and advertise report support.
No old completion binding/receipt is rewritten, no PDF is regenerated, and no
live flight is stopped. Separate upload-receipts.json retains acknowledged
identities/checksums; the existing server reconciliation owns retry safety.
Shutdown cancels between editions and joins this independent worker. Redacted,
bounded protocol diagnostics identify action, status, media type and response.
Historical records identify the endpoint, not a trustworthy original gallery
folder; the explicit current-destination confirmation owns this manual transfer.
Do not infer the destination from an old key fingerprint.

Owners: `winapp/uploader/flight_ui.py`, `flight_jobs.py`, `flight_workflow.py`
and `flight_recorder.py`. Explicit Finish uses the existing Stop path and keeps
the API key, including when the automatic report preference is off. Existing
accepted-photo draining, valid-landing delivery, scoped auth and Revoke consent
remain unchanged. Saved sessions from historical keys may produce local copies;
local work has empty transport credentials and cannot gain Revoke consent.

Every manual generation gets a unique private revision identity, flat readable
filenames and a
separate `manual-report.json` result. Raw data, earlier PDFs, completion plans
and delivery receipts are preserved. Local generation neither resolves pending
upload work nor claims remote delivery. Stopped partial flights retain the
renderer's incomplete-flight labels; active/unclosed and empty recordings are
rejected. Cancellation retains completed editions, and shutdown/update includes
local rendering in the existing worker drain. Redirected source/report paths
are refused rather than read or written outside the selected session.

`reports/.metadata/catalog.json` journals original sources, readable filenames
and stable report IDs before any rename. Explicit generation/folder opening
flattens old automatic/manual layouts atomically; browsing remains passive.
Sidecars stay in .metadata and raw/completion state outside the PDF directory.
Collisions stop without replacing either PDF. Missing identity catalogs fail
closed instead of inventing upload IDs. Retain old layout support until usage
and migration evidence justify retirement; historical-session usage is unknown.
Regressions cover interrupted journal recovery, exact bytes and retained IDs.

Template 2 replaces raw prose tables with phase, contact and configuration
infographics, enclosing aviation axes and subtle grids. Technical identities
remain in private manifests/PDF metadata. Analysis 2 prevents landing rollout
from arming rejected-takeoff evidence. Gaps and unavailable rates stay explicit.
Optional measured fuel is stored as fuel_weight_kg, null for old recordings;
existing gallons stay intact. Unit heuristics default to kg, use lb for
Boeing/PMDG and gallons for common light GA. Historical mass conversion assumes
0.80 kg/L and is visibly marked estimated. This permanent display policy
supports
volume-only sessions without rewriting their raw data or qualifying aircraft
accuracy. Live optional mass and Windows/PDF delivery remain manual acceptance.

The background-matched IAS/ALT/V/S line consumes fresh values from the existing
recorder handle. Readings expire after three seconds and clear on pause/stop or
unavailable acquisition; UI ticks open no native connection and read no files.
Only the explicit report browser reads checkpoint metadata. No server schema,
API, dependencies or simulator write capability is introduced.

Usage of these controls is unknown. They were requested during an active flight;
the local app was deliberately not launched, stopped or attached for inspection.
Portable regressions cover local/remote isolation, preservation, cancellation,
passive history and instrument freshness/conversion. Actual compact Windows
layout, installed upgrade, simulator and end-to-end report acceptance remain
manual. Retain existing Stop/Revoke and old-session recovery paths until
separate
live and deployed-usage evidence supports a deliberate retirement.

## Windows flight telemetry definitions and diagnostics (0.5.2)

Owners: `winapp/uploader/simconnect_flight.py`, `flight_recorder.py` and the
existing launcher diagnostics. The recorder uses the SimConnect name
`ABSOLUTE TIME` and `GForce` load-factor units on both MSFS generations.
The former `E:ABSOLUTE TIME` RPN spelling and `g` unit are invalid wire inputs.
The observed MSFS 2024 build 12.2.282174.999 repeatedly rejected core telemetry
with SDK exception 7; two supplied sessions contained zero persisted samples
despite successful independent photo-camera acquisition. This is confirmed
deployed regression evidence, not an aggregate usage estimate.

Failures retain exact SDK operations, variable/units, send ID and parameter
index in activity. Known SDK/HRESULT codes carry a name and explanation;
unknown codes remain explicit and original arguments use named fields. Messages
state core/optional impact, while timeouts retain loaded-flight and pause state.
Changed consecutive failure reasons persist in session warnings; connection,
first valid telemetry and first saved sample are logged separately. A later
dependent request error cannot replace the first causal failure.
Copied diagnostics separately expose recorder state, saved
sample count and app version without opening another native connection.
Optional signal failures continue to affect only that signal. No raw schema,
API, existing session files or photograph location behavior changes. Missing
historical samples cannot be recovered from camera success messages.

Portable SDK-rejection fixtures cover both generations. A local read-only
probe could not open a simulator connection, so installed MSFS 2020/2024
acceptance and upgrade-during-flight continuity remain unverified. Keep both
generation paths and durable recovery; these permanent SDK names require no
legacy fallback. Retire support only with separately recorded usage evidence.

## Windows uploader dark interface and recording switch (0.5.1)

Owners: `winapp/uploader/ui_theme.py`, `flight_ui.py` and the existing
`FlightRecorder` pause/resume lifecycle. The watcher automatically arms one
shared photo/flight session. An opted-out recorder waits without opening
SimConnect; enabling it later admits acquisition in that same session.
Pauses preserve captured data and mark the resumed track discontinuity.
The independent report policy still decides whether captured data produces PDFs.
Stop, Revoke, tray shutdown and update drain retain their existing owners.
Saved paths, connection and feature preferences retain their existing per-user
locations and schemas; the redesign requires no re-entry or configuration reset.

The shared theme permanently composites glass panels into opaque RGB pixels
with a matching uniform exterior matte. This avoids Windows Tk black/rectangular
corner artifacts without changing application opacity. Optional-Pillow source
mode retains native controls so dependency repair remains usable; packaged
installers include Pillow. Retire this fallback only with evidence that all
supported launch/repair entrypoints require Pillow. Live source-mode usage is
unknown. Hosted adapter tests cover automatic admission, switch gating,
preference persistence, finalization exclusion and scrollbar overflow. Actual
simulator/aircraft/build and installed-upgrade acceptance remain separate.

## Media-renamer long original filenames

The renamer stages exclusively owned JPG/WebP cache files alongside originals,
removes stale prospective targets, commits original names and rows, and
publishes
cached pixels only after checking their new persisted exclusive owner.
Successful transfers preserve bytes without image decoding. Shared/unverified
legacy caches retain conservative invalidation; locked cache moves fall back to
explicit warnings, while unsafe pre-commit cache removal aborts the original
rename. This permanent fallback protects source ownership and
Windows/shared-host
file locks; usage incidence remains unknown. Gallery selection applies use
15-image browser requests with server-confirmed progress. Native full-form
rename remains supported without JavaScript. No access or serving rule is
relaxed.

Owner: `app/services/media_renamer.php`. Same-directory staging permanently uses
a fixed 83-byte hash basename instead of appending the original name. Production
logs reported staging refusal for 172-byte originals because the former
temporary name grew to 256 bytes. The hosting filesystem type remains
unverified.
Existing source naming, image order, writer ownership, rollback and sidecars
remain supported; no migration or metadata-dependent filename scheme is added.
`media_renamer_staging_test.php` owns real long-name/rollback regression
coverage;
the upload-selection fixture owns gallery-context and continued-numbering
checks.
Live production acceptance and installation-wide incidence remain unknown.

## Attachment masters and accounting snapshots

Database inspection keeps legacy case-insensitive PCRE word-boundary matching
for identifiers outside the ASCII word index. This permanent fallback belongs
to `database_maintenance.php`, because deployed schema names may include
punctuation or non-ASCII characters; actual installation-wide usage is unknown.
Boundary and scoped SQL-evidence regressions protect equivalence. Source
inspection
still distinguishes production SQL, test SQL and lexical mentions without
treating
this supporting evidence as authorization to remove schema objects. No
source-cache
freshness fallback is introduced. A local paired run preserved the full
reference
map while reducing inspection from 4.71 to 1.19 seconds; other-host timings
remain
unknown and are available through the new stage/operation diagnostic breakdown.

With durable thumbnail metadata, new storage jobs finish ordinary-photo
accounting
in their initial source DB pass; only DNG display masters retain bounded disk
work.
The previous full-image derivative cursor remains for schemas without thumbnail
metadata and already-running jobs without `display_masters_only`. This permanent
support belongs to storage statistics; removing it requires complete installed
schema/old-job retirement proof, currently unknown. Required thumbnail aggregate
failures propagate instead of silently authorizing empty fast-path totals.
The observed 1,744-photo run needed 89 file-phase requests despite only 0.40
seconds
of phase work. A 40,000-row regression checks one-request ordinary-photo
completion;
production-scale timing and other-host acceptance remain separate from that
fixture.

Storage statistics no longer degrade required photo-query failures to empty
collections. A local 2026-10-19 run reported 1,744 source photos but zero
derivative
batch rows and false completion. The owner now binds PDO cursor/LIMIT parameters
as integers, propagates required-query errors and rejects premature exhaustion.
The previous catch-and-empty behavior is retired because it cannot distinguish
database failure from completed work. Existing optional thumbnail-schema policy
is unchanged. Real database regression covers native/emulated prepares; job
regression covers failures and retained snapshots. Other-host incidence is
unknown.

Database attachment accounting retains legacy sidecar ownership. A bounded
first-use import reads missing PDF/music/OFP categories, including empty ones;
subsequent updates use SQL totals. This permanent upgrade bridge belongs to
Admin storage jobs and may retire only after every supported installation has
durable accounting coverage; installation coverage is currently unknown.
Explicit reconciliation invalidates derived rows before reimporting out-of-band
changes. Optional accounting failures retain committed originals, log safe
owner/category diagnostics and drop stale categories where possible. A complete
database outage may also prevent invalidation and requires operator recovery.
The regression owner `tests/admin_storage_statistics_test.php` covers repeated
updates with source directories offline and insert-if-absent mutation
protection.
Hosted execution is required to establish regression evidence.

Owners: canonical capability registry, shared upload/editor controllers,
attachment readers and Admin storage statistics. Independent PDF/music masters
default ON when no preference exists, including upgrades; fresh seeding
preserves
explicit OFF. OFF retains files, indexes, preferences and the
incomplete-PDF-child
publication guard. The historical `media&ofp=1` alias remains supported and
applies
the same PDF master. Stored page photos retain their normal image ownership.
Both attachment masters OFF retain the core Presentation card when lightbox is
enabled. The tab is omitted only when all three capabilities are OFF; an old
selected-tab URL then normalizes to Identity rather than an empty panel.

Photo-only cached snapshots/jobs are derived state and cannot establish complete
attachment coverage. The v3 snapshot/v3 job keys do not rewrite original data
or old derived bytes; the first new measurement requires explicit Update all.
The existing optional snapshot argument remains supported for pure/legacy
photo-only consumers and contributes zero attachment bytes when omitted.

The attachment accounting owner omits corrupt indexes and unsafe/unreadable
originals with visible partial-coverage warnings, preserving independent
readable
categories and all original bytes. This is permanent read-only reporting
behavior,
not permission to repair indexes. A post-commit advisory revision write may fail
without failing the successful attachment response. Missing derived categories
are reimported by the next update; complete database-outage recovery requires
explicit invalidation as described above. This permanent fallback avoids
duplicate
retries after optional reporting failures. Out-of-band file changes require
accounting invalidation followed by manual refresh.

Regression owners: canonical feature core/inventory, real MySQL/MariaDB HTTP
workflow and the existing storage-statistics fixture. Real-host library sizes,
permissions and media usage remain unknown; no performance/usage-based
retirement
of these compatibility paths is claimed.

## Gallery soundtrack browser support

Owners: gallery music service/model, protected controller and
`gallery-music-player.js`. Music is additive, stored per physical gallery and
defaults off. Existing galleries without a music index retain silent slideshows
without loading attachment presentation. There is no SQL migration or change to
existing gallery visibility, password/share or NSFW policy.

Native codec and autoplay support varies by browser. The permanent compatibility
path keeps photos running when optional music is refused, exposes an explicit
Play retry for user-gesture rejection, and skips failed sources with one bounded
pass rather than an unbounded retry loop. Viewer teardown retires pending audio
generations and releases the source. Adopted formats/browser versions and live
device usage are unknown; retain these behaviors until deployed browser and
regression evidence proves a specific support path can be safely retired.
Controlled browser and isolated HTTP fixtures are coverage, not live codec or
installed-host acceptance. See `docs/GALLERY_MUSIC.md` and `TESTING.md`.

The same player owns a permanent serialized metadata fallback when
IntersectionObserver is unavailable: connected duration cells share one silent
reader and ten-second deadlines. Unknown duration remains explicit. Live usage
of this fallback is unknown; retire only with supported-browser/deployment
proof.
The music model also owns permanent v1 compatibility: a missing download flag
means false. New tracks always start locked; bulk permission affects current
tracks only. This prevents old indexes or later uploads inheriting permission.

Public media cards retain native disclosure support while their always-visible
totals use the same authorized rows. An unreadable PDF or audio duration leaves
the full total unknown. The shared PDF metadata owner releases each original
before opening the next; no page rendering or database migration is introduced.
Real-host metadata latency and device coverage remain unknown.

Explicit presentation launches temporarily override default-off music without
changing persisted preferences. Native presentation and its manual music
control also honor the stored presentation music preference when the gallery
playlist defaults to off. Both native paths retain the public playback policy
and the current visible track filter. Deferred activation starts audio in the original
click task; shared-link autoplay can still require the existing Play gesture.
The media-panel copy owner permanently supports denied/unavailable clipboard
access through a selectable URL. Retain these browser capability paths until
deployment and supported-device evidence establishes safe retirement.

## Smart Gallery music and visibility compatibility

Owners: music model/service/controller and existing Smart source/presentation
services. V1 records without `hidden` remain visible, preserving historical
behavior; missing download permission still means locked. New uploads explicitly
start visible and locked. Public view/stream boundaries enforce hidden state,
including anonymous preview; administrator hiding controls remain future work.
No SQL migration or format rewrite is required to read existing indexes.

Smart music uses canonical complete source membership and protected source URLs.
Its own `music_enabled` presentation override is absent/false for existing Smart
galleries. A corrupt source index is omitted read-only by the merge service,
retaining original bytes and other sources. Both paths are permanent supported
behavior; deployed adoption is unknown, so retirement needs explicit stored-data
and supported-installation proof. SHA-256 only proves identical original bytes;
acoustic equality across encodings/tags is unknown and is not inferred from
filenames or duration. Hosted service, actual HTTP/DB and browser owners cover
these boundaries; real codecs/devices and large deployed unions remain manual.

Smart hero media/presentation parity uses the existing source-policy, attachment
controller/view and shared lightbox owners without schema or format changes.
PDF/OFP discovery uses canonical complete matching sources, excludes hidden
documents for all audiences and keeps physical endpoint access checks. Existing
ordinary Smart `music_enabled` defaults remain unchanged; explicit presentation
links snapshot common autoplay/music ON and medium speeds without persisting.
This permanent shared path preserves pagination-independent media, current
source
filter/language in share links and the established activation fallback. Adoption
and large deployed PDF unions remain unknown; no retirement is inferred.

## Compact attachment browser presentation

The physical-gallery presentation settings owner is the existing lightbox
service,
gallery model and guarded editor writer. Nullable version-1 JSON keeps
pre-feature
galleries enabled with autoplay, music and medium timings. Unknown
versions/types render
those compatible defaults. Public reads need no new schema probes; explicit
editor/import writes fail before filesystem effects on missing or unknown
schema.
Older forms preserve the stored field. Sidecars, Organizer children and Trash
retain preferences. Live usage statistics for the new settings are unknown.

New links snapshot all supported playback options. Legacy `presentation=1` links
remain supported and `autoplay=1` is an alias. Invalid values cannot introduce
arbitrary timers or change gallery/media grants. Requested automatic fullscreen
or audible playback refusal now holds the decoded first photo behind black and
offers one Start presentation confirmation, including exclusive links. Its
trusted
click requests shared audio before fullscreen without an asynchronous boundary.
After confirmation the existing viewport fallback protects unsupported native
fullscreen; media failures keep player feedback without a repeated prompt.
The glass player still follows ordinary HUD hiding; Photo/Play can retry later.
Stop/Close/explicit silence cancel it. This permanent shared-player fallback
protects browsers requiring activation; retire only with supported-browser and
deployment evidence that audible autoplay is assured. Required Chromium covers
allowed/refused/muted starts and idle-HUD hiding with controlled audio; real
codecs, Safari and physical devices remain manual acceptance.
Autoplay defaults ON until explicitly changed. Share applets snapshot it
alongside
music, speeds and link-only fullscreen/exclusive choices. Exclusive mode
defaults
OFF, hides controls/metadata/diagnostics when selected, and retains Escape exit;
it grants no access and does not bypass browser sound policy. A one-second
minimum
black opening also waits for first-photo decode, uses the existing slideshow
timer
and never repeats as a gallery item. These are permanent supported behaviors;
live usage statistics and real-device permission behavior remain unknown.

The gallery-only `presentation` query flag is allowed through the SEO guard;
it grants no visitor authority. Public direct links must reach the same gallery
controller in a fresh anonymous session. Desktop native fullscreen rejection
keeps `.is-fullscreen` geometry with `.is-css-fullscreen` stacking rather than
entering a mobile-only CSS shell. This permanent viewer-owned capability
fallback
protects links without user activation. Retire only with supported-browser proof
that native fullscreen is assured. Actual Chromium regressions decode synthetic
images, assert nonzero stage geometry, advance from photo one and close/release
audio after controlled native fullscreen/autoplay rejection. Live browser
policy,
codec and mobile-device coverage remains manual; adoption statistics are
unknown.

Owners: `gallery-media-panels.js`, shared lightbox view helpers and music
player.
Native details is the permanent no-JavaScript and missing-Popover fallback: the
bounded list opens inline while forms keep their original ownership. Optional
Web Animations is feature-detected; missing support closes immediately. User
reduced-motion preference disables both disclosure motion and row-title
scrolling.
ResizeObserver is optional: play/open/resize events still measure title overflow
without it. These paths protect attachment access, keyboard dismissal and
exclusive audio without requiring newer browser APIs.

Live browser adoption and low-end device results are unknown. Required Chromium
covers desktop/narrow popup bounds, toggled row state, single audio ownership,
scroll-preserving replacement and missing-Popover presentation. Native
no-JavaScript use, assistive technology, Safari/mobile and reduced-motion
acceptance remain manual coverage. Retain these capability fallbacks permanently
unless deployed support and regression evidence justify a deliberate retirement.

## Windows flight recorder, report delivery and revocation lifecycle

Owners: `winapp/uploader/flight_jobs.py`, `flight_workflow.py`,
`flight_ui.py`, `flight_recorder.py`, `simconnect_flight.py`,
`flight_data.py`, `flight_report.py`, `flight_delivery.py`, and the
gallery-scoped upload automation controller/service.

Template 4 is a presentation-only A4 landscape revision: regular/bold DejaVu,
navy/teal furniture, larger type, paired overview profiles and landing summary /
detail pages. Render manifests invalidate older layout reuse through the
existing template identity; prior PDF bytes, filenames, receipt bindings and
analysis semantics are preserved. The bold font uses the existing DejaVu
license and resource-folder packaging. All four editions share the layout.
Maintainer feedback confirms hosting PDF upload and repeat flight closure on
2026-10-18; BASIC and visual previews do not qualify future native cadence.

The PDF browser adds passive pypdf page inspection and local-time render dates;
legacy missing creation metadata uses a marked file mtime permanently, because
old invariant PDF timestamps do not recover creation time. Existing identities,
PDF bytes and receipts remain unchanged. Plot interpolation is display-only,
bounded to five seconds in series color and 300 seconds amber/dashed; hard
state changes and longer gaps stay separate. Neither landing fits nor telemetry
are filled. Original landing-window frames are retained before overview thinning
with disclosed per-landing/session bounds. Existing in-memory analysis objects
without a raw window permanently retain their overview display fallback;
reanalysis of saved raw data populates the new window. Dense final approach
through sustained
taxi preserves every acquired frame, remains at a 20 Hz target, and returns to
normal by observed speed rather than an invented runway boundary. Native cadence
remains a separate live acceptance check; replacement-key upload was
subsequently reported successful by the maintainer.

Flight HTTP uses the established uploader User-Agent as permanent hosting
compatibility. The reported WEDOS host returned HTML 401 without it and Gallery
JSON with it in a read-only invalid-key comparison. The client does not solve
challenges, weaken TLS/authentication or count a send as a receipt. Per-file
acknowledgements remain required before completion. BASIC only is requested for
this iteration; no installer, FULL or protected integration is implied.

Flight PDF status and receipt validation requires the action, explicit found and
conflict booleans and a report byte length matching the locally hashed PDF.
The current server and the original `flight_reports_v1` owner provide these
fields. Usage of modified servers that omit them is unknown; their ambiguous
responses are refused without retrying multipart data, and source PDFs remain.
The legacy image upload protocol and `pgu_` authentication remain unchanged.
An already stored plan receipt records a historical acknowledgement, not a
fresh check that the PDF still exists on the server. HTTP 422 can follow server
publication, so the client performs a passive status check before reporting the
outcome; no multipart replay is implied. Concurrent source writes and live
Windows behavior remain unqualified. The saved-file confirmation identifies a
revision and path, not a hash captured at dialog time; delivery captures and
rechecks the hash later without an atomic guard against unrelated writers.


Recording and analysis support the shared MSFS 2020/2024 provider path; missing
aircraft/build signals remain explicit unavailable data. Recording defaults on
with an independent opt-out. Report creation defaults on and language selection
defaults to EN/CS/DE/SV; local output uses bundled ReportLab and DejaVu fonts.
The gallery report capability is optional and negotiated. Reports are separate
protected PDF documents, inherit gallery visibility and do not replace OFP.
Stable report identity/language/checksum and status reconciliation protect
retries; no raw telemetry is uploaded and no database schema is added.

Standalone Stop uses the durable coordinator to stop the watcher, its owned UTC
synchronizer and flight recorder, then drain accepted photographs and complete
required report delivery. Unrelated AI, manual-import and preflight workers
remain active, and the key stays configured. Confirmed Revoke uses that same
Stop flow, additionally stops other authenticated producers and finishes
accepted work before requesting scoped server revocation. Failures retain local
data and task state; the UI shows phase, elapsed time and an ETA only when
remaining work is measurable. After restart the app restores work but requires
an explicit Finish click or direct Revoke confirmation before revocation.
Local-only resolution is
explicit and does not count as server delivery.

Automated provider and transport fixtures are not live qualification. Actual
MSFS 2020 and 2024 simulator/aircraft/build coverage, achieved sampling cadence,
installed-app recovery and report rendering acceptance remain pending until
recorded separately for each generation. Retain both provider paths and local
report recovery unless live evidence and deployed-usage evidence support a
deliberate retirement.

## Watch-folder UTC synchronization and packaged Python

Owners: `winapp/uploader/simconnect_time.py`, the watcher GUI and updater drain.
The saved `sync_utc_time` field is additive and defaults off for existing
configurations. Both identified MSFS 2020/2024 connections use the common clock
API, separate from per-photo camera/aircraft connections. Missing or
incompatible
simulators preserve the preference and uploads. Three failed corrections suspend
only that connection; reconnection probes capability again. This is permanent
optional-host support, not a reason to remove either simulator generation.

The regression report concerns an MSFS 2024 new-flight UTC mismatch. Adoption
and live behavior of this watchdog are unknown. Native-wire tests cover both
identities, state transitions, date/readback and cancellation. Exact live
day-setter indexing and Live Time/Live Weather effects remain unverified for
both generations; retain the common route until live evidence justifies a
specific adapter. Never substitute accepted HRESULTs for clock readback.

Installer preparation selects x64 CPython 3.14.patch and verifies the actual
frozen interpreter before packaging. Ordinary source execution retains its
existing compatibility; no app version bump accompanies the interpreter check.
Installed release/upgrade acceptance remains separate from build mocks/BASIC.

## Reviewed main ancestry and historical synchronization receipts

Owners: release assembly, completion, policy, origin and reconciliation readers.
Current schema-4 completion fast-forwards unchanged selected develop to the
actual
reviewed/tagged main merge M. A `RECONCILED_FF` receipt binds M/tree/source and
retirement verifies original assembly, owner approval, source ancestry and
retained
M. Equal contents in a sibling commit are insufficient. Readiness and PR
creation
block the old merge prohibition; only the maintainer changes installed policy.
Non-force updates, exact Actions checks, no bypass and concurrent-ref protection
remain required. No content-copy commit or forced protected update is generated.

Published schema-4 `RECONCILED_EQUIVALENT` receipts (observed through v_0.133.3)
retain their original single-parent result above selected develop, complete tree
and authenticated assembly/approval. Readers keep that historical format while
its published receipts remain supported; later reviewed main FFs may descend
from
the retained result. Historical schemas retain their original linear-Q FF and
reconciliation proof readers. Existing divergent refs are joined by a later
release retaining current develop, or by a separately authorized migration.
No published tag, origin, receipt or asset is rewritten to change the graph.

Protected develop is the source authority for new initialization even when a
prior release Q is not its ancestor; old reconciliation assets and historical
test archives are not new-release prerequisites. Existing published origins,
tags and assets stay immutable. This supports published releases whose manual
conflict resolution added a merge to Q (observed in v_0.133); adoption outside
that incident is unknown. Keep historical receipt readers for those published
graphs; new completion retains main ancestry directly. Early approval
uses the post-assembly observer; normal Git push acknowledges origin publication
without a redundant cached REST readback. Live main-following synchronization
awaits reviewed policy installation and a separately human-approved release;
registered real-Git regressions own
tree preservation, concurrency refusal, idempotence and successor
initialization.

## Gallery title-picture selection compatibility

Owner: gallery cover model/service, editor mutations, sidecars, and the existing
public mutation completion coordinator. `galleries.cover_image_id` remains the
legacy primary reference; nullable `cover_image_ids` adds opt-in Automatic and
ordered multi-photo modes without changing older rows. The protected behavior
is the established single-cover and child-collage output for installations that
have not applied the additive migration or whose row remains `NULL`. Multi-photo
writes fail closed when schema readiness is missing or unknown. Public cards
recheck current image visibility and gallery access; they never trust Admin
selection alone. The writer lease serializes edits, and the public refresh
fingerprint includes ordered selection and uploaded-cover path.

The historical issue/commit records an Admin bulk-route HTTP 500 caused by
missing selectively loaded cover-service declarations; generated runtime plans
must continue to be produced from authored dependencies and qualified by the
hosted workflow. Historical feature adoption is unknown. Keep the legacy path
and access checks indefinitely for existing galleries; retire the compatibility
branch only after explicit installed-schema and deployed-data evidence proves
no supported installation requires it. Regression owners are listed in
`docs/TITLE_PICTURE_SELECTION.md` and `TESTING.md`; browser and hosted
qualification remain separate evidence.

## Owner-selected feature qualification and protected-develop releases (#235)

Current native owner contract 4 retains all functional shards and mandatory
Linux/Windows packaging among 27 required jobs. Integration dispatches macOS
packaging separately from the selected feature revision for the exact prepared
candidate/base. Its genuine result remains advisory even when a runner is
unavailable, times out or fails. Owner: `.github/scripts/ci-owner-contract.mjs`
and the integration/reusable workflows. On 2026-10-28, integration runs
`40725313291` and `40729700051` could not acquire a macOS runner and ended with
cancelled packaging despite successful Linux/Windows packaging. The second run
passed all functional owners. This observed capacity failure motivates the
independent boundary; it establishes no production macOS adoption.

Historical contracts 1–3 preserve mandatory macOS and their original owner
counts. Readers authenticate complete Git-hashed workflow bytes before choosing
the owner contract; missing native jobs never select a newer contract. Keep
these readers until supported retained integration/release consumers are proven
absent; their current usage is unknown. Regression fixtures reject missing
Linux/Windows and historical macOS owners, preserve real adverse advisory
outcomes, and exercise dispatch refusal without a ref write or run wait.

Owners: integration/evidence and release origin/promotion readers. New
integration
schema 2 permits explicit owner-selected feature workflows and seals successful
FULL before publication. New release schema 4 trusts the frozen protected
develop
source and records assembly, without reopening older source qualification.
Historical integration schema 1 and release schemas 1–3 remain supported for
immutable existing records. Their actual usage is unknown; retain their readers
until supported historical origin/completion consumers can be proven absent.
The old finite 0.132.9 bridge below is historical compatibility only. New
releases
resolve protected main first-parent history to the unchanged stable tag without
another version-specific exception. Regression coverage includes complete
adverse
native owners, changed inputs, corrupt archives, release assembly failures and
Git/tag identity preservation. Live acceptance is pending hosted qualification
and human-reviewed installation; no production publication is part of this
batch.

Protected publication retains Checks IDs and an optional `matrix_status_id` from
the same verified FULL. Commit Statuses cover GitHub's dispatch-suite rollup
behavior; the publisher verifies the exact status ID, SHA, workflow URL, state
and Actions creator before the unchanged guarded FF. Preserve both supported
provider representations and historical records; neither changes test ownership.

## Reviewed untagged dispatcher maintenance merge before 0.132.9

Owner: `release-origin.mjs`, `start-new-release.mjs` and the shared release
promotion readers. PR #230 advanced main through normal human-reviewed protected
merge while the existing `v_0.132.8` tag stayed immutable. The old origin
equality
assumption would reject the next release. The finite `0.132.9` bridge preserves
both truthful identities and authenticates the exact maintenance merge, human
review, immutable caller/matrix blobs, twelve native CI owners, exact-SHA check
and complete archive/provider digest. No arbitrary main advance or other version
is admitted. Usage is one observed maintenance installation; future usage is
unknown. Registered origin, initialization and hosted-policy fixtures cover
positive selection, archive/provider corruption, altered PR/review/workflow,
missing/skipped/duplicate native owners and changed check identity. Retire the
exception only after `0.132.9` is published with permanent provenance and no
supported origin, qualification or historical completion consumer needs it;
preserve the tagged release evidence. Other releases retain ordinary equality.

## Retired deploy files and installed updater ownership

Owner: `app/release_file_policy.php` and the updater activation/obsolete-file
planner. Historical deploy entrypoints are removed from the repository and new
production inventory. Historical path recognition remains solely to read prior
schema-1 inventory and manifest evidence on supported older installations;
usage is **unknown**. `deploy.bat` and the two scripts under `scripts/` were
updater-owned. Their removal uses the existing trusted installed sidecar or
manifest, guarded activation and rollback. Unknown files must never be deleted
based on a familiar filename. Root `deploy.sh` was outside updater ownership
and is preserved on such installations. `deploy/.htaccess` remains a shipped
security rule protecting historical package directories. No schema migration or
blanket name-based deletion is introduced. This read compatibility is permanent
until supported installed releases no longer need older ownership records;
removal requires migration/installed-consumer evidence. Regression owners are
`production_file_policy_test.php`, `updater_inventory_compatibility_test.php`,
`updater_resumable_state_machine_test.php` and the compact production package
test.

## Admin log clipboard and native text downloads

Owner: `admin-logs.js` and the existing `admin_log_export` controller/service.
The native selected-text copy command is attempted first because the embedded
browser's asynchronous Clipboard API acknowledged writes to its browser-session
clipboard without updating the user's Windows clipboard during #219 acceptance.
The standard asynchronous API remains the fallback when native copying is
absent.
HTTP installations or browsers denying asynchronous Clipboard API access retain
the full diagnostic text in a selectable modal; no successful copy is reported
when clipboard writing fails. Native authenticated TXT links and scoped POST
downloads remain supported without JavaScript. This is permanent support for
ordinary hosting and browser permission policies; usage is **unknown**. Retiring
the manual-copy alternative requires acceptance evidence for supported HTTP and
permission-denied environments. Regression evidence belongs to
`tests/admin_log_export_selection_test.php`,
`tests/fixtures/admin_log_exports.mjs`
and the browser verification in #219. Native HTTP downloads are retained because
Blob URL downloads did not produce a file in the embedded browser; acceptance
verified actual UTF-8 files containing six group events and eleven selected
events in Windows Downloads. Ordinary clipboard and HTTP download support
remains
permanent until supported-browser acceptance proves an equivalent replacement.

## Admin bulk-list Shift selection

Admin bulk lists with item checkboxes support Shift-click ranges within the same
list. Clicking one row and then Shift-clicking another applies the second
checkbox's resulting state to enabled visible rows between them on the current
results page. The range anchor resets when live filtering, paging, or another
row replacement changes the results. Severity filters still determine visible
log rows, and the Selected, This page, and All filtered log export scopes retain
their meanings.

## Independent updater cache reclamation

Owner: updater filesystem/prune cleanup and site-maintenance service.
The existing three-argument `application_update_delete_tree_slice()` API remains
supported with an optional relative cursor and additional result fields.
Existing
updater/CLI callers retain their `removed`, `done` and `elapsed_ms` fields. The
protected environment is PHP 8.1+ shared hosting; cursor-free calls remain valid
for bounded shallow updater work, while the independent GC persists depth state.
This is permanent support for existing callers rather than a timed fallback.
Usage is unknown. `tests/updater_cache_gc_test.php` supplies synthetic safety
and
progress coverage; hosted FULL and production acceptance are distinct gates.

## Develop required-check propagation

Owner: `.github/scripts/develop-integration.mjs`, `integrateDevelop()`.
Integration
[38899855917](https://github.com/klusik/PHP_gallery/actions/runs/38899855917)
passed FULL but GitHub rejected FF one second after creating its required check.
Propagation is the suspected cause; frequency is **unknown**. The narrow retry
retains exact evidence and leases, rejects other/ambiguous errors and is covered
by `tests/develop_integration_test.mjs`. Keep this provider compatibility until
GitHub guarantees immediate enforcement visibility and hosted acceptance proves
safe removal. Feature fixtures do not prove protected installation or live
recovery.

## Retained Actions job inventory provider failures

Owner: `.github/scripts/release-promotion.mjs`, `command()` and `fetchJobs()`.
Release qualification must independently read the complete job inventory of the
exact retained integration attempt. GitHub returned HTTP 502 for the 100-job
page of integration run 38465847308 attempt 2; a complete one-job-page read
returned all 16 jobs. This is a provider read failure, not failed Gallery
runtime
qualification. Usage frequency is **unknown**; the incident is documented in
issue #202.

Only an explicitly classified HTTP 5xx allows one alternate paginated read with
one job per page, on the identical run and attempt. Permission,
missing-resource,
malformed/incomplete evidence and unclassified command errors remain terminal.
The smaller-page read retains total-count completeness, exact check identities
and all mandatory job gates; it cannot authorize writes or accept partial proof.
Regression coverage belongs to `tests/hosted_release_policy_test.mjs`.
Retire the fallback only after supported GitHub retained-job collections have
reliable large-page reads and exact-coverage regression acceptance remains
intact.

## Public iCloud exports and bounded ZIP sources

Owner: `admin-icloud-source.js`, `admin-icloud-download.js`, the existing
browser
uploader and image worker. The observed anonymous Photos sharing protocol can
produce CORS-readable compatible ZIPs for the owner-provided Shared Album. It is
undocumented and may change; strict schema/host/availability failures retain the
explicit manual alternative. No Apple credentials, cookies or Gallery proxy are
introduced. Historical Photos fragments and Shared Album `#/sc,` redirects
remain
separate source inputs. Usage is **unknown**; live Photos Link, physical
large-source
and Safari/Firefox/mobile qualification remain separate evidence requirements.
Retirement of the manual alternative requires those supported-source/client
acceptance proofs; it is not inferred from one successful Shared Album export.

Manual ZIP selections and automatic sources use the existing pull-driven
worker/batcher seam, bounding one original to 64 MiB/the effective server limit
instead of retaining every image. Automatic downloads use OPFS; manual picker
Files are already disk-backed and need no OPFS copy. The former manual
aggregate cap rejected an observed 259.9 MiB screenshot archive before upload;
the preview also incorrectly compared that local source with a 48 MiB PHP
request limit. Manual imports now retain metadata/cursors and exact pending ZIP
batches for retry, preserve mixed-selection order, and inspect all selected
archives before any server write. The inherited ZIP worker owner covers sparse
80 MiB declared-original admission, real worker extraction, mixed order,
integrity skips, exact lost-ACK replay and malformed-later-archive refusal.
These synthetic scenarios do not measure real browser-process memory or prove
Safari/Firefox/physical large-archive acceptance; that usage remains unknown.
Both modes retain 4 MiB directory, 10,000-entry and 2 GiB expanded-data bounds,
and
explicit
ZIP64/multi-disk refusal. Apple's actual exported ZIP appends 205 zero bytes
after
the end record. The shared parser accepts only all-zero suffixes up to 4 KiB,
with unchanged directory/entry/CRC/signature checks; nonzero trailers remain
invalid. Permanent compatibility is justified by ordinary ZIP-library acceptance
and the observed Apple producer; regression fixtures cover both acceptance and
hostile/nonzero/oversized suffix refusal. Tail allocation is at most 69,653
bytes.
Disk files are locked across active/retry work, removed on success/safe failure,
and aged unlocked orphans are reclaimed at the next acquisition. Synthetic
protocol/storage/incremental/replay and existing PDF scenarios run under the
inherited central ZIP owner; BASIC alone does not execute them.

## PDF conversion generations after target loss

Owner: `app/services/gallery_pdf_conversion.php`. Existing v1 markers omit
`render_key` and `generation`; their conversion identity remains the render key
and they remain generation zero. Confirmed replacement of a missing or changed
child creates a new identity while preserving prior markers and their
publication
guards. Empty newer generations advance rather than replacing generation zero.
Legacy-marker usage is **unknown**; preserve this interpretation until supported
installations have an accepted migration. The lifecycle fixture contains
generation-selection, old-marker preservation and empty-orphan assertions;
functional execution remains deferred during BASIC-only implementation.

## Gallery PDF index visibility defaults

Owner: `app/services/gallery_pdf_documents.php` and protected PDF delivery in
`app/controllers/public_media.php`. Existing v1 attachment indexes omit the
manual
`hidden` flag and legacy `ofp_hidden` envelope value; both default to visible to
preserve previously shared documents. New hidden state is enforced for visitors,
including share tokens and anonymous Admin preview. Replacement and index
rewrites
retain those values. Usage of legacy indexes is **unknown**; keep this fallback
while supported installations can contain v1 records without visibility fields.
Retirement requires a verified migration and supported-installation acceptance.
Regression assertions are authored in `gallery_pdf_documents_service_test.php`
and `simbrief_ofp_public_http_test.php`; execution is deferred during BASIC-only
implementation and must not be claimed as verified functional coverage.

## Publication refresh and normal automatic release retirement

Pages handoff protects the main-only `github-pages` environment. A main checkout
cannot change a release run's tag ref (observed run 38225431172).
`project-website-dispatch.mjs` validates latest stable publication and
Actions-based
Pages, then dispatches the same workflow only on main. The handoff has no Pages
write or OIDC token. Legacy Pages setup requires explicit owner configuration.
`hosted_release_policy_test.mjs` covers wrong contexts, superseded/draft
releases,
legacy configuration and the exact allowed dispatch.
Website evidence uses the existing owned DevTools browser session after fonts,
images and layout assertions, replacing the CLI screenshot startup that timed
out in run 38230411649. PNG format, viewport width and all six evidence files
are
required; native route, keyboard, contrast and overflow checks remain enforced.

The reconciliation owner recognizes automatic completion when protected linear
develop retains qualified Q. It checks durable published bytes, original native
qualification (schema-3 integration/delta transports included), immutable tag
object/tree/parents and live effective owner approval of the exact bot PR. This
removes dependence on the retired reconciliation workflow for automatic FF;
legacy equivalent-replay receipts keep their path. Missing permanent evidence
stays BLOCKED with instructions to attach the original completion artifact.
Cleanup never generates/uploads a replacement receipt. Tests in
`release_retirement_test.mjs` cover isolated real merge/tag/FF, red
qualification,
absent/altered approval, forged receipt fields and missing evidence/Q ancestry.
Expired native qualification artifacts remain a blocker. Live
deployment/retirement
acceptance is unknown until maintainer execution. Keep legacy parsing until all
published receipt consumers are migrated.

Reviewed 2026-10-06 for Issue #73. This is an evidence-backed inventory, not a
guarantee that every path is exercised in production. Approximate eras mean
“visible in current code/evidence by this time”; they do not claim the first
release that introduced the behavior. Usage is marked unknown unless an
existing product metric actually observes adoption.

## Policy

### CI recovery — retained integration artifact protocol

- **Reason / protected behavior:** Existing immutable release origins may select
  a
  FULL-qualified develop SHA advanced outside the integration action. A
  main-owned,
  owner-dispatched recovery qualifies that same SHA without changing any ref.
- **Owner:** `develop-integration.yml` and `develop-integration.mjs`; release
  consumers
  continue using the existing `coverage-artifacts.mjs` and schema-1 integration
  proof.
- **Permanent support rationale:** Keep retained origins and their selected
  commits
  usable. Recovery records preserve real historical ancestry and fresh same-run
  native
  FULL owners, with explicit no-ref-write metadata. There is no red override or
  forged
  replacement of historical checks, and ordinary integration guards remain
  strict.
  Recovery does not relax main/predecessor pins; an origin frozen before
  installing
  this authority cannot be moved to the newer main and remains historical
  evidence.
- **Evidence / usage:** `tests/develop_integration_test.mjs` covers
  owner/attempt/ref
  rejection, old and fresh FULL owners, metadata races and unchanged
  release-consumer
  verification. Live recovery usage is **unknown** until a reviewed installation
  on
  main and successful owner recovery are recorded.

### CI-1 — Local maintainer recovery after CI-first migration

- **Reason / protected environment:** Maintainers may need existing local
  generators/audits when GitHub is genuinely inaccessible or for explicitly
  requested environment-specific diagnosis. Red or slow CI is not inaccessible
  CI.
- **Owner:** AGENTS.md owns agent execution; RELEASE.md and the existing
  canonical
  generator/audit scripts own recovery. No parallel runner or test registry
  exists.
- **Permanent support rationale:** Keep local development and emergency recovery
  possible without treating local PASS as hosted qualification. Ordinary agents
  commit/push only an explicitly authorized working branch; protected release
  workflows own approved promotion/publication. Production-host smoke stays
  manual.
- **Regression evidence:** `tests/hosted_release_policy_test.mjs` exercises
  missing,
  stale and red evidence refusal, exact overrides and CI-first/branch contracts
  through the central hosted Node registry. Actual hosted write-mode acceptance
  requires configured server protections and independent environment approval.
- **Usage / introduction:** Local recovery usage is unknown; this policy
  transition
  was authored for #100/#124 on 2026-10-08, without inferring older adoption.

Compatibility is a supported behavior when users, installed data, older
clients, host configurations, or documented no-JavaScript flows rely on it.
Keep such behavior until its owner demonstrates the retirement condition below.
An unsupported platform policy may be a retirement candidate without implying
that a corresponding production branch exists.

When adding or materially changing a fallback or compatibility branch, record
in the pull request: its reason, the concrete old behavior/version/environment
it protects, its component owner, and the earliest safe retirement condition.
For high-cost or cross-cutting behavior, add or update a record here. State
“unknown” for unmeasured usage or uncertain introduction dates; do not infer
adoption from a code path, log event, or earliest Git path alone. Removal must
include migration and release-note implications where applicable, and a
regression/acceptance proof for the retired scenario. This is review metadata,
not a source parser or a commitment to remove anything on a calendar date.

### UI-3 — Clipboard exposure and writable FileList availability

- **Reason / protected environment:** Desktop browsers or clipboard sources may
  expose no image files, and older/restricted browsers may not construct a
  DataTransfer or assign an input FileList. Their established chooser/drop and
  ordinary no-JavaScript POST uploads remain usable.
- **Owner:** `admin-upload-selection.js` owns clipboard extraction;
  `admin-upload-queue.js` and `admin-upload-preview.js` own the right-drawer
  local selection, previews and disposal. Existing upload controllers/services
  and the side-panel completion owner retain persistence, progress and security.
- **Evidence:** `admin_upload_clipboard_test.mjs` and the registered
  `admin_upload_clipboard_browser_test.mjs` cover file-only exposure, selection
  preservation on assignment failure, ordinary multipart submission and dynamic
  panel controls. The OS/browser matrix in `TESTING.md` remains manual evidence.
- **Usage:** Clipboard API availability and fallback frequency are `unknown`; no
  new telemetry is collected.
- **Support rationale:** Chooser/drop and no-JavaScript POST remain permanently
  supported input methods. Clipboard input is additive and may never require
  permission polling or a second upload endpoint.

### UI-4: Bounded local miniature fallback

- **Reason / protected environment:** A browser may lack `createImageBitmap` or
  canvas encoding, reject a codec, or receive animated, oversized, unknown or
  metadata-heavy image data. Preview admission must not narrow the existing
  server upload formats or replace the original with a thumbnail.
- **Owner:** `admin-upload-thumbnail.js` owns bounded raster admission and
  serialized decoding; `admin-upload-preview.js` owns format fallback and
  URL/callback disposal. Upload processing, metadata and authorization remain
  with the existing transport/service owners.
- **Evidence:** Registered `admin_upload_thumbnail_test.mjs`, the expanded
  clipboard Chromium fixture, and the real Worker/ZIP operation-key fixture
  cover fallback, original preservation, resource bounds, reset and uncertain
  acknowledgment. Actual OS clipboard/browser results remain in the `TESTING.md`
  manual matrix.
- **Usage:** Decoder support, oversized-image frequency, fallback usage and real
  browser-process memory are `unknown`; no telemetry is added.
- **Support rationale:** A missing local miniature is permanently allowed for an
  otherwise uploadable original. Retirement would require a separate accepted
  browser/format support-policy change, not merely a passing Chromium test.

## Database and migrations

### WIDGET-2 — Public geometry without resize observation

- **Reason / protected environment:** Browsers without `ResizeObserver` must
  still display the original published article and retain viewport/scroll-based
  floating placement. Dynamic control-size invalidation is an additive browser
  enhancement.
- **Owner:** `setupPublicContentWidgets()` in `public-content-widgets.js` owns
  observation and the shared bounded geometry scheduler.
- **Evidence:** The public placement Chromium fixture covers size changes of
  protected controls with observation available; it does not qualify older
  browser engines. Server-rendered and no-JavaScript coverage remain independent
  of this API.
- **Usage:** Browser API availability and fallback frequency are unknown; no
  telemetry is added.
- **Support rationale:** The original in-flow article remains permanently
  supported. Removing the observer guard requires an accepted browser-support
  policy and proof that supported engines provide the API.

### WIDGET-1 — Unsupported persisted source-language metadata

- **Reason / protected scenario:** A restored, older or directly edited
  published widget may have missing or unsupported optional `source_language`
  metadata. Public reads label that content as English so the otherwise valid
  title and body remain visible; current Admin writes still reject unsupported
  codes.
- **Owner:** `public_widget_normalize_public_row()` in
  `app/services/public_content_widgets.php` owns the read fallback.
  `public_widget_normalize()` remains the strict validation path for Admin
  writes.
- **Evidence:** `tests/public_content_widget_public_render_test.php` checks the
  safe fallback label and strict-write rejection. The owned MySQL/MariaDB
  `tests/public_content_widget_db_workflow_test.php` writes an unsupported code
  directly to its disposable row, then verifies the public reader and planner
  keep the content visible with an English label and do not rewrite storage.
  `docs/PUBLIC_CONTENT_WIDGETS.md` defines the visitor-facing language behavior.
- **Usage / introduction:** Unsupported stored values are unmeasured; the
  introduction date of any such rows is unknown.
- **Support rationale:** Keep this fallback while imported, restored or directly
  edited rows can contain optional unsupported language metadata. Retirement
  requires a measured inventory and a documented data-repair policy that
  guarantees those rows cannot silently disappear from public pages.

### UI-2 — Authored metadata language and source-field compatibility

- **Reason / protected scenario:** Existing galleries and photos may have no
  translation row or source-language tag. Gallery fields fall back
  independently;
  an existing photo translation retains blank caption fields. Admin source
  editors must never save a presentation overlay as canonical text.
- **Owner:** `app/services/content_localization.php` and request language policy
  in `app/services/translations.php`. Admin and public browser preferences stay
  independent; explicit language arguments remain supported for existing
  callers.
- **Evidence / tests:** `content_request_language_test.php`,
  `content_localization_model_test.php`, and
  `content_language_workflow_integration_test.php`; investigation scope is in
  `docs/ISSUE_93_LOCALIZATION.md`.
- **Usage:** Missing authored translations and legacy cookie adoption are
  unknown.
- **Support rationale:** Source fallback and explicit language arguments are
  permanent compatibility contracts. Legacy cookie removal would require an
  accepted migration of persisted browser preferences and regression proof.

### UI-1 — Breadcrumb preferences missing on upgraded installations

- **Reason / protected scenario:** Existing galleries and installations have no
  breadcrumb preference. Missing or obsolete gallery values inherit the Theme
  default; missing or obsolete Theme values use the built-in `chevron` style.
  Existing route destinations and server-rendered navigation remain available.
- **Owner:** `app/services/breadcrumbs.php`, the shared view, and gallery/Theme
  settings owners. Physical gallery overrides use existing application settings;
  no optional schema probe or manual migration is required.
- **Evidence / tests:** `breadcrumb_component_test.php`,
  `breadcrumb_gallery_settings_test.php`, `breadcrumb_theme_settings_test.php`
  and the registered `breadcrumb_browser_test.mjs`.
- **Usage:** Preference absence and obsolete identifiers are unmeasured
  (`unknown`).
- **Support rationale:** Missing preferences and unknown style identifiers are
  permanently supported upgrade states. The public gallery helper delegates to
  the shared renderer so existing page composition retains its include contract.

### DB-1 — Legacy migration definition shapes and direct-require runner

- **Reason / protected scenario:** `load_migration_definition()` accepts both
  the historical SQL-list definition and the `{statements, after}` callback
  form. Repair migrations scoped to a PDO and returned an empty list for old
  direct-require runners. This preserves legacy migration consumers while new
  definitions use callback-aware semantics.
- **Era / owner:** Callback API evidence exists by 2026-07-12; original
  introduction is unknown. Owner: `app/migration_definitions.php` and
  `database/migrations/`.
- **Evidence / tests:** `app/migration_definitions.php`,
  `migration_consistency_test.php`,
  `migration_legacy_runner_compatibility_test.php` (repair files and
  conditional maintenance).
- **Usage:** External direct-require runner adoption is unknown.
- **Earliest safe retirement:** Only after the oldest supported installer or
  updater no longer directly requires migration files, all supported runners
  consume callback-aware definitions, and the direct-require contract is
  explicitly retired. Do not mass-convert immutable historical migrations.

### DB-2 — Applied migration ledger rows with no current file

Updater owner: `updates_jobs/activation.php` with the schema-inspection model.
The c2e2bc77/0.144.3 updater incorrectly required every historical file;
the supplied F8D315905480 job supports this failure path but does not identify
the exact missing file or production ledger. Evidence format 2 preserves all
rows/timestamps and schema, records manifest-proven historical absence as null
and rejects missing declared files. Unversioned saved witnesses retain their
strict original checks; unknown formats refuse. The recovery integration
fixture covers actual historical SQL rows, file corruption, changed ledger and
schema, legacy witnesses and completed rollback. Live upgrade acceptance is
unknown. Permanent support follows the ledger contract; removing the reader
requires proof that no supported stored recovery witness needs it.

Legacy `activate` checkpoints without a first-write receipt require complete
original-byte/backup proof under all three owner locks before pointer release.
New activation intent is persisted before the gate. The fixed Admin controller
also hands off its initial bootstrap lease after authentication and CSRF.
Disposable byte/lock/adverse-state fixtures cover both; real hosting use is
unverified. Retain the old-checkpoint path while deployed unfinished jobs exist.
The public `attempts` field keeps its historical lifetime worker-request
meaning; new labels explain it and runtime waiting is additive. Missing waiting
fields in old jobs default to false. All four catalogs and synchronized
server/browser cards retain support for already stored jobs. Worker failures
gain a private structured receipt and confirmed-log/PHP-log fallback, without
changing public error redaction. The disposable fixture covers logging failure
and absence of secrets; actual hosting PHP-log delivery remains unverified.

- **Reason / protected scenario:** An already-applied `schema_migrations` row
  remains history even when its migration file has been removed; it must not
  reappear as pending work or be silently discarded.
- **Era / owner:** Era unknown. Owner: `app/migration_definitions.php` and
  `app/migrations.php`.
- **Evidence / tests:** `pending_migration_files()` and migration-history
  handling; `migration_consistency_test.php` uses simulated files and applied
  version arrays to cover unknown historical versions, pending files, and a
  fully applied set. It does not insert live database ledger rows.
- **Usage:** Ledger-row frequency is not measured as a product metric.
- **Lifecycle:** Permanent history protection under the current ledger model.
  Reconsider only as part of an explicit, versioned ledger-compaction design
  with backup/upgrade proof; no current removal is planned.

### DB-3 — Duplicate DDL reconciliation on migration replay

- **Reason / protected scenario:** MySQL/MariaDB DDL can partially persist when
  a process stops before recording the migration. Known duplicate-object errors
  are reconciled on replay so the ledger is recorded only after the resulting
  schema is correct, including non-transactional DDL.
- **Era / owner:** Evidence exists by 2026-04-28; origin unknown. Owner:
  `app/migrations.php`.
- **Evidence / tests:** `apply_migration_statement()` and
  `migration_schema_cache_reset_test.php`.
- **Usage:** Replay events are not an adoption measure.
- **Lifecycle:** Retain for supported MySQL/MariaDB families unless an
  equivalent, equally durable partial-DDL recovery guarantee replaces it.
  A single-engine transaction assumption is not a retirement proof.

### DB-5 — Layout repair before first-install configuration

- **Reason / protected scenario:** `install.php` runs the layout-semantics
  callback before `config.php` and the first administrator exist. The repair
  must
  use the installer PDO for the shared writer lock and the installer-owned
  `galleries/` root rather than assume application bootstrap has run. Configured
  upgrades retain their configured gallery root and legacy/persistent Trash
  roots.
- **Era / owner:** Repair for issue #123, reported on 0.124.3. Owner:
  `gallery_description_layout_compatibility.php` and the canonical gallery edit
  model.
- **Evidence / tests:** `installer_first_install_test.php` exercises config-free
  HTTP installation, busy refusal and retry;
  `gallery_description_layout_compatibility_test.php`
  retains upgrade, fresh-state and interrupted sidecar/database replay coverage.
- **Usage:** Unknown; the issue documents one failed installation.
- **Lifecycle:** Permanent while standalone pre-configuration installation is
  supported. Retirement requires an equivalent tested installation protocol that
  supplies configuration before callbacks without weakening writer exclusion or
  replay.

### DB-4 — Unsupported database-floor and former query-workaround rationale

- **Reason / protected scenario:** Historical support notes explain old server
  floors and a former Smart Gallery window-function-avoidance rationale. The
  current support policy lists MySQL 8.4 and MariaDB 10.11/11.4 as required
  maintained workflow representatives; no runtime branch based on server
  version was found.
- **Era / owner:** Policy review is current in `docs/DATABASE_SUPPORT.md`;
  query/index rationale predates this review. Owner: database support policy
  and Smart Gallery query/index owners.
- **Evidence / tests:** `docs/DATABASE_SUPPORT.md`,
  `tests/database_engine_contract_test.php`, direct database workflow.
- **Usage:** Unsupported-version installations are unknown; CI measures only
  the required representative engine tuples.
- **Retirement status:** Explicit policy/documentation candidate, not a proven
  removable runtime branch. Revisit the unsupported floor and former query
  rationale only after correctness, performance, and safety review of the
  query and digest index. Keep current query and index behavior until that
  review supports a change.

## Transport compatibility

### HTTP-1 — Explicitly trusted TLS-terminating proxies and HTTPS wrappers

- **Reason / protected scenario:** Shared-hosting proxies may deliver browser
  TLS
  over HTTP to PHP. Core/Admin and Viewer transport use one resolver, accepting
  this topology only with both `security.trusted_proxies` (exact IPv4/IPv6 or
  CIDR)
  and `security.trusted_proxy_protocol_headers`. Client-IP header opt-in remains
  independent. Direct TLS and HTTP installations retain their existing behavior.
- **Owner:** `app/services/client_ip.php::request_transport_is_https()`;
  `app/helpers_request.php::request_is_https()` and `viewer_request_is_https()`
  retain their established caller entry points. Core loads the service lazily
  before session startup without loading Viewer identity or account storage.
- **Migration / safety:** Previously generic Core HTTPS detection accepted
  forwarded protocol from any peer, including a forged direct-client assertion
  (BH-03). Configure the actual proxy scope and protocol header opt-in before
  upgrading a TLS-terminating installation. The proxy must overwrite enabled
  client-supplied headers. Ambiguous or conflicting enabled values cannot prove
  HTTPS. Existing configured HTTPS base URLs are not downgraded by the resolver.
- **Evidence / tests:** `tests/request_https_proxy_test.php` first reproduced
  the
  forged HTTPS assertion, then covers direct transport, exact/CIDR proxy trust,
  IPv6, header opt-in/ambiguity, public URL schemes and Admin cookie Secure
  flags.
  `viewer_authentication_phase06_test.php` retains Viewer policy coverage.
- **Usage:** Proxy topology and wrapper adoption are unknown; no production
  measurement is inferred from source age or test coverage.
- **Support rationale / retirement:** Direct transport, explicitly configured
  proxy hosting and both wrapper entry points are permanent supported contracts.
  Wrapper removal needs complete caller migration and equivalent regression
  coverage. Unrestricted forwarded-header acceptance is unsafe and is retired;
  it is not retained as an implicit configuration fallback.

## Updater and release compatibility

### UPD-6 — Source ZIPs without HTTP Range support

- **Reason / protected scenario:** GitHub's codeload source archive can ignore
  Range and return HTTP 200. Short workers then repeatedly replace the same
  partial archive without reaching extraction. Cached PHP stat results can
  additionally misreport newly written bytes, including resumed completion.
- **Owner:** `updates_jobs/download.php` owns refreshed measurements, bounded
  restart counting and published-archive selection. `updates_filesystem.php`
  recognizes the flat production ZIP and the historical source wrapper.
- **Safety:** Three incomplete full-response restarts select a published asset
  through one metadata request per slice. Its canonical URL, version, original
  full commit, positive size and SHA-256 are required. Raw ZIP digest validation
  precedes extraction regardless of the optional per-file verifier. A retry
  clears old archive receipts and keeps the commit. No request limit is raised.
- **Evidence / tests:** On 2026-10-28, the source archive at `9cb0125e` ignored
  a GET Range request and exceeded one two-second opportunity; the existing
  published production asset returned the requested 206 byte range.
  `updater_download_transport_test.php` covers both confined transport paths,
  resumed file sizes, short writes, fallback identity and digest refusal,
  evidence preservation and retry. Hosted execution must qualify the changed
  candidate; an earlier green develop does not execute the added fixture.
- **Usage / support rationale:** Production frequency and installed-client
  distribution are unknown. Preserve source archives for unpublished snapshots
  and older releases; no matching asset may be inferred from version alone.
  The first hop from an older installed downloader remains operator acceptance
  work. Safe retirement requires a supported upgrade floor and equivalent
  bounded download/identity coverage, not a passing fast-network check.


### FILES-1 — Windows layout-migration sidecar sharing locks

- **Reason / protected scenario:** Windows briefly refused atomic replacement of
  an owned `gallery.json` during the release audit. The existing
  layout-migration
  owner retries the same complete staging file for at most ten attempts with
  nine
  50 ms pauses; other platforms retain one attempt.
- **Owner:** `app/services/gallery_description_layout_compatibility.php`.
- **Safety:** Recheck the original bytes before each retry, refuse concurrent
  edits or persistent storage refusal, never delete the previous sidecar, retain
  its permission-mode behavior, and remove only the owned staging file. A
  staging
  file that inherited read-only mode becomes owner-writable only for cleanup.
- **Evidence / tests:**
  `tests/gallery_description_layout_compatibility_test.php`
  injects a transient refusal, verifies bounded Windows recovery and unchanged
  non-Windows refusal, intervening-edit preservation, read-only refusal and
  staging cleanup; existing rollback and per-document replay cases remain.
- **Usage:** One local native sharing refusal was observed on 2026-10-07 with
  PHP 8.5.10 on Windows; production frequency is unknown.
- **Support rationale / retirement:** Permanent support while Windows is
  supported. Removal requires an alternative atomic commit proven with transient
  locks, persistent refusal, concurrent edits and migration replay.


### UPD-4 — Windows updater checkpoint sharing locks

- **Reason / protected scenario:** Windows readers can briefly deny replacement
  of a just-written updater checkpoint. Retry the same complete staging file for
  at most ten rename attempts with nine 50 ms pauses; never delete the prior
  checkpoint to obtain replacement permission. Other platforms use one attempt.
- **Owner:** `app/services/updates_jobs.php` and
  `app/services/updates_jobs/state.php`, the existing updater state persistence
  owner.
- **Evidence / tests:** An owned Windows probe refused its 29th consecutive
  checkpoint replacement, then committed after one pause (66 ms observed).
  `tests/updater_resumable_state_machine_test.php` covers complete replacement,
  persistent destination refusal, staging cleanup, prior checkpoint preservation
  on Windows read-only refusal, and 620 resumable archive-entry checkpoints.
- **Usage:** Production sharing-lock frequency is unknown; the local failure and
  successful retry were observed on 2026-10-07 with PHP 8.5.10 on Windows.
- **Support rationale / retirement:** Permanent support for Windows file-sharing
  semantics while Windows is supported. Removal requires a replacement atomic
  persistence mechanism proven with transient locks, persistent refusal and
  existing durable-job recovery; no calendar retirement is assumed.

### UPD-1 — Positive manifest ownership bridge for archives without sidecar

- **Reason / protected scenario:** An incoming production-files sidecar may be
  absent in an older supported archive. The updater then accepts only safe
  positive paths owned by the core manifest and recognized guards. A present
  but invalid sidecar fails closed; absence is not treated as arbitrary trust.
- **Era / owner:** Policy evidence exists by 2026-10-05; oldest supported
  archive is unknown. Owner: `app/release_file_policy.php` and updater archive
  validation.
- **Evidence / tests:** `tests/production_file_policy_test.php` covers valid
  positive membership, invalid-sidecar refusal, older ZIPs, and unlisted
  paths; `tests/updater_safety_model_test.php` covers updater safety.
- **Usage:** Archive-sidecar adoption is not aggregated.
- **Earliest safe retirement:** After the bridge upgrade window is documented
  and every supported installer/updater archive plus prior ownership state has
  valid positive inventory. Do not substitute a calendar date for the
  supported-upgrade floor.

### UPD-2 — Incoming schema-1 companion membership for installed readers

- **Reason / protected scenario:** The installed pre-WinApp reader validates
  every incoming production path before the replacement reader is activated.
  Listing newly distributed WinApp sources in the base list prevents those
  installations from updating. The additive `companion_files` field keeps the
  base readable while current packaging includes the reviewed companions;
  neither reader grants CMS updater ownership to companion files.
- **Era / owner:** The pre-WinApp contract is evidenced by commit `ffe5b5d`
  (CMS version 0.119); the compatible split is introduced in 0.120.1.
  Owner: `app/release_file_policy.php` and
  `scripts/generate_production_files.php`.
- **Evidence / tests:** `tests/updater_inventory_compatibility_test.php` uses
  trusted frozen installed-reader functions to reproduce error reference
  `385BCD5058D6`, validate the real incoming inventory and compare archive
  activation scopes. `tests/production_file_policy_test.php` covers both
  schema-1 layouts, optional-field validation, disjoint package lists and
  private-state/updater-ownership refusal; real deploy regression retains
  companion sources in folder/ZIP packages.
- **Usage:** Supported installed-reader adoption is unknown. One supplied
  online failure reports version 0.119; this is not an aggregate usage metric.
- **Earliest safe retirement:** Only after the supported installed-updater
  floor explicitly excludes the old reader, deployments have a verified
  migration path and incoming archive qualification covers that new floor.
  Existing archives without the optional field remain readable. Never obtain
  compatibility by executing PHP supplied in an incoming archive or granting
  companions authority over installation-owned state.

### UPD-5 — Additive companion resources for installed validators

- **Reason / protected scenario:** The reported 0.135.1 to 0.136 update stopped
  before activation with `C35BC3B622B0`. Its installed companion-aware reader
  rejects the new DejaVu font/license in `companion_files` before a replacement
  validator can run. Testing only the current and pre-companion readers missed
  this schema-generation boundary.
- **Owner:** `app/release_file_policy.php` and
  `scripts/generate_production_files.php`. Schema 1 adds optional
  `companion_resource_files`, strictly limited to reviewed non-updater
  resources.
  Stable companion entries remain in the original field. Current readers also
  retain the original published resource placement. No installed-version
  dispatch, incoming-code execution or weaker path/ownership checks are added.
- **Evidence / tests:** `updater_inventory_compatibility_test.php` uses a frozen
  trusted 0.135.1 reader to reproduce the exact failure and accept additive
  resources with unchanged CMS activation ownership. It retains the existing
  pre-companion fixture. Policy regressions reject malformed, unsafe, duplicate,
  unordered or updater-owned resources. The real folder/ZIP builder checks full
  font/license bytes, with mandatory Linux/Windows package coverage and
  independent advisory macOS coverage under current owner contract 4.
- **Usage / retirement:** The supplied production failure confirms this reader
  generation remains deployed; aggregate adoption is unknown. Preserve the
  additive format until supported installed readers no longer validate the old
  field with its historical allowlist. Direct-upgrade mechanics do not establish
  arbitrary historical database/hosting acceptance. Published 0.136 assets stay
  immutable; the corrected inventory needs a newly authorized release.

### UPD-3 — Windows checkout line endings in production inventory verification

- **Reason / protected scenario:** Git may convert the checked-in JSON inventory
  to CRLF on Windows. Comparing those bytes with generated LF text must not
  reject an otherwise identical reviewed production list.
- **Era / owner:** The correction is evidenced by commit `d4cbe89` and shipped
  in CMS 0.121.2. Owner: `scripts/generate_production_files.php` through
  `production_files_inventory_content_matches()`.
- **Evidence / tests:** `tests/production_file_policy_test.php` accepts CRLF
  conversion and still rejects actual content changes. The generator normalizes
  only CRLF to LF; membership, path safety and manifest hashes keep their
  existing strict checks.
- **Usage:** Windows checkout adoption is unknown.
- **Permanent-support rationale:** LF and CRLF are supported Git checkout
  representations. Retire normalization only if support for CRLF checkouts is
  explicitly ended and supported Windows build/packaging environments prove
  canonical LF handling. This is not an archive-integrity normalization rule.

## Translations and language preference

### I18N-1 — PHP catalogs when a language JSON pack is absent

- **Reason / protected scenario:** `translation_load_language()` reads the
  maintained JSON pack first and uses the matching PHP dictionary only when
  that JSON file is absent. A present malformed JSON pack does not silently
  switch formats; it resolves to empty catalog data.
- **Era / owner:** JSON foundation evidence exists by 2026-05-11; the PHP
  fallback's first introduction is unknown. Owner:
  `app/services/translations.php` and
  `app/lang/`.
- **Evidence / tests:** `tests/translation_catalog_consistency_test.php`
  validates maintained catalogs. `tests/translation_catalog_chunks_test.php`
  exercises large canonical packs, last-duplicate-key semantics, native JSON
  formatting, malformed input, cache invalidation and absent-JSON PHP fallback.
- **Usage:** Format fallback adoption is unknown.
- **Earliest safe retirement:** Only after supported packages and upgrade
  scenarios guarantee every supported JSON pack, the product owner explicitly
  ends PHP-catalog compatibility, and an absent-pack regression is replaced by
  a documented fail-safe.

The translation service privately caches bounded chunks to avoid oversized PHP
hash tables on public requests. Maintained one-member-per-line JSON uses native
per-value decoding; minified or multiline editable JSON permanently retains the
native whole-document reader. The optimization never accepts invalid JSON or
silently changes to PHP when JSON is present. Bulk dictionary consumers retain
their complete return shape. Pack metadata discovery does not retain dictionaries
for otherwise unused languages. Canonical metadata discovery retains only keys
and the language display name, releasing other values after native parsing;
explicit lookups still use request caching. Production formatting adoption is
unknown; keep both formatting paths while editable packs are supported.

### ADMIN-MAINT-1 — Historical Overview maintenance links

Owner: Admin dashboard/Maintenance Center controllers and dashboard
workspace JS.
System tools now owns the former Overview maintenance groups. Saved query links
redirect before loading dashboard data; fragment-only bookmarks use the
controller-rendered destination in browser JS. Already-open older clients retain
the authenticated deferred endpoint and its diagnostics. Without JavaScript,
fragment-only links land on Overview and the Maintenance Center menu remains
available. Adoption is unknown, so these small navigation adapters remain
permanent while historical Admin URLs are supported. The deferred-controller
fixture covers guards and redirect work exclusion; the HTTP gallery workflow
covers all three native tabs and a legacy redirect. Desktop/narrow IAB review
uses 6.1-terra. No new root menu item or storage migration is introduced.

### I18N-2 — Canonical English and key fallback

- **Reason / protected scenario:** `t()` resolves the active catalog, canonical
  English, caller default, then key so incomplete translations do not erase
  visible labels or break older callers.
- **Era / owner:** Translation foundation is visible by 2026-05-11; exact
  first introduction is unknown. Owner: `app/services/translations.php` and
  `app/lang/en.json`.
- **Evidence / tests:** `tests/translation_catalog_consistency_test.php`
  checks English keys and four maintained catalogs.
- **Usage:** Missing-key frequency is not reported as product adoption.
- **Lifecycle:** Permanent user-facing fallback contract; no removal planned.

### I18N-3 — Legacy shared Admin language cookie/session mirror

- **Reason / protected scenario:** Admin language preference resolution
  preserves the `cms_admin_language` session value, dedicated Admin cookie,
  older shared `cms_language` cookie, and a `cms_language` session mirror.
  Public language preference remains separate and current.
- **Era / owner:** Base translation/session evidence exists by 2026-05-11;
  selector evidence by 2026-08-13. Owner: `app/services/translations.php` and
  request
  language/session bootstrap.
- **Evidence / tests:** `tests/public_language_preference_test.php` and
  `tests/session_context_test.php` cover Admin/Public separation, mirrors,
  reset, and diagnostics.
- **Usage:** Existing cookies/session values are not counted.
- **Earliest safe retirement:** Stop emitting the shared alias first; preserve
  readers through at least the one-year cookie max-age and supported-session
  horizon, then expire/migrate stored Admin preference while preserving it.
  Never merge the Public dedicated preference into this alias.

## Core runtime and session contracts

### CORE-3 — Maintenance Center full-bootstrap task consumers

- **Reason / protected scenario:** CLI/test consumers that load the complete
  bootstrap already have Maintenance Center subsystem dependencies and may call
  task steps without a request kernel loader. Selective HTTP routes inject one
  for each separate analysis/execution request.
- **Owner:** `app/services/maintenance_center.php` and its analysis/execution
  workers; HTTP injection belongs to
  `app/controllers/admin_maintenance_center.php`.
- **Evidence / tests:** `tests/maintenance_center_test.php` covers
  phase-specific
  module selection, controller injection and loading before task callbacks;
  `scripts/runtime_dynamic_dependencies.php` registers the reviewed loader.
  A localhost `media.deep_verify` analysis failed when assigned the site worker,
  which omitted the thumbnail summary service. Both phases now defer to the
  existing thumbnail owner. The isolated runtime-module fixture verifies all
  five thumbnail adapters without a full-bootstrap fallback.
- **Usage:** Full-bootstrap consumer adoption is unknown.
- **Earliest safe retirement:** Migrate all supported CLI/test consumers to an
  explicit loader and prove their include contracts before removing the optional
  null-loader path. Never replace selective HTTP loading with umbrella loading.

### CORE-1 — Two-argument ModuleLoader construction

- **Reason / protected scenario:** An optional `null` third file-order argument
  preserves historical two-argument construction for consumers using a
  dependency-first manual plan. The official bridge supplies the compiled
  third order.
- **Era / owner:** The two-argument Core runtime API is visible by 2026-10-05
  (#69); optional third file-order argument is visible by 2026-10-06 (#79).
  Exact originating release is unknown. Owner: `app/runtime/ModuleLoader.php`
  and `app/runtime/bridge.php`.
- **Evidence / tests:** `tests/runtime_kernel_test.php` covers two-argument
  construction and composed plans.
- **Usage:** External constructor usage is unknown.
- **Earliest safe retirement:** After all in-repository consumers use the
  explicit order and the project publishes an intentional unsupported-API
  boundary for external consumers. Do not infer external non-use from local
  search results.

### CORE-3 — Module validation lifetime and optional opcode caching

- **Owner:** `app/runtime/ModuleLoader.php` and the existing early Test Run
  trace.
- **Protected behavior:** Complete composed-plan shape/path/graph validation
  remains per loader/request, including unselected modules. A physical path is
  checked once per `load()` preflight, before includes, and checked afresh when
  another unloaded module selects it in a later load. No persisted validation
  receipt, APCu, file cache or extension-dependent fallback is introduced.
- **Ordering/failure:** Canonical layer/lexical order is checked linearly;
  file completion counters retain the dependency-first completed prefix, even
  if independent branches finish in a different order or a later include fails.
  `require_once`, selective roots and the legacy two-argument API remain.
- **Updates/concurrency:** Request-local preflight sets have no shared writers
  or invalidation races. Existing activation gates protect partial deployment.
  Server OPcache invalidation is still the hosting operator's responsibility;
  disabled timestamp validation requires reset/restart during deployment.
- **Evidence/usage:** `runtime_kernel_test.php` covers shared files, scope,
  missing/replaced paths, cycles and canonical/partial completion. The targeted
  disposable HTTP benchmark separates opcode-cold and warm requests; production
  hosting adoption and real traffic performance remain unknown.
- **Lifecycle:** PHP 8.1 and hosting without optional cache extensions are
  permanent support. No cache requirement or retirement date is introduced.

### CORE-2 — Flat session keys and domain-owned semantics

- **Reason / protected scenario:** `app/session_context.php` centralizes
  flat-key get/set/remove access while preserving the existing key names,
  inactive-CLI seeded values, and unrelated sibling keys. Translation,
  Navigraph cache/OAuth, gallery access, NSFW acknowledgement, and flash keep
  domain semantics in their owning services.
- **Era / owner:** The adapter is from 2026-10-06 (#72); underlying session
  keys span older mixed eras. Owner: `app/session_context.php` plus each
  domain session owner.
- **Evidence / tests:** `tests/session_context_test.php` and
  `tests/request_session_helpers_test.php`.
- **Usage:** Key-level usage is repository-observable; external session
  consumers are not measured.
- **Lifecycle:** Established current state contract; no removal planned.
  Redesign requires a versioned namespace, dual-read/migrate-on-write or an
  explicit expiry window, and key-by-key compatibility regression coverage.

## Media and routing

### MEDIA-1 — Responsive and progressive thumbnail renderers

- **Reason / protected scenario:** `responsive` provides complete server
  responsive markup and no-JavaScript behavior; `progressive` provides a
  real small first image and bounded near-viewport sharpening. The selected
  gallery setting accepts both permanent values and normalizes invalid values
  to progressive.
- **Era / owner:** Default-setting migration is 2026-08-20; service path is
  visible by 2026-08-24. Owner: `app/services/public_thumbnail_rendering.php`,
  responsive HTML helpers, and progressive browser modules.
- **Evidence / tests:** `tests/public_thumbnail_rendering_model_test.php`,
  `tests/progressive_thumbnail_renderer_test.mjs`, and the renderer browser
  fixture.
- **Usage:** Configured mode is measurable; aggregate visitor traffic by mode is
  unknown.
- **Lifecycle:** Both machine values and pipelines are permanent supported
  behavior. Keep semantic image markup, alt text, authorization, and no-JS
  behavior in both. No removal planned.

### MEDIA-2 — JPEG compatibility derivatives alongside WebP

- **Reason / protected scenario:** Legacy JPEG derivatives remain available
  alongside WebP for consumers that cannot use WebP. The indexed cleanup
  inventory deletes only registered legacy JPEG derivatives, not originals or
  WebP files.
- **Era / owner:** Evidence exists by 2026-06-08; exact introduction unknown.
  Owner: `app/services/thumbnail_compatibility.php` and thumbnail format/
  metadata services.
- **Evidence / tests:** `tests/thumbnail_compatibility_model_test.php` and
  `tests/thumbnail_format_metadata_consistency_test.php`.
- **Usage:** Inventory count/bytes are measurable; actual served-format need
  is unknown.
- **Earliest safe retirement:** Only after the indexed legacy inventory is
  empty and supported consumers demonstrate that JPEG derivative demand is
  absent. Keep explicit non-destructive inventory and cleanup until then.

### MEDIA-3 — Header-first MIME detection with fileinfo compatibility fallback

- **Reason / protected scenario:** Common JPEG, PNG, GIF, and WebP media is
  identified from its image header before fileinfo is consulted. PHP 8.5.11's
  fileinfo libmagic path allocates a 7 MiB read buffer even for a tiny image.
  The existing fileinfo path remains for supported `image/*` formats such as
  SVG, BMP MIME aliases, and legacy files whose headers are not in the common
  fast-path set; file extensions do not establish image identity.
- **Era / owner:** The header-first path is visible by 2026-10-06; the original
  fileinfo-based behavior predates this review. Owner:
  `app/services/dng_derivatives.php`.
- **Evidence / tests:** `image_source_mime_for_derivatives()` and
  `image_public_display_file()`; `tests/image_decode_pipeline_test.php` covers
  JPEG content with a misleading extension, supported raster MIME values,
  SVG fallback, and rejection of non-image content.
  Upstream PHP 8.5.11 defines the
  [7 MiB read bound](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/file.h#L471)
  and
  [allocates that buffer before reading the file](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/magic.c#L201).
- **Usage:** Per-format media requests and fallback rates are not measured.
- **Lifecycle:** Keep header inspection for recognized raster content and the
  fileinfo compatibility path for other formats and historical MIME aliases.
  Reconsider the fallback only after every supported legacy image format has
  a tested, trustworthy alternative MIME detector; do not replace content
  detection with extension-based guesses.

### MEDIA-4 — Previously emitted SimBrief PDF query URLs

- **Reason / protected scenario:** Earlier OFP View/Download actions emitted
  `index.php?page=media&id=GALLERY_ID&ofp=1`. Bookmarks, saved HTML, and
  unmodified clients may continue using this overloaded image/PDF route.
  Current actions use `gallery_ofp_pdf` with a gallery ID and a same-origin
  URL derived from the actual mount path so visitors on host aliases or
  non-root installations do not cross origins for PDF.js.
- **Owner:** `app/controllers/public_media.php` retains the legacy dispatch;
  `app/controllers/public_gallery_controls.php` authors current links;
  `app/services/simbrief_ofp_attachments.php` enforces the shared gallery
  authorization and local-file integrity policy.
- **Evidence / tests:** `simbrief_ofp_public_http_test.php` exercises anonymous,
  authenticated and denied legacy/current delivery on root/subdirectory
  `index.php` routes. `public_media_authorization_contract_test.php` covers
  the actual password, token, NSFW and visibility policy.
- **Usage:** Unknown; no measurement of historical PDF URL usage is available.
- **Support rationale / retirement:** Keep the old query branch until existing
  links can be migrated or a versioned retirement with real hosted evidence
  is approved. Both routes must remain equally protected; the compatibility
  route must never become a public static-file bypass.

### MEDIA-5 — Original gallery PDFs and converted page destinations

- **Reason / protected scenario:** General PDF attachments use a gallery-local
  stable document ID and same-origin protected route while retaining original
  PDF bytes. Their index is separate from the SimBrief OFP manifest so manual
  documents and OFP imports do not overwrite one another. Conversion supports
  either a reserved private child or PNG pages appended after existing photos. A
  source-bound reserved PDF child cannot be made public or unpublished until its
  conversion is complete; incomplete or cancelled work must first be finished or
  safely reconciled. This guard applies only to the reserved PDF child and
  leaves append targets and existing JPG page galleries unchanged.
- **Owner:** `app/services/gallery_pdf_documents.php` owns original documents;
  `app/services/gallery_pdf_conversion.php` owns resumable conversion state;
  source-gallery `.gallery-pdf-conversions/` sidecars hold markers for both
  private-child and append targets. `app/controllers/public_media.php` retains
  gallery access checks and the legacy OFP PDF-delivery route. The former
  `simbrief_action=convert_pdf_pages` browser request returns HTTP 409 with a
  reload prompt; the server-rasterizer helper remains only for CLI/test
  compatibility consumers and is not called by browser actions.
- **Ordinary upload default:** The browser document workflow attaches only the
  original PDF unless the administrator explicitly checks the Advanced
  conversion
  option. Missing controls also mean attachment only, so an older form cannot
  silently create a private child. The operation retains that choice across
  retries; acknowledged originals are not attached again. This opt-in policy is
  permanent, and existing separately launched document conversion remains
  supported.
- **Evidence:** The document service fixture covers exact bytes, stable
  identity,
  explicit replacement/deletion confirmation and digest changes. Hosted
  qualification, browser device coverage, and live deployment authorization
  remain pending for the exact candidate.
- **Usage:** Per-document route and conversion-destination usage is unknown; no
  usage measurement is collected.
- **Support rationale / retirement:** Keep the existing OFP manifest and
  `media&ofp=1` resolver for stored OFP files and historical links. Keep the
  original PDF alongside generated PNGs in both destination modes. Photo ZIP
  exports and gallery-migration transfers do not enumerate manual-document
  sidecars; full filesystem backups must include the hidden document and
  conversion directories. No document-specific import/restore workflow exists.
  Revisit
  these contracts only after stored IDs/markers and historical links have a
  measured migration path and replacement hosted evidence.

### ROUTE-1 — Query routes and clean URL aliases

- **Reason / protected scenario:** Query routes remain the canonical
  compatibility form. Pretty/clean path mapping is a convenience when rewrite
  rules are available.
- **Era / owner:** Routing path evidence exists by 2026-08-12; exact first
  introduction unknown. Owner: `app/bootstrap/routing.php`, dispatcher, and
  URL producers.
- **Evidence / tests:** `tests/route_reference_integrity_test.php`,
  `tests/public_media_url_rewrite_test.php`,
  `tests/public_media_version_routing_test.php`, and the HTTP-entry fixture
  from #78.
- **Usage:** No aggregate route-form metric is collected; existing access logs
  may provide local evidence.
- **Lifecycle:** Query routing is permanent. Removing clean paths requires an
  explicit public-URL breaking policy and proof for generated/bookmarked URLs.

## WinApp, upload, and downloads

### WINAPP — Optional discovery and mixed-version upload compatibility

- **Reason / protected scenario:** A newer WinApp must retain manual upload
  using its existing gallery-scoped `pgu_` key against older supported Gallery
  installations, including those without discovery. Older uploaders must retain
  the same authenticated upload API when the Gallery server is newer. Discovery
  is an optional read and must not become a prerequisite for upload, change
  saved connection settings, or alter the existing upload authentication.
  Unknown/error and unsupported discovery do not prove which server version is
  installed and do not erase the saved configuration.
- **Owner:** The discovery route and runtime dispatcher own the server response;
  `winapp/uploader/server_discovery.py` and the connection dialog own the
  optional client check. Existing upload controller/service/model and uploader
  transport continue to own gallery-scoped upload authentication and behavior.
- **Evidence / limits:** Discovery and current-version upload regressions are
  defined in the disposable server HTTP workflow and WinApp tests; their
  presence alone does not prove an exact-candidate FULL or real mixed-version
  upload. Actual supported older version floors, deployment usage and
  cross-version live upload evidence are unknown. Record exact client/server
  versions, upload outcomes and environment when qualification is performed.
- **Retirement:** Preserve manual API-key setup and upload compatibility in both
  directions until an explicit compatibility policy identifies supported
  versions, real usage and verified migration/replacement evidence. Discovery
  availability alone is not sufficient evidence for retiring either path.

### WINAPP-1 — Query-first upload API and narrow clean-route fallback

- **Reason / protected scenario:** Canonical query POST is tried first because
  some hosting WAFs mishandle clean routes. The clean route is retried only on
  a non-JSON 404; Gallery JSON 404, authorization failures, network failures,
  and transient statuses do not cause route retry. Revocation has its own
  bounded 502/503/504 retry and already-revoked reconciliation.
- **Era / owner:** Upload entry point exists by 2026-05-16; fallback is newer,
  exact introduction unknown. Owner: `winapp/gallery_watch_upload.pyw` and
  Gallery upload controllers.
- **Evidence / tests:** `winapp/tests/test_redesign.py` covers canonical-first,
  multipart 404, revoke 404, JSON 404, transient behavior, and reconciliation.
- **Usage:** Local warning logs exist; aggregate alias adoption is unknown.
- **Earliest safe retirement:** Retire only the alternate route after target
  hosting and the supported client floor guarantee the canonical route. Keep
  query-first and status-classification contracts unless that contract is
  deliberately redesigned.

### WINAPP-4 — Explicit offline flight abandonment

- **Reason / protected scenario:** Deleted galleries and externally revoked
  keys can reject both inventory/report delivery and final revocation. App
  0.8.0 diagnostics supplied on 2026-10-23 confirm this HTTP 401 recovery loop,
  including attempts after no pending sessions remain.
- **Owner:** `winapp/uploader/flight_ui.py` stops producers and binds the local
  decision; `flight_workflow.py` persists abandonment after workers settle;
  `gallery_watch_upload.pyw` persists removal of the captured saved key.
- **Contract:** Explicit confirmation permanently supports leaving a connection
  without network availability. It retains recordings, photograph sources, PDFs
  and existing receipts. It abandons unresolved delivery/import bindings and
  clears only the local saved key. It neither requests nor claims server
  revocation. A submitted revocation must settle first. Timeout/persistence
  failure retains recovery and the key; a new connection cannot inherit consent.
- **Evidence / retirement:** Central WinApp workflow/UI/app regressions cover
  empty plans, worker barriers, saved receipts, local persistence and stale
  bindings. Exact-candidate hosted runtime and installed Windows acceptance
  remain separate qualification. Deployment adoption is unknown. Keep this
  explicit local escape while users can delete galleries or revoke keys outside
  the companion; removal requires an equally offline accepted replacement.

### WINAPP-5 — Official SimConnect SDK runtime refresh

- **Owner / supported behavior:** The app and installer continue to share one
  unmodified x64 native runtime with existing 2020/2024 transport, camera and
  aircraft fallback owners. The 0.9.0 source refresh uses the official MSFS 2024
  SDK Core 1.7.3 `SimConnect SDK/lib/SimConnect.dll`; its complete source URL,
  size and SHA256 are retained in `winapp/README.md`.
- **Evidence / limits:** On 2026-10-23, bounded official CDN acquisition
  verified
  ZIP CRC and MSI File/Component/Directory mapping before extracting the DLL
  without installing the SDK. Static PE32+/x64 validation and required exports
  passed; the old and new binaries expose the same 117 named exports. No usable
  file-version metadata is present, so no DLL version is inferred. Live
  simulator/installed-app acceptance and deployment adoption remain unknown.
- **Lifecycle:** Preserve both generations, optional overrides and bounded
  camera-to-aircraft fallback. A package refresh alone never establishes live
  compatibility or retires supported behavior. Microsoft's SDK terms remain
  distinct from the repository's MIT license.

### WINAPP-2 — Installer Python selection and matching windowless launcher

- **Reason / protected scenario:** Installer startup finds a supported Python
  runtime (`python`/`py`) and pairs the selected interpreter with its matching
  `pythonw.exe` shortcut so the GUI launches without a console.
- **Era / owner:** `.pyw` entry is visible by 2026-05-16; exact installer
  selector introduction unknown. Owner: WinApp installer/launcher scripts.
- **Evidence / tests:** `winapp/tests/test_redesign.py` loads the `.pyw` entry;
  current evidence found no automated installer BAT/shortcut creation test.
- **Usage:** Installed shortcut/runtime adoption is unknown; test coverage gap
  is explicit.
- **Earliest safe retirement:** Only when the supported installer/runtime and
  deployed shortcuts no longer depend on this selection contract and a focused
  installer acceptance test proves the replacement.

### WINAPP-3 — Batch wrapper PATH-based `pythonw` entry

- **Reason / protected scenario:** `run_gallery_watcher.bat` remains a
  separately supported launcher that invokes `pythonw` through PATH; this may
  coexist with installer-created shortcuts that bind a particular runtime.
- **Era / owner:** Visible by 2026-05-16; exact introduction unknown. Owner:
  `winapp/run_gallery_watcher.bat` and WinApp launch documentation.
- **Evidence / tests:** `winapp/tests/test_self_update_smoke.py` checks the
  startup prefix; direct wrapper activation adoption is not measured.
- **Usage:** Unknown.
- **Earliest safe retirement:** Only after support documentation and deployed
  scripts/shortcuts no longer advertise or call the wrapper, with replacement
  launcher evidence on supported Windows installs.

### UPLOAD-1 — Client-side thumbnail negotiation for the local WinApp uploader

- **Reason / protected scenario:** WinApp uses local Pillow derivatives and
  transmits thumbnail IDs with server-side batch work disabled to avoid
  shared-hosting thumbnail CPU bursts. Unsupported HEIC/HEIF/DNG, missing
  Pillow, or local conversion failure remains server-owned.
- **Era / owner:** Watcher/uploader entry exists by 2026-05-16; current
  negotiation detail introduction unknown. Owner: `winapp/uploader/media.py`,
  `gallery_watch_upload.pyw`, `upload_automation.php`, and browser upload
  service.
- **Evidence / tests:** `winapp/tests/test_redesign.py` covers local thumbnail
  and multipart behavior; `tests/browser_upload_settings_test.php` and
  `tests/browser_upload_zip_worker_test.mjs` cover the server path.
- **Usage:** Local warnings may be logged; aggregate client adoption and
  fallback rates are unknown.
- **Earliest safe retirement:** Only after supported deployed clients and all
  accepted formats have a validated replacement, and shared-hosting server
  processing constraints are acceptable. Keep server fallback for unsupported
  formats and local failures.

### DOWNLOAD-1 — Server ZIP generation, immutable cache, and direct/no-JS path

- **Reason / protected scenario:** The server may create and reuse an
  authorized immutable artifact; progressive browser streaming remains the
  modern client path while direct and no-JavaScript requests remain supported.
  Cache health is checked before manifest/build, and every request is
  authorized before artifact reuse. Optional cache failure leaves progressive
  availability intact.
- **Era / owner:** Cache path exists by 2026-09-03; exact direct-path
  introduction unknown. Owner: `app/services/download_artifact_cache.php`,
  download controllers, and `public/assets/gallery-download.js`.
- **Evidence / tests:** `tests/gallery_download_controller_test.php` covers
  early legacy-capability 503 and progressive availability;
  `tests/gallery_download_service_test.php` covers legacy manifest bounds,
  and `tests/gallery_download_manifest_test.php` plus
  `tests/gallery_download_client_test.mjs` cover manifest and client paths.
- **Usage:** `media.download.served` logs are observable but are not a product
  adoption metric for legacy/direct clients.
- **Earliest safe retirement:** Only after the direct/no-JavaScript contract is
  explicitly ended, usage is measured and acceptably low, and an authorized
  replacement remains safe. Do not remove the server fallback because cache
  generation is optional or unhealthy.

## Visual CSS resize across a scriptless preview frame

- **Reason / protected environment:** During a native drag over the scriptless
  preview iframe, hosted Chromium delivered a trusted held pointer move to the
  parent overlay after handle capture was lost. The active gesture must continue
  only through the existing pointerId-guarded transaction while the parent
  overlay shields hit testing; the implementation does not assume a specific
  browser cause for capture loss.
- **Owner:** `public/assets/gallery-modules/theme-visual-css-resize.js` owns the
  existing resize transaction, temporary overlay shield, one overlay
  move/up/cancel listener set, capture attempt, cancellation and restoration. It
  does not add a second pointer pipeline or alter public-page interaction
  outside an active drag.
- **Evidence:** `tests/theme_visual_css_resize_model_test.mjs` covers the
  bounded transaction model; `tests/theme_visual_editor_browser_test.mjs`
  exercises native pointer movement, transient preview, commit and Escape
  restoration. The
  [W3C Pointer Events capture algorithm](https://www.w3.org/TR/pointerevents3/#setting-pointer-capture)
  keeps capture pending until subsequent event processing and requires the
  capture element to be in the pointer's active document. The
  [W3C cross-iframe capture issue](https://github.com/w3c/pointerevents/issues/493)
  documents unresolved boundary behavior; the `TESTING.md` browser matrix is the
  manual evidence owner.
- **Usage:** Unknown; no gesture or browser telemetry is collected.
- **Lifecycle:** Permanent while resize handles are parent-owned and the public
  preview remains in an iframe. The shield must be enabled only for an active
  transaction and restored on every commit, cancel, capture failure, frame
  teardown and workspace cleanup path.

## Visual-preview audience fallback for inaccessible galleries

- **Reason / protected scenario:** An administrator may switch the visual
  workspace to Anonymous while its current gallery requires a password, is
  private, or otherwise fails the ordinary visitor grant. Redirecting only the
  authenticated visual preview to the nearest accessible ancestor or Home keeps
  the workspace useful without rendering the denied gallery document or
  changing normal visitor access behavior. A listed password-gated child may
  remain as a normal locked, no-cover card under the existing public-listing
  policy; private children stay omitted.
- **Owner:** `app/controllers/public_gallery_page.php` selects the destination;
  `app/services/gallery_access.php` walks ancestors and applies the canonical
  no-administrator-bypass visitor policy; the visual editor HUD displays the
  fixed `visual_notice=anonymous_fallback` token as generic localized copy;
  `app/services/seo_request_guard.php` retains it through Home canonicalization
  only alongside the exact visual-preview and anonymous-audience markers.
- **Evidence / tests:** `tests/public_visual_preview_workflow_test.php` covers
  the real HTTP redirect for password-gated and private galleries,
  nearest-ancestor and Home fallback, cyclic ancestry, preserved
  preview/audience/notice markers across canonicalization, no-store behavior,
  locked listed-child rendering, private-child omission, and the unchanged
  ordinary-visitor password gate. `tests/gallery_workflow_browser_test.php`
  exercises the actual authenticated Admin browser flow from the protected
  signed-in gallery to Anonymous, checking the ancestor URL, preview/audience
  markers, generic notice, unchanged CSS draft and Admin URL, zero mutation
  requests, and Back restoration of the signed-in gallery. Separately,
  `tests/theme_visual_editor_browser_test.mjs` uses a controlled synthetic
  iframe redirect to verify draft/history retention, notice display, and token
  removal from later navigation.
- **Usage:** Unknown; the product has no counter for audience-toggle
  fallbacks.
- **Lifecycle:** Permanent while the visual editor offers an Anonymous
  audience toggle and gallery navigation. Retirement requires an explicit
  replacement UX that preserves access enforcement, draft state, and a clear
  explanation when the selected gallery is unavailable.

## Installed CSS and separate public manual overrides

- **Owner and reason:** Theme/Custom CSS preserves the existing
  `public/assets/custom.css` preset/upload contract while a separate editor owns
  `public/assets/custom-overrides.css`. Ordinary Theme saves, skin replacement
  and unrelated resets must not erase manual edits.
- **Protected behavior:** Existing installations retain their appearance when
  advanced controls are unset or invalid. Manual CSS is an optional final public
  layer; an absent, invalid-transport or unreadable override file is omitted
  without blocking public rendering, and the protected editor exposes a bounded
  error. CSS syntax recovery remains the browser's responsibility. Native editor
  POST/redirect remains supported with its own CSRF and explicit clear checkbox.
- **Composite background-save compatibility:** The existing
  `custom_css_overrides_save($text, $revision)` call and
  `css_override_action=save` route remain CSS-only when no background operation
  is requested. The visual editor's explicit global Theme target carries `keep`,
  `replace`, or `remove` plus the opaque background revision; replacement
  attaches the reviewed File and remove stages only deletion of the global Theme
  image. The operation preserves `theme_background_source` (the gallery fallback
  mode), per-gallery `background_source` values and gallery cover assets. Its
  controller-prepared public context distinguishes the global Theme image, Theme
  gallery fallback, explicit gallery source, and no image; when an independent
  gallery-derived layer is active, the visual editor disables the Theme image
  target on that route and directs the administrator to Home. If background
  revision inspection is unavailable, only image operations are disabled and the
  existing CSS-only editor/save path remains usable; a ready empty image state
  still permits first upload. An unavailable preview URL hides the visual launch
  without claiming that a preview exists, while the editor controls remain
  initialized. Owner: `app/services/custom_css.php`,
  `app/services/custom_css/visual_background_save.php`,
  `app/services/gallery_backgrounds.php`, and the application-settings model
  transaction. CSS lock precedes the shared Theme background writer lock. Legacy
  direct POST/redirect remains supported.
- **Visual-preview stylesheet compatibility:**
  `app/controllers/shared_layout.php` prepares the marked stylesheet URLs by
  starting with `Gallery\Core\asset_url()` and then adding the cache revision
  and visual-preview/audience query context; `app/views/layout.php` only emits
  that controller model. The canonical helper examines both `SCRIPT_NAME` and
  `SCRIPT_FILENAME`, preserving the same public asset path for repository-root,
  `public/` document-root, and subdirectory-mounted deployments.
  `app/bootstrap/request.php` carries the marker to otherwise unmarked dependent
  resources only for same-origin, in-mount GET/HEAD requests whose referrer has
  the exact request origin; ordinary public stylesheet URLs and non-preview
  requests retain their existing form. `app/services/custom_css.php` owns the
  fail-closed `@import` and stylesheet-inspection decisions, while dispatch
  enforces them after preview authorization and before rendering. Keep this
  request-context bridge limited to those resources and methods; it is not a
  general route authorization or cross-origin referrer fallback.
- **Evidence:** `tests/custom_css_visual_preview_inspection_test.php`,
  `tests/public_visual_preview_policy_test.php`,
  `tests/public_visual_preview_workflow_test.php`, and
  `tests/public_visual_preview_asset_url_test.php` cover stylesheet inspection,
  route/resource boundaries, and canonical asset URL composition. The real-route
  `tests/support/gallery_workflow_browser.js` asserts preview/public `base.css`
  path and marker behavior, loaded body reset, and strict saved-width parity.
  Existing `theme_advanced_appearance_test.php`,
  `custom_css_preservation_test.php`,
  `app_settings_atomic_activation_model_test.php`,
  `theme_custom_css_rendering_test.php`, and Appearance/Custom CSS Chromium
  fixtures cover defaults, independent assets/resets, transaction rollback,
  failed writes, stale editors, escaping, isolation and header restoration.
  Installed usage and document-root distribution are **unknown**.
- **Retirement:** Permanent support for installed preset/upload assets, CSS-only
  callers, direct/no-JavaScript editor forms, and repository-root, `public/`
  document-root, and subdirectory-mounted installations. Removing any contract
  requires an explicitly approved migration of saved CSS, supported
  administrator clients and deployment configurations, plus acceptance evidence;
  no age-based expiry.

## Retiring a record

Do not delete a compatibility path because its record is old or its branch is
not visible in a local search. The owner should update this entry with the
proof that meets its retirement condition, identify migration and release-note
effects, add or adjust the regression/acceptance evidence, and link the change
that removes the behavior. Permanent records may remain as architectural
contracts rather than accumulating expiry dates.

## Release provenance schema v1 and v_0.125 legacy predecessor

Owner: hosted release-origin/promotion tooling. Schema v1 is permanently
supported
for immutable origins, including the already initialized v_0.126. Extended tag,
release-side and tree identities live in separate evidence and future immutable
predecessor records. Never rewrite the existing origin to migrate its schema.
Usage: one confirmed v_0.126 initialization; other usage unknown.

The repository-specific v_0.125 legacy pin protects the actual annotated
historical
tag and identical published/release-side tree. Historical qualification is
UNKNOWN_LEGACY. It grants no write/publication authority and cannot qualify any
other predecessor. Retain this historical proof permanently; future
initializations
use normal durable qualification evidence. Regression: release_origin_test.mjs
and
linear_release_graph_test.mjs, registered in the central audit. Direct CLI
release
notes retain their explicit tag-based default; hosted preparation supplies
verified
release-side SHA and checks tree equality. Direct CLI usage is unknown.

Tagged releases can precede manual GitHub Release publication. This permanent
ordering is owned by `release-origin.mjs`: when published permanent assets are
unavailable, schema-3 predecessor validation loads that predecessor's original
FULL integration and BASIC release-delta archives through
`coverage-artifacts.mjs`
and supplies their authenticated payload/provider pairs to the shared verifier.
Current-release evidence is never reused for the predecessor. This protects the
supported ability to begin another release before publishing the prior draft;
missing, expired, corrupt, foreign or red evidence remains blocking.
Usage: observed on the attempted v_0.132 initialization after tagged v_0.131.1;
other usage unknown. Regression: `hosted_release_policy_test.mjs` supplies real
ZIP bytes, starts the successor and checks invalid evidence before writes. The
schema-2 real-Git lifecycle remains separately covered. Preserve this path while
manual publication may follow tagging; there is no automatic retirement date.

## Historical release evidence after automatic lifecycle simplification

Release tooling owns permanent validation of existing schema-v1 origins,
annotated/lightweight tags, digest-checked publication assets and prior linear
reconciliation records. These protect immutable releases including v_0.126 and
the explicit v_0.125 UNKNOWN_LEGACY pin; old records are not regenerated.
Usage is unknown. Retire a parser only after inventorying all retained release
records and proving replacement validation against original bytes and fixtures.
Missing legacy evidence never becomes approval.

New tagged releases may precede manual GitHub Release publication. Their owner
is
`release-origin.mjs`; the protected behavior is valid successor initialization
from previous release-side Q without previous-M ancestry or automatic
publication.
Complete exact hosted qualification plus the bound reviewed merge is mandatory;
unavailable or expired evidence blocks initialization. This remains supported
while manual GitHub publication is the normal contract. The real-Git linear and
completion contracts cover parent/tree identity and independent publication.

Permanent repairs from hotfix `9d187d3663f96f7fa10451001d61ddb31e276479` are
recorded
in `docs/RELEASE_LIFECYCLE.md`. No hardcoded recovery identity, attestation
comment
or one-off environment is part of the normal path. Its cleanup stays PENDING
until final CI and durable transfer are proven.

## Completion before the workflow reaches default main

Owner: hosted release completion/handoff tooling. The existing dispatchable
`release-promotion.yml` on main supplies registration while its exact-Q release
ref supplies the new automatic workflow. This protects the first release after
integration without an untagged main commit or manual bootstrap form. Usage is
unknown until live release acceptance; feature-only transport probes and central
handoff/real-Git fixtures cover the selection and idempotence contracts. Retire
this bootstrap only after confirming every supported installation has the new
completion subscriptions on default main and proving equivalent single-approval
recovery. Bounded review-wait continuation remains supported while asynchronous
owner approval is part of the release contract.

Ruleset installation-token omission of `bypass_actors` is supported for both
protected branches. Missing is explicitly distinguished from visible empty or
malformed/nonempty. Server protection remains authoritative for hidden
membership;
visible policy identity, active target, effective checks/review/history
requirements
and current-user bypass are independently validated. Usage: observed for both
production Actions-token responses; hidden membership is never claimed as
checked.
Retire omission handling only if GitHub guarantees this field for the existing
token permission scope and live/negative regression evidence proves that
contract.

## Visual CSS availability and synchronous draft helpers

- **Reason / protected environment:** Shared hosting can fail an optional ES
  module or supply an unrelated configured public origin. Server-rendered
  availability keeps Manual CSS usable with disabled/blocked JavaScript; caught
  optional workspace loading preserves synchronous Theme form operations.
- **Owner:** Core request helpers own mounted preview URLs; Theme customization
  owns optional loading; `theme-visual-editor-support.js` owns availability and
  synchronous background File/operation helpers. The workspace retains the
  historical `restoreVisualEditorBackgroundFile` and
  `setVisualEditorBackgroundOperation` exports for direct consumers.
- **Permanent support rationale:** Keep native Manual CSS/Theme form submission
  and visible uninitialized state when JavaScript is unavailable. Keep helper
  re-exports until every direct consumer is migrated and regression evidence
  proves removal preserves the include/import contract.
- **Regression evidence:** Custom CSS rendering, preview asset URL and HTTPS
  proxy PHP fixtures; the visual editor browser fixture exercises missing
  optional dependencies, setup failure and intact draft/Theme controls.
- **Usage / introduction:** Production incidence and direct-export usage are
  unknown. This availability contract is authored for #182; the actual original
  production trigger remains unverified until deployment is checked.
# Historical integration digest bootstrap evidence (superseded for new installs)

The earlier owner-only integration bootstrap preserved release-path evidence
while the exact
installed main `7dcfadf153b1c941fd81edee43272f8accb475a1` rejects bare
upload-action
digests (observed run 38218271690). Its owner is the release lifecycle, not the
normal integration selector. It accepts a narrowly scoped repair feature and
uses actual develop -> immutable origin -> exact source overlay ancestry. For
unpublished `0.127.3` it retains the exact original `d4f9abd7` origin and
metadata
bytes, with independently checked unchanged main/predecessor pins; it never
reinitializes that version after a branch deletion.
Existing schema-2 release qualification consumers retain their native job names,
require fresh complete FULL/release owners and ignore only the additive recovery
provenance field. No schema-3 inherited integration coverage is claimed.

Regression evidence: `develop_integration_test.mjs` verifies real Git ancestry,
tree preservation, absence of ref/index/worktree writes, changed marker and
wrong
authority/scope refusal; `hosted_release_policy_test.mjs` verifies old
FULL-owner
requirements including rejection of skipped Chromium. Live usage is **unknown**;
feature fixtures and maintenance FULL do not prove production recovery
acceptance.
After a new main installation the exact-authority guard makes this path
unusable.
Retire executable preparation only after no pending recovery origin uses it;
retain or migrate immutable marker/qualification readers before removing
parsing.

For the current CI-authority evaluator update, this release-before-develop
bootstrap is superseded by the staged develop-first path in
`docs/CI_COVERAGE_REUSE_CONTRACT.md`. Preserve the existing erroneous release 8
origin and its complete evidence byte-for-byte; do not delete or rewrite it.
Create any superseding release only after the optimized authority is on develop.
The new route has its own pending live acceptance and does not inherit this
bootstrap's recovery provenance, alter ordinary main guards, or enable bypass.

### Staged CI authority update compatibility

Stage 1 repairs publisher/workflow/readers/root binding while leaving the full
pinned BASIC closure unchanged; its existing main-owned FULL integration and
guarded FF establish W*. Stage 2 is dispatched by the owner from develop with
`ci_authority_update: true`. It binds retained stage-1 proof and exact W*,
permits
only six approved evaluator transition paths with unchanged scanner closure,
uses trusted inherited BASIC against a clean candidate checkout, and runs FULL
from W* on exact S with PHP 8.1 and 8.5 before leased non-force FF. Records
identify `authority_update` and `inherited_basic` explicitly without claiming
that W* is in main history. Ordinary main review, separate recovery, human PR
approval and manual publication remain in force. Report PENDING until real
hosted
runs prove both stages and every write guard; fixtures and maintenance green do
not establish installation.

## Unchanged WinApp release attachments

Owner: `.github/scripts/release-draft-assets.mjs`. CMS-only releases retain the
original installer/update-metadata pair from the pinned published predecessor
when shipped WinApp inputs and the independent version are unchanged. Provider
SHA-256, byte size, metadata installer hash and the already authenticated
predecessor receipt/tag protect this download/update compatibility. Missing
proof
fails closed. Usage is unknown until a future approved production release.
`tests/support/release_draft_assets_scenarios.mjs` covers fresh artifacts,
reuse, changed-source
refusal, version mismatch and corruption. Permanent support is required while
clients discover the companion through each release's assets; retirement
requires
migration of that discovery contract and supported-client acceptance evidence.

## Interrupted GitHub release transfers

Ready-draft automatic cleanup is owned by
`.github/scripts/release-draft-retirement.mjs`. It requires a schema-4
synchronized
completion with the retained complete byte inventory. Historical schema-1--3
completions and published/manual retirement keep their existing approval and
publication controls; automatic cleanup skips unsupported/incomplete completion
instead of inventing proof. The manual retirement path remains supported for
retained historical refs. Usage of automatic cleanup is unknown until the first
production release containing this change. The registered retirement fixture
exercises real leased deletion, active/open dependencies, changed assets, lost
responses and retries without a branch. Live GitHub deletion permissions remain
unperformed coverage. Removing the manual compatibility path requires proof that
all retained historical refs were retired through their existing controls.

Owner: `.github/scripts/release-completion.mjs` and
`.github/scripts/release-draft-assets.mjs`. A failed create response may follow
a
successful server write. Read-only inventory reconciliation preserves that
draft,
manual edits and any publication without a duplicate POST. Binary transfers
retry
at most three times for the documented temporary HTTP/process categories;
uploaded
bytes and mutable draft/tag identities are checked before every upload retry.
Permission, integrity and conflict failures remain terminal. The existing
central
`release_draft_test.mjs` exercises CLI headers, absolute upload URL, original
input
bytes, cleanup, bounded recovery and publication/conflict refusal. Production
complete upload usage is **unknown** after the observed v_0.127.5 transport
failure
and manual publication. Retain recovery while GitHub responses may be
interrupted;
retirement requires an accepted replacement preserving create-only idempotence,
original bytes and published-release immutability.

## Admin Images background preparation (#202)

The gallery editor owns the complete ordinary/no-JavaScript table fallback.
Drawer `panel=1` responses defer Images through the same authenticated route and
canonical row renderer. Retain the full-page path for supported browsers without
scripting and direct Admin navigation; usage is unknown. Retirement requires a
replacement that preserves all row actions, keyboard/accessibility and accepted
no-JavaScript journeys.
`thumbnail_readonly_existing_response_file(..., exactOnly)`
defaults to false so existing visual-preview nearest-derivative fallback remains
compatible. The editor's explicit `existing_only=1` opt-in protects read-only
300px preload; ordinary media loading and generation keep their current owner.
Evidence owners and live acceptance limits are in
[ADMIN_IMAGES_PRELOAD.md](ADMIN_IMAGES_PRELOAD.md).

## Manual cache diagnostic logging (#212)

Updater status JSON gains an additive diagnostic field with a nonblocking probe
of the existing worker lock and server-clock checkpoint age. Existing clients
can keep consuming ok/job; unsupported lock probes remain explicit unavailable
evidence and do not manufacture process activity. Usage is unknown. Status reads
never create a missing lock or rewrite lease metadata. Retry now retains the
last
owner-redacted failure separately from the current error; legacy jobs without
that history remain supported without reconstruction. The cache fixture covers
metadata preservation, held/free/missing locks and retry history; the browser
fixture covers unchanged counter observations and compact activity labels.

Startup retains traffic-driven maintenance for ordinary routes/settings while
explicit cache actions and passive updater status reads own their work. This
permanent routing boundary prevents a status/stop request from advancing updater
work before authorization/CSRF controller handling. Security checks remain in
the
existing dispatch path. Usage is unknown; the actual startup-dispatch fixture
`cache_transport_maintenance_test.php` protects both the exemptions and retained
ordinary behavior. The dashboard fixture protects failed phase counters and safe
owner-provided error/reference presentation.

The cache diagnostic copy control follows the existing Clipboard API plus legacy
selection/copy interaction used by Admin controls. It supports HTTP hosting and
denied Clipboard API access, reports actual copy failure and remains owned by
the
cache UI module. Usage is unknown; retain this fallback until supported hosting
and browsers all provide accepted clipboard access. Job progress reuses the
existing authenticated Admin Updates status endpoint; stop reuses the locked
pre-activation cancellation service with canonical panel completion. Existing
direct/no-JavaScript POST fallback remains supported permanently. The dashboard
browser fixture covers copying after injection, phase counters and in-place
stop.

The log service preserves the existing void best-effort API and adds a safe
insertion receipt for explicit diagnostic consumers. The cache diagnostic owner
stores a 26-character request-ID prefix to support the installed observability
migration while retaining the full 32-character ID in searchable JSON context.
This is permanent support for existing installations; usage is unknown.
Retirement requires a qualified schema migration covering every supported
installation. `cache_cleanup_diagnostics_test.php` covers column width
rejection,
confirmed insertion, safe failure receipts and redaction. Manual
internal-browser
verification additionally checked real persisted log entries on the local
install.

## Floating navigation shell refresh (#208)

`admin-mutation-completion.js` preserves the grouped Top/Down shell as a direct
child of the listing frame. Its existing single-Top fallback protects already
mounted pre-update public markup and remains owned by the Admin mutation
coordinator. Usage is unknown. Retirement requires proof that supported page
renderers and custom layouts all emit the grouped shell and accepted live
fragment-refresh journeys no longer require the old shape. The production
Theme-width browser fixture verifies repeated grouped-shell refresh, stable
button identities and retained gallery cards; exact-candidate hosted integration
also owns the real application visibility/delete/restore journey.

## Contextual updater and bounded cache leases (#212)

Scheduled cache bursts retain the original schema-1 state/journal and approved
manual deletion cursor. New settled counters default to zero for in-flight old
cursors; completed legacy states expose zero pending backlog. Current cycles
persist complete counters. Usage of old deployed cursors is unknown. This
compatibility path remains until supported installations finish their existing
deletion jobs; it must never reset detached debt to improve metrics. The new
disposable scheduler fixture covers recovery after detachment before cursor
commit and refuses corrupt state instead of creating a replacement job.

The lightweight shared cache storage owner preserves all existing lifecycle
function names, stable lease buckets and schema-1 checkpoint identities. No
second collector is introduced. Public writers/passive registration use this
owner, while explicit worker roots load the existing collector. The updater
filesystem root honors the same explicit disposable-test root constant used by
cache lifecycle; absent that fixture constant, production root validation is
unchanged. Regression uses real updater filesystem declarations rather than
redeclaration stubs and confines all destructive work to its random temporary
installation.

The token-protected cron retains GET-token fallback for existing provider
URL-only
schedulers, while preferring a header or POST. Owner: site maintenance
controller.
Usage is unknown. Retire the URL fallback only after supported installations
have
migrated scheduler credentials and accepted header/POST calls; do not log
tokens.
Ordinary external cache-only requests still disable legacy thumbnail chaining.
The automatic cache owner now uses a separate authenticated POST/header chain
with committed single-use tickets, a dedicated live lock and bounded timer hops.
It permanently supports generic configured-origin HTTP hosting without requiring
a provider API or browser continuation. Missing absolute `base_url`, unsupported
network functions, loopback refusal or insufficient runtime is reported as a
safe
chain refusal; direct short visitor opportunities and optional external workers
remain available. A socket write is not an arrival receipt. Usage is unknown
until deployment shows independent worker arrivals. The required serial hosted
HTTP child verifies one public kickoff followed by actual authenticated server
requests, safe early deletion with partial coverage, aggregate logs and OFF.
Single-worker `cli-server` refuses timer chaining while retaining short visitor
work; Windows built-in servers are always treated as single-worker because PHP
does not support their worker pool. Owner: lightweight cache lifecycle runtime
policy and site maintenance transport. Usage is unknown. Permanent support is
required while single-worker development servers remain supported. Regression
covers the actual single-worker fallback, explicit runtime policy cases and a
two-worker autonomous HTTP fixture with owned process-tree cleanup.

Idle unapproved manual previews are explicitly superseded after fifteen minutes
without manual inventory activity. This is a policy change, not approval of an
old plan. The new scheduled ID is shown in status and old IDs fail closed.
Approved cleanup and detached cursors remain protected without an idle expiry.
Disposable regression evidence covers new-ID rejection and uninterrupted owned
cleanup; production scheduler configuration remains a separate deployment step.

Age-only legacy backup/orphaned-running-job pruning is retired because it cannot
prove current/previous recovery ownership. Existing legacy payloads remain
protected pending an owner migration; usage is unknown. Known terminal job
pruning preserves the last successful rollback, active/source and selected beta
references, and new scheduled ownership revalidates them under updater locks.
Windows releases the terminal job child worker handle immediately before
directory rename while retaining the start lock; terminal workers only read
payloads and retries/rollback transitions use the start lock. The disposable
lifecycle fixture covers contextual protection.

New GitHub/consumer leases use 256 stable buckets. Reclamation also checks any
full-identity lease left by an earlier live checkout of this feature, protecting
still-running producers while migrating lock granularity. Usage is unknown;
retirement requires proof every earlier producer has exited and no supported
caller uses that lease scheme. Bucket collisions are intentionally conservative
and delay reclamation rather than weaken safety. The inherited BASIC audit
runner remains unchanged. Native completed reports require matching JSON and the
final in-run Markdown footer with subsequent publications outside that subtree;
interrupted owner-only, missing/truncated Markdown and custom in-subtree
publications remain protected. The lifecycle fixture covers these refusal paths
and a held consumer lease.

Historical schema-1 audit reports without `source` are accepted only when
recognized profile/environment/task statuses, their canonical run-directory
identity and final native Markdown publication match. Downloaded exports retain
the completed JSON contract because their native run Markdown may be outside the
export. This protects real September audit reports observed during analysis
without permitting filename-only pruning. Usage is confirmed locally; retirement
requires migration/removal of these historical reports. The lifecycle fixture
includes historical/current completed pairs and protected interrupted-run
markers.

Registered legacy browser profiles and versioned release working copies lack
reliable creation markers. Their fixed 24-hour retention uses a
filesystem-derived age anchor persisted once; PHP ctime is not treated as
portable birth time. Native browser fixtures now write creation evidence. The
fallback is owned by cache lifecycle and permanently supports explicitly
registered historical disposable roots; usage is confirmed from the incident
screenshots. It can retire only after those roots are migrated/removed and all
supported producers record creation. Regression fixtures cover recent writes
without renewed expiry, the exact 24-hour boundary, fresh/pinned roots, legacy
anchor stability and preserved persistent release controls. In-progress
inventories initialize newly registered category counters without rewriting
already approved previews; earlier observations remain in their original
categories until a new scan.

### Visitor cache opportunities and strict central ownership

WEDOS shared hosting disables `disk_free_space()` in PHP 8, as confirmed by
[WEDOS support](https://help.wedos.cz/otazka/chyba-uncaught-error-call-to-undefined-function-hmbackupwordpressdisk_free_space-in/134191/answer/134193/).
Version 0.132 called it unconditionally before inventory, producing
`cache_worker_exception` with observed arrivals but no progress on restricted
hosts. The cache lifecycle owner now permanently supports unavailable, invalid
or throwing capacity probes without claiming known free bytes. Known-low space
still refuses admission; unknown capacity uses committed scheduler/candidate
checkpoints and existing bounded journals, and actual write failures stop work.
This is a hosting compatibility path, not a measured disk reserve or quota.
Usage of the reported symptom is confirmed by the deployed Admin screenshot;
the exact site's exception trace was not available. The hosted serial HTTP
fixture disables the real native function in both processes and requires
ordinary-visit reclamation plus protected-data/OFF assertions. Retirement
requires
a hosting support contract that guarantees the native probe for every supported
installation; generic shared hosting currently needs permanent support.

The cache lifecycle owner permanently supports PHP webhosts without
`fastcgi_finish_request`/`litespeed_finish_request`: a 40 ms admission
opportunity
runs at shutdown and may add to client latency. Supported response finishing
still
occupies the PHP worker and receives 120/600 ms admission; this is not a
separate
thread. Usage is unknown until deployed arrival/progress evidence is observed.
The hosted public HTTP fixture covers the unsupported finishing path; disposable
visitor fixtures cover both budgets, contention and resumed deletion metrics.
No cron, provider API or incoming traffic guarantee is introduced. This fallback
can retire only if the supported hosting contract stops including such runtimes;
current generic PHP 8.1+ support requires it permanently.

`maintenance_center_model_mutation_owner(true)` refuses unknown storage before a
new visitor mutation. Existing nullable callers retain their historical
observation contract; the optional strict parameter is owned by the persistence
model and tested by the visitor unknown-claim fixture. Upgrade preference
seeding
uses insert-if-absent, never an override of explicit administrator OFF. The old
900/3600-second deployed due markers are interpreted using the new thirty-second
completion/observation anchor; existing unfinished/approved cursors are never
migrated away. A numeric count of blocked inventory directories is partial
coverage, not a boolean worker failure. Actual cleanup blocking retains its
independent safe retry delay. Timer waiting occupies a PHP worker and cannot
guarantee recovery after all hosting processes terminate; independent scheduling
can provide that recovery without changing the generic cache owner.

## Cache registry transition and standalone previews

The cache lifecycle service preserves pre-registry JSON manual/automatic plans,
committed queue fences and detached deletion payloads until their original owner
drains them. Usage is unknown. The DB registry starts afterwards and retains the
last legacy observation as historical until regional coverage is available.
Invalid legacy state fails closed. Explicit manual previews and database-free
standalone fixture consumers keep the historical API; production automatic
workers require the migrated registry and refuse unavailable evidence. Removal
requires proof that no deployed unfinished legacy jobs remain and supported
standalone consumers have migrated. Regression owners:
`cache_lifecycle_scheduler_test.php`, `cache_lifecycle_registry_test.php` and
the real autonomous HTTP fixture. New regional work never restarts its root scan
merely because a work opportunity or process restart occurred.

Workspace age records now bind their fixed anchor to the current producer marker
and filesystem device/inode identity. An unbound historical age record is
re-anchored conservatively, and changed identity is revalidated under the
exclusive deletion lease before detachment. This protects a replacement at the
same path from inheriting expiry. Producer-free legacy workspace usage is
unknown; retain the conservative path while supported legacy roots remain.


## Historical flat download and updater archive ownership (#212, #220)

Owners: cache lifecycle policy/storage, the existing download producer and HTTP
stream boundary. Production screenshots confirm native flat gallery ZIPs with
40/64-hex signatures and older updater ZIP snapshot names remain deployed; exact
installation-wide adoption is unknown. Older cleanup only classified these
bytes,
while positive eligibility covered newer metadata-backed artifacts. Permanent
historical support now requires native path plus ZIP/header or source-snapshot
proof, stable physical identity and producer/serving leases. Completed download
ZIPs keep the existing seven-day lifetime from payload modification, independent
of permission-change ctime. Native abandoned partials retain six-hour
inactivity.
The updater keeps current installed code and a two-copy recovery window, with
active/selected/unknown recovery protected independently. No age-only general
backup/original deletion is restored. Retire this recognition only after
deployed
historical payloads are migrated/drained and all supported producers use
explicit
metadata. `cache_lifecycle_test.php` covers real ZIP shapes, expiry, active
leases,
unknown content and retained copies; exact hosted FULL and production acceptance
remain separate gates. Existing API fields retain their meanings; the optional
manual `operation` evidence separates current progress from historical totals.


## Consistency scope and large catalogs

Owner: gallery consistency service and its model/controller/browser workspace.
The first implementation decoded each source and used one HTTP request per
photograph. Local report evidence contains 41 name findings and 119 missing
variants among 44 groups; the user reports 40,000 production photographs.
Names-only analysis now excludes source-catalog reads and all physical I/O.
File/header mode excludes original checksum/pixel verification explicitly; deep
content mode remains opt-in and preserves unavailable-decoder findings.
Affected sources still capture hashes for repair admission. Batches amortize
request overhead and count only completed observations, with a time admission
limit. Fixed-size SHA-256 path keys bound repeated long-path storage; integer
owners avoid repeated string IDs. Existing in-flight unscoped session jobs
retain path-key and deep-content behavior until replaced by a new analysis.
Retain that session adapter while such checkpoints may exist; adoption is
unknown. New scope, queue and no-write fixtures accompany the existing actual
HTTP repair postconditions. Live 40,000-photo throughput is unmeasured.

Completed old reports reconstruct preview values from their captured catalog,
without rerunning media analysis. Browser proposals apply only selected existing
ancestor spellings; a single child repair preserves unselected existing parents.
The real HTTP nested fixture compares the shown child path to the actual folder,
while the Node preview fixture covers parent/child selection combinations and
strict sibling-prefix boundaries. Both retain separators and hierarchy.
