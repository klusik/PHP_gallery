# Compatibility lifecycle and retirement register

## Media-renamer long original filenames

Owner: `app/services/media_renamer.php`. Same-directory staging permanently uses
a fixed 83-byte hash basename instead of appending the original name. Production
logs reported staging refusal for 172-byte originals because the former
temporary name grew to 256 bytes. The hosting filesystem type remains unverified.
Existing source naming, image order, writer ownership, rollback and sidecars
remain supported; no migration or metadata-dependent filename scheme is added.
`media_renamer_staging_test.php` owns real long-name/rollback regression coverage;
the upload-selection fixture owns gallery-context and continued-numbering checks.
Live production acceptance and installation-wide incidence remain unknown.

## Attachment masters and accounting snapshots

Owners: canonical capability registry, shared upload/editor controllers,
attachment readers and Admin storage statistics. Independent PDF/music masters
default ON when no preference exists, including upgrades; fresh seeding preserves
explicit OFF. OFF retains files, indexes, preferences and the incomplete-PDF-child
publication guard. The historical `media&ofp=1` alias remains supported and applies
the same PDF master. Stored page photos retain their normal image ownership.
Both attachment masters OFF retain the core Presentation card when lightbox is
enabled. The tab is omitted only when all three capabilities are OFF; an old
selected-tab URL then normalizes to Identity rather than an empty panel.

Photo-only cached snapshots/jobs are derived state and cannot establish complete
attachment coverage. The new v3 snapshot/v2 job keys do not rewrite original data
or old derived bytes; the first new measurement requires explicit Update all.
The existing optional snapshot argument remains supported for pure/legacy
photo-only consumers and contributes zero attachment bytes when omitted.

The attachment accounting owner omits corrupt indexes and unsafe/unreadable
originals with visible partial-coverage warnings, preserving independent readable
categories and all original bytes. This is permanent read-only reporting behavior,
not permission to repair indexes. A post-commit advisory revision write may fail
without failing the successful attachment response; the next manual scan still
measures files. This permanent fallback avoids duplicate retries after optional
reporting failures. Out-of-band file changes require manual refresh.

Regression owners: canonical feature core/inventory, real MySQL/MariaDB HTTP
workflow and the existing storage-statistics fixture. Real-host library sizes,
permissions and media usage remain unknown; no performance/usage-based retirement
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
of this fallback is unknown; retire only with supported-browser/deployment proof.
The music model also owns permanent v1 compatibility: a missing download flag
means false. New tracks always start locked; bulk permission affects current
tracks only. This prevents old indexes or later uploads inheriting permission.

Public media cards retain native disclosure support while their always-visible
totals use the same authorized rows. An unreadable PDF or audio duration leaves
the full total unknown. The shared PDF metadata owner releases each original
before opening the next; no page rendering or database migration is introduced.
Real-host metadata latency and device coverage remain unknown.

Explicit presentation launches temporarily override default-off music without
changing persisted preferences. Deferred activation starts audio in the original
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

## Compact attachment browser presentation

The physical-gallery presentation settings owner is the existing lightbox service,
gallery model and guarded editor writer. Nullable version-1 JSON keeps pre-feature
galleries enabled with autoplay, music and medium timings. Unknown versions/types render
those compatible defaults. Public reads need no new schema probes; explicit
editor/import writes fail before filesystem effects on missing or unknown schema.
Older forms preserve the stored field. Sidecars, Organizer children and Trash
retain preferences. Live usage statistics for the new settings are unknown.

New links snapshot all supported playback options. Legacy `presentation=1` links
remain supported and `autoplay=1` is an alias. Invalid values cannot introduce
arbitrary timers or change gallery/media grants. Browser autoplay refusal retains
a glass player that follows ordinary HUD hiding and a trusted-photo-click retry
of the requested soundtrack. Photo/Play activation can also request native fullscreen.
Stop/Close/explicit silence cancel it. This permanent shared-player fallback
protects browsers requiring activation; retire only with supported-browser and
deployment evidence that audible autoplay is assured. Required Chromium covers
allowed/refused/muted starts and idle-HUD hiding/restoration with controlled audio; real
codecs, Safari and physical devices remain manual acceptance.
Autoplay defaults ON until explicitly changed. Share applets snapshot it alongside
music, speeds and link-only fullscreen/exclusive choices. Exclusive mode defaults
OFF, hides controls/metadata/diagnostics when selected, and retains Escape exit;
it grants no access and does not bypass browser sound policy. A one-second minimum
black opening also waits for first-photo decode, uses the existing slideshow timer
and never repeats as a gallery item. These are permanent supported behaviors;
live usage statistics and real-device permission behavior remain unknown.

The gallery-only `presentation` query flag is allowed through the SEO guard;
it grants no visitor authority. Public direct links must reach the same gallery
controller in a fresh anonymous session. Desktop native fullscreen rejection
keeps `.is-fullscreen` geometry with `.is-css-fullscreen` stacking rather than
entering a mobile-only CSS shell. This permanent viewer-owned capability fallback
protects links without user activation. Retire only with supported-browser proof
that native fullscreen is assured. Actual Chromium regressions decode synthetic
images, assert nonzero stage geometry, advance from photo one and close/release
audio after controlled native fullscreen/autoplay rejection. Live browser policy,
codec and mobile-device coverage remains manual; adoption statistics are unknown.

Owners: `gallery-media-panels.js`, shared lightbox view helpers and music player.
Native details is the permanent no-JavaScript and missing-Popover fallback: the
bounded list opens inline while forms keep their original ownership. Optional
Web Animations is feature-detected; missing support closes immediately. User
reduced-motion preference disables both disclosure motion and row-title scrolling.
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
renewed confirmation before irreversible Revoke. Local-only resolution is
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
API, separate from per-photo camera/aircraft connections. Missing or incompatible
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
Current schema-4 completion fast-forwards unchanged selected develop to the actual
reviewed/tagged main merge M. A `RECONCILED_FF` receipt binds M/tree/source and
retirement verifies original assembly, owner approval, source ancestry and retained
M. Equal contents in a sibling commit are insufficient. Readiness and PR creation
block the old merge prohibition; only the maintainer changes installed policy.
Non-force updates, exact Actions checks, no bypass and concurrent-ref protection
remain required. No content-copy commit or forced protected update is generated.

Published schema-4 `RECONCILED_EQUIVALENT` receipts (observed through v_0.133.3)
retain their original single-parent result above selected develop, complete tree
and authenticated assembly/approval. Readers keep that historical format while
its published receipts remain supported; later reviewed main FFs may descend from
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
tree preservation, concurrency refusal, idempotence and successor initialization.

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

Owners: integration/evidence and release origin/promotion readers. New integration
schema 2 permits explicit owner-selected feature workflows and seals successful
FULL before publication. New release schema 4 trusts the frozen protected develop
source and records assembly, without reopening older source qualification.
Historical integration schema 1 and release schemas 1–3 remain supported for
immutable existing records. Their actual usage is unknown; retain their readers
until supported historical origin/completion consumers can be proven absent.
The old finite 0.132.9 bridge below is historical compatibility only. New releases
resolve protected main first-parent history to the unchanged stable tag without
another version-specific exception. Regression coverage includes complete adverse
native owners, changed inputs, corrupt archives, release assembly failures and
Git/tag identity preservation. Live acceptance is pending hosted qualification
and human-reviewed installation; no production publication is part of this batch.

Protected publication retains Checks IDs and an optional `matrix_status_id` from
the same verified FULL. Commit Statuses cover GitHub's dispatch-suite rollup
behavior; the publisher verifies the exact status ID, SHA, workflow URL, state
and Actions creator before the unchanged guarded FF. Preserve both supported
provider representations and historical records; neither changes test ownership.

## Reviewed untagged dispatcher maintenance merge before 0.132.9

Owner: `release-origin.mjs`, `start-new-release.mjs` and the shared release
promotion readers. PR #230 advanced main through normal human-reviewed protected
merge while the existing `v_0.132.8` tag stayed immutable. The old origin equality
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
`updater_resumable_state_machine_test.php` and the compact production package test.

## Admin log clipboard and native text downloads

Owner: `admin-logs.js` and the existing `admin_log_export` controller/service.
The native selected-text copy command is attempted first because the embedded
browser's asynchronous Clipboard API acknowledged writes to its browser-session
clipboard without updating the user's Windows clipboard during #219 acceptance.
The standard asynchronous API remains the fallback when native copying is absent.
HTTP installations or browsers denying asynchronous Clipboard API access retain
the full diagnostic text in a selectable modal; no successful copy is reported
when clipboard writing fails. Native authenticated TXT links and scoped POST
downloads remain supported without JavaScript. This is permanent support for
ordinary hosting and browser permission policies; usage is **unknown**. Retiring
the manual-copy alternative requires acceptance evidence for supported HTTP and
permission-denied environments. Regression evidence belongs to
`tests/admin_log_export_selection_test.php`, `tests/fixtures/admin_log_exports.mjs`
and the browser verification in #219. Native HTTP downloads are retained because
Blob URL downloads did not produce a file in the embedded browser; acceptance
verified actual UTF-8 files containing six group events and eleven selected
events in Windows Downloads. Ordinary clipboard and HTTP download support remains
permanent until supported-browser acceptance proves an equivalent replacement.

## Independent updater cache reclamation

Owner: updater filesystem/prune cleanup and site-maintenance service.
The existing three-argument `application_update_delete_tree_slice()` API remains
supported with an optional relative cursor and additional result fields. Existing
updater/CLI callers retain their `removed`, `done` and `elapsed_ms` fields. The
protected environment is PHP 8.1+ shared hosting; cursor-free calls remain valid
for bounded shallow updater work, while the independent GC persists depth state.
This is permanent support for existing callers rather than a timed fallback.
Usage is unknown. `tests/updater_cache_gc_test.php` supplies synthetic safety and
progress coverage; hosted FULL and production acceptance are distinct gates.

## Develop required-check propagation

Owner: `.github/scripts/develop-integration.mjs`, `integrateDevelop()`. Integration
[38899855917](https://github.com/klusik/PHP_gallery/actions/runs/38899855917)
passed FULL but GitHub rejected FF one second after creating its required check.
Propagation is the suspected cause; frequency is **unknown**. The narrow retry
retains exact evidence and leases, rejects other/ambiguous errors and is covered
by `tests/develop_integration_test.mjs`. Keep this provider compatibility until
GitHub guarantees immediate enforcement visibility and hosted acceptance proves
safe removal. Feature fixtures do not prove protected installation or live recovery.

## Retained Actions job inventory provider failures

Owner: `.github/scripts/release-promotion.mjs`, `command()` and `fetchJobs()`.
Release qualification must independently read the complete job inventory of the
exact retained integration attempt. GitHub returned HTTP 502 for the 100-job
page of integration run 38465847308 attempt 2; a complete one-job-page read
returned all 16 jobs. This is a provider read failure, not failed Gallery runtime
qualification. Usage frequency is **unknown**; the incident is documented in
issue #202.

Only an explicitly classified HTTP 5xx allows one alternate paginated read with
one job per page, on the identical run and attempt. Permission, missing-resource,
malformed/incomplete evidence and unclassified command errors remain terminal.
The smaller-page read retains total-count completeness, exact check identities
and all mandatory job gates; it cannot authorize writes or accept partial proof.
Regression coverage belongs to `tests/hosted_release_policy_test.mjs`.
Retire the fallback only after supported GitHub retained-job collections have
reliable large-page reads and exact-coverage regression acceptance remains intact.

## Public iCloud exports and bounded ZIP sources

Owner: `admin-icloud-source.js`, `admin-icloud-download.js`, the existing browser
uploader and image worker. The observed anonymous Photos sharing protocol can
produce CORS-readable compatible ZIPs for the owner-provided Shared Album. It is
undocumented and may change; strict schema/host/availability failures retain the
explicit manual alternative. No Apple credentials, cookies or Gallery proxy are
introduced. Historical Photos fragments and Shared Album `#/sc,` redirects remain
separate source inputs. Usage is **unknown**; live Photos Link, physical large-source
and Safari/Firefox/mobile qualification remain separate evidence requirements.
Retirement of the manual alternative requires those supported-source/client
acceptance proofs; it is not inferred from one successful Shared Album export.

Manual ZIP selections retain the 64 MiB aggregate-original cap. Automatic sources
use OPFS and the existing pull-driven worker/batcher seam, bounding one original
to 64 MiB/the effective server limit instead of retaining every image. Both modes
retain 4 MiB directory, 10,000-entry and 2 GiB expanded-data bounds, and explicit
ZIP64/multi-disk refusal. Apple's actual exported ZIP appends 205 zero bytes after
the end record. The shared parser accepts only all-zero suffixes up to 4 KiB,
with unchanged directory/entry/CRC/signature checks; nonzero trailers remain
invalid. Permanent compatibility is justified by ordinary ZIP-library acceptance
and the observed Apple producer; regression fixtures cover both acceptance and
hostile/nonzero/oversized suffix refusal. Tail allocation is at most 69,653 bytes.
Disk files are locked across active/retry work, removed on success/safe failure,
and aged unlocked orphans are reclaimed at the next acquisition. Synthetic
protocol/storage/incremental/replay and existing PDF scenarios run under the
inherited central ZIP owner; BASIC alone does not execute them.

## PDF conversion generations after target loss

Owner: `app/services/gallery_pdf_conversion.php`. Existing v1 markers omit
`render_key` and `generation`; their conversion identity remains the render key
and they remain generation zero. Confirmed replacement of a missing or changed
child creates a new identity while preserving prior markers and their publication
guards. Empty newer generations advance rather than replacing generation zero.
Legacy-marker usage is **unknown**; preserve this interpretation until supported
installations have an accepted migration. The lifecycle fixture contains
generation-selection, old-marker preservation and empty-orphan assertions;
functional execution remains deferred during BASIC-only implementation.

## Gallery PDF index visibility defaults

Owner: `app/services/gallery_pdf_documents.php` and protected PDF delivery in
`app/controllers/public_media.php`. Existing v1 attachment indexes omit the manual
`hidden` flag and legacy `ofp_hidden` envelope value; both default to visible to
preserve previously shared documents. New hidden state is enforced for visitors,
including share tokens and anonymous Admin preview. Replacement and index rewrites
retain those values. Usage of legacy indexes is **unknown**; keep this fallback
while supported installations can contain v1 records without visibility fields.
Retirement requires a verified migration and supported-installation acceptance.
Regression assertions are authored in `gallery_pdf_documents_service_test.php`
and `simbrief_ofp_public_http_test.php`; execution is deferred during BASIC-only
implementation and must not be claimed as verified functional coverage.

## Publication refresh and normal automatic release retirement

Pages handoff protects the main-only `github-pages` environment. A main checkout
cannot change a release run's tag ref (observed run 38225431172).
`project-website-dispatch.mjs` validates latest stable publication and Actions-based
Pages, then dispatches the same workflow only on main. The handoff has no Pages
write or OIDC token. Legacy Pages setup requires explicit owner configuration.
`hosted_release_policy_test.mjs` covers wrong contexts, superseded/draft releases,
legacy configuration and the exact allowed dispatch.
Website evidence uses the existing owned DevTools browser session after fonts,
images and layout assertions, replacing the CLI screenshot startup that timed
out in run 38230411649. PNG format, viewport width and all six evidence files are
required; native route, keyboard, contrast and overflow checks remain enforced.

The reconciliation owner recognizes automatic completion when protected linear
develop retains qualified Q. It checks durable published bytes, original native
qualification (schema-3 integration/delta transports included), immutable tag
object/tree/parents and live effective owner approval of the exact bot PR. This
removes dependence on the retired reconciliation workflow for automatic FF;
legacy equivalent-replay receipts keep their path. Missing permanent evidence
stays BLOCKED with instructions to attach the original completion artifact.
Cleanup never generates/uploads a replacement receipt. Tests in
`release_retirement_test.mjs` cover isolated real merge/tag/FF, red qualification,
absent/altered approval, forged receipt fields and missing evidence/Q ancestry.
Expired native qualification artifacts remain a blocker. Live deployment/retirement
acceptance is unknown until maintainer execution. Keep legacy parsing until all
published receipt consumers are migrated.

Reviewed 2026-10-06 for Issue #73. This is an evidence-backed inventory, not a
guarantee that every path is exercised in production. Approximate eras mean
“visible in current code/evidence by this time”; they do not claim the first
release that introduced the behavior. Usage is marked unknown unless an
existing product metric actually observes adoption.

## Policy

### CI recovery — retained integration artifact protocol

- **Reason / protected behavior:** Existing immutable release origins may select a
  FULL-qualified develop SHA advanced outside the integration action. A main-owned,
  owner-dispatched recovery qualifies that same SHA without changing any ref.
- **Owner:** `develop-integration.yml` and `develop-integration.mjs`; release consumers
  continue using the existing `coverage-artifacts.mjs` and schema-1 integration proof.
- **Permanent support rationale:** Keep retained origins and their selected commits
  usable. Recovery records preserve real historical ancestry and fresh same-run native
  FULL owners, with explicit no-ref-write metadata. There is no red override or forged
  replacement of historical checks, and ordinary integration guards remain strict.
  Recovery does not relax main/predecessor pins; an origin frozen before installing
  this authority cannot be moved to the newer main and remains historical evidence.
- **Evidence / usage:** `tests/develop_integration_test.mjs` covers owner/attempt/ref
  rejection, old and fresh FULL owners, metadata races and unchanged release-consumer
  verification. Live recovery usage is **unknown** until a reviewed installation on
  main and successful owner recovery are recorded.

### CI-1 — Local maintainer recovery after CI-first migration

- **Reason / protected environment:** Maintainers may need existing local
  generators/audits when GitHub is genuinely inaccessible or for explicitly
  requested environment-specific diagnosis. Red or slow CI is not inaccessible CI.
- **Owner:** AGENTS.md owns agent execution; RELEASE.md and the existing canonical
  generator/audit scripts own recovery. No parallel runner or test registry exists.
- **Permanent support rationale:** Keep local development and emergency recovery
  possible without treating local PASS as hosted qualification. Ordinary agents
  commit/push only an explicitly authorized working branch; protected release
  workflows own approved promotion/publication. Production-host smoke stays manual.
- **Regression evidence:** `tests/hosted_release_policy_test.mjs` exercises missing,
  stale and red evidence refusal, exact overrides and CI-first/branch contracts
  through the central hosted Node registry. Actual hosted write-mode acceptance
  requires configured server protections and independent environment approval.
- **Usage / introduction:** Local recovery usage is unknown; this policy transition
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

- **Reason / protected environment:** Desktop browsers or clipboard sources may expose no image files, and older/restricted browsers may not construct a DataTransfer or assign an input FileList. Their established chooser/drop and ordinary no-JavaScript POST uploads remain usable.
- **Owner:** `admin-upload-selection.js` owns clipboard extraction; `admin-upload-queue.js` and `admin-upload-preview.js` own the right-drawer local selection, previews and disposal. Existing upload controllers/services and the side-panel completion owner retain persistence, progress and security.
- **Evidence:** `admin_upload_clipboard_test.mjs` and the registered `admin_upload_clipboard_browser_test.mjs` cover file-only exposure, selection preservation on assignment failure, ordinary multipart submission and dynamic panel controls. The OS/browser matrix in `TESTING.md` remains manual evidence.
- **Usage:** Clipboard API availability and fallback frequency are `unknown`; no new telemetry is collected.
- **Support rationale:** Chooser/drop and no-JavaScript POST remain permanently supported input methods. Clipboard input is additive and may never require permission polling or a second upload endpoint.

### UI-4: Bounded local miniature fallback

- **Reason / protected environment:** A browser may lack `createImageBitmap` or canvas encoding, reject a codec, or receive animated, oversized, unknown or metadata-heavy image data. Preview admission must not narrow the existing server upload formats or replace the original with a thumbnail.
- **Owner:** `admin-upload-thumbnail.js` owns bounded raster admission and serialized decoding; `admin-upload-preview.js` owns format fallback and URL/callback disposal. Upload processing, metadata and authorization remain with the existing transport/service owners.
- **Evidence:** Registered `admin_upload_thumbnail_test.mjs`, the expanded clipboard Chromium fixture, and the real Worker/ZIP operation-key fixture cover fallback, original preservation, resource bounds, reset and uncertain acknowledgment. Actual OS clipboard/browser results remain in the `TESTING.md` manual matrix.
- **Usage:** Decoder support, oversized-image frequency, fallback usage and real browser-process memory are `unknown`; no telemetry is added.
- **Support rationale:** A missing local miniature is permanently allowed for an otherwise uploadable original. Retirement would require a separate accepted browser/format support-policy change, not merely a passing Chromium test.

## Database and migrations

### WIDGET-2 — Public geometry without resize observation

- **Reason / protected environment:** Browsers without `ResizeObserver` must still display the original published article and retain viewport/scroll-based floating placement. Dynamic control-size invalidation is an additive browser enhancement.
- **Owner:** `setupPublicContentWidgets()` in `public-content-widgets.js` owns observation and the shared bounded geometry scheduler.
- **Evidence:** The public placement Chromium fixture covers size changes of protected controls with observation available; it does not qualify older browser engines. Server-rendered and no-JavaScript coverage remain independent of this API.
- **Usage:** Browser API availability and fallback frequency are unknown; no telemetry is added.
- **Support rationale:** The original in-flow article remains permanently supported. Removing the observer guard requires an accepted browser-support policy and proof that supported engines provide the API.

### WIDGET-1 — Unsupported persisted source-language metadata

- **Reason / protected scenario:** A restored, older or directly edited published widget may have missing or unsupported optional `source_language` metadata. Public reads label that content as English so the otherwise valid title and body remain visible; current Admin writes still reject unsupported codes.
- **Owner:** `public_widget_normalize_public_row()` in `app/services/public_content_widgets.php` owns the read fallback. `public_widget_normalize()` remains the strict validation path for Admin writes.
- **Evidence:** `tests/public_content_widget_public_render_test.php` checks the safe fallback label and strict-write rejection. The owned MySQL/MariaDB `tests/public_content_widget_db_workflow_test.php` writes an unsupported code directly to its disposable row, then verifies the public reader and planner keep the content visible with an English label and do not rewrite storage. `docs/PUBLIC_CONTENT_WIDGETS.md` defines the visitor-facing language behavior.
- **Usage / introduction:** Unsupported stored values are unmeasured; the introduction date of any such rows is unknown.
- **Support rationale:** Keep this fallback while imported, restored or directly edited rows can contain optional unsupported language metadata. Retirement requires a measured inventory and a documented data-repair policy that guarantees those rows cannot silently disappear from public pages.

### UI-2 — Authored metadata language and source-field compatibility

- **Reason / protected scenario:** Existing galleries and photos may have no
  translation row or source-language tag. Gallery fields fall back independently;
  an existing photo translation retains blank caption fields. Admin source
  editors must never save a presentation overlay as canonical text.
- **Owner:** `app/services/content_localization.php` and request language policy
  in `app/services/translations.php`. Admin and public browser preferences stay
  independent; explicit language arguments remain supported for existing callers.
- **Evidence / tests:** `content_request_language_test.php`,
  `content_localization_model_test.php`, and
  `content_language_workflow_integration_test.php`; investigation scope is in
  `docs/ISSUE_93_LOCALIZATION.md`.
- **Usage:** Missing authored translations and legacy cookie adoption are unknown.
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
- **Usage:** Preference absence and obsolete identifiers are unmeasured (`unknown`).
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
  callback before `config.php` and the first administrator exist. The repair must
  use the installer PDO for the shared writer lock and the installer-owned
  `galleries/` root rather than assume application bootstrap has run. Configured
  upgrades retain their configured gallery root and legacy/persistent Trash roots.
- **Era / owner:** Repair for issue #123, reported on 0.124.3. Owner:
  `gallery_description_layout_compatibility.php` and the canonical gallery edit model.
- **Evidence / tests:** `installer_first_install_test.php` exercises config-free
  HTTP installation, busy refusal and retry; `gallery_description_layout_compatibility_test.php`
  retains upgrade, fresh-state and interrupted sidecar/database replay coverage.
- **Usage:** Unknown; the issue documents one failed installation.
- **Lifecycle:** Permanent while standalone pre-configuration installation is
  supported. Retirement requires an equivalent tested installation protocol that
  supplies configuration before callbacks without weakening writer exclusion or replay.

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

- **Reason / protected scenario:** Shared-hosting proxies may deliver browser TLS
  over HTTP to PHP. Core/Admin and Viewer transport use one resolver, accepting
  this topology only with both `security.trusted_proxies` (exact IPv4/IPv6 or CIDR)
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
- **Evidence / tests:** `tests/request_https_proxy_test.php` first reproduced the
  forged HTTPS assertion, then covers direct transport, exact/CIDR proxy trust,
  IPv6, header opt-in/ambiguity, public URL schemes and Admin cookie Secure flags.
  `viewer_authentication_phase06_test.php` retains Viewer policy coverage.
- **Usage:** Proxy topology and wrapper adoption are unknown; no production
  measurement is inferred from source age or test coverage.
- **Support rationale / retirement:** Direct transport, explicitly configured
  proxy hosting and both wrapper entry points are permanent supported contracts.
  Wrapper removal needs complete caller migration and equivalent regression
  coverage. Unrestricted forwarded-header acceptance is unsafe and is retired;
  it is not retained as an implicit configuration fallback.

## Updater and release compatibility


### FILES-1 — Windows layout-migration sidecar sharing locks

- **Reason / protected scenario:** Windows briefly refused atomic replacement of
  an owned `gallery.json` during the release audit. The existing layout-migration
  owner retries the same complete staging file for at most ten attempts with nine
  50 ms pauses; other platforms retain one attempt.
- **Owner:** `app/services/gallery_description_layout_compatibility.php`.
- **Safety:** Recheck the original bytes before each retry, refuse concurrent
  edits or persistent storage refusal, never delete the previous sidecar, retain
  its permission-mode behavior, and remove only the owned staging file. A staging
  file that inherited read-only mode becomes owner-writable only for cleanup.
- **Evidence / tests:** `tests/gallery_description_layout_compatibility_test.php`
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
  `app/services/updates_jobs/state.php`, the existing updater state persistence owner.
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
  fallback's first introduction is unknown. Owner: `app/services/translations.php` and
  `app/lang/`.
- **Evidence / tests:** `tests/translation_catalog_consistency_test.php`
  validates maintained catalogs, but current evidence does not prove it
  executes the absent-JSON PHP fallback.
- **Usage:** Format fallback adoption is unknown.
- **Earliest safe retirement:** Only after supported packages and upgrade
  scenarios guarantee every supported JSON pack, the product owner explicitly
  ends PHP-catalog compatibility, and an absent-pack regression is replaced by
  a documented fail-safe. The current coverage gap must remain explicit.

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
  selector evidence by 2026-08-13. Owner: `app/services/translations.php` and request
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
  workers; HTTP injection belongs to `app/controllers/admin_maintenance_center.php`.
- **Evidence / tests:** `tests/maintenance_center_test.php` covers phase-specific
  module selection, controller injection and loading before task callbacks;
  `scripts/runtime_dynamic_dependencies.php` registers the reviewed loader.
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
  Upstream PHP 8.5.11 defines the [7 MiB read bound](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/file.h#L471)
  and [allocates that buffer before reading the file](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/magic.c#L201).
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
  either a reserved private child or PNG pages appended after existing photos. A source-bound reserved PDF child cannot be made public or unpublished until its conversion is complete; incomplete or cancelled work must first be finished or safely reconciled. This guard applies only to the reserved PDF child and leaves append targets and existing JPG page galleries unchanged.
- **Owner:** `app/services/gallery_pdf_documents.php` owns original documents;
  `app/services/gallery_pdf_conversion.php` owns resumable conversion state;
  source-gallery `.gallery-pdf-conversions/` sidecars hold markers for both
  private-child and append targets. `app/controllers/public_media.php` retains
  gallery access checks and the legacy OFP PDF-delivery route. The former
  `simbrief_action=convert_pdf_pages` browser request returns HTTP 409 with a
  reload prompt; the server-rasterizer helper remains only for CLI/test
  compatibility consumers and is not called by browser actions.
- **Ordinary upload default:** The browser document workflow attaches only the
  original PDF unless the administrator explicitly checks the Advanced conversion
  option. Missing controls also mean attachment only, so an older form cannot
  silently create a private child. The operation retains that choice across
  retries; acknowledged originals are not attached again. This opt-in policy is
  permanent, and existing separately launched document conversion remains supported.
- **Evidence:** The document service fixture covers exact bytes, stable identity,
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

- **Reason / protected environment:** During a native drag over the scriptless preview iframe, hosted Chromium delivered a trusted held pointer move to the parent overlay after handle capture was lost. The active gesture must continue only through the existing pointerId-guarded transaction while the parent overlay shields hit testing; the implementation does not assume a specific browser cause for capture loss.
- **Owner:** `public/assets/gallery-modules/theme-visual-css-resize.js` owns the existing resize transaction, temporary overlay shield, one overlay move/up/cancel listener set, capture attempt, cancellation and restoration. It does not add a second pointer pipeline or alter public-page interaction outside an active drag.
- **Evidence:** `tests/theme_visual_css_resize_model_test.mjs` covers the bounded transaction model; `tests/theme_visual_editor_browser_test.mjs` exercises native pointer movement, transient preview, commit and Escape restoration. The [W3C Pointer Events capture algorithm](https://www.w3.org/TR/pointerevents3/#setting-pointer-capture) keeps capture pending until subsequent event processing and requires the capture element to be in the pointer's active document. The [W3C cross-iframe capture issue](https://github.com/w3c/pointerevents/issues/493) documents unresolved boundary behavior; the `TESTING.md` browser matrix is the manual evidence owner.
- **Usage:** Unknown; no gesture or browser telemetry is collected.
- **Lifecycle:** Permanent while resize handles are parent-owned and the public preview remains in an iframe. The shield must be enabled only for an active transaction and restored on every commit, cancel, capture failure, frame teardown and workspace cleanup path.

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

- **Owner and reason:** Theme/Custom CSS preserves the existing `public/assets/custom.css` preset/upload contract while a separate editor owns `public/assets/custom-overrides.css`. Ordinary Theme saves, skin replacement and unrelated resets must not erase manual edits.
- **Protected behavior:** Existing installations retain their appearance when advanced controls are unset or invalid. Manual CSS is an optional final public layer; an absent, invalid-transport or unreadable override file is omitted without blocking public rendering, and the protected editor exposes a bounded error. CSS syntax recovery remains the browser's responsibility. Native editor POST/redirect remains supported with its own CSRF and explicit clear checkbox.
- **Composite background-save compatibility:** The existing `custom_css_overrides_save($text, $revision)` call and `css_override_action=save` route remain CSS-only when no background operation is requested. The visual editor's explicit global Theme target carries `keep`, `replace`, or `remove` plus the opaque background revision; replacement attaches the reviewed File and remove stages only deletion of the global Theme image. The operation preserves `theme_background_source` (the gallery fallback mode), per-gallery `background_source` values and gallery cover assets. Its controller-prepared public context distinguishes the global Theme image, Theme gallery fallback, explicit gallery source, and no image; when an independent gallery-derived layer is active, the visual editor disables the Theme image target on that route and directs the administrator to Home. If background revision inspection is unavailable, only image operations are disabled and the existing CSS-only editor/save path remains usable; a ready empty image state still permits first upload. An unavailable preview URL hides the visual launch without claiming that a preview exists, while the editor controls remain initialized. Owner: `app/services/custom_css.php`, `app/services/custom_css/visual_background_save.php`, `app/services/gallery_backgrounds.php`, and the application-settings model transaction. CSS lock precedes the shared Theme background writer lock. Legacy direct POST/redirect remains supported.
- **Visual-preview stylesheet compatibility:** `app/controllers/shared_layout.php` prepares the marked stylesheet URLs by starting with `Gallery\Core\asset_url()` and then adding the cache revision and visual-preview/audience query context; `app/views/layout.php` only emits that controller model. The canonical helper examines both `SCRIPT_NAME` and `SCRIPT_FILENAME`, preserving the same public asset path for repository-root, `public/` document-root, and subdirectory-mounted deployments. `app/bootstrap/request.php` carries the marker to otherwise unmarked dependent resources only for same-origin, in-mount GET/HEAD requests whose referrer has the exact request origin; ordinary public stylesheet URLs and non-preview requests retain their existing form. `app/services/custom_css.php` owns the fail-closed `@import` and stylesheet-inspection decisions, while dispatch enforces them after preview authorization and before rendering. Keep this request-context bridge limited to those resources and methods; it is not a general route authorization or cross-origin referrer fallback.
- **Evidence:** `tests/custom_css_visual_preview_inspection_test.php`, `tests/public_visual_preview_policy_test.php`, `tests/public_visual_preview_workflow_test.php`, and `tests/public_visual_preview_asset_url_test.php` cover stylesheet inspection, route/resource boundaries, and canonical asset URL composition. The real-route `tests/support/gallery_workflow_browser.js` asserts preview/public `base.css` path and marker behavior, loaded body reset, and strict saved-width parity. Existing `theme_advanced_appearance_test.php`, `custom_css_preservation_test.php`, `app_settings_atomic_activation_model_test.php`, `theme_custom_css_rendering_test.php`, and Appearance/Custom CSS Chromium fixtures cover defaults, independent assets/resets, transaction rollback, failed writes, stale editors, escaping, isolation and header restoration. Installed usage and document-root distribution are **unknown**.
- **Retirement:** Permanent support for installed preset/upload assets, CSS-only callers, direct/no-JavaScript editor forms, and repository-root, `public/` document-root, and subdirectory-mounted installations. Removing any contract requires an explicitly approved migration of saved CSS, supported administrator clients and deployment configurations, plus acceptance evidence; no age-based expiry.

## Retiring a record

Do not delete a compatibility path because its record is old or its branch is
not visible in a local search. The owner should update this entry with the
proof that meets its retirement condition, identify migration and release-note
effects, add or adjust the regression/acceptance evidence, and link the change
that removes the behavior. Permanent records may remain as architectural
contracts rather than accumulating expiry dates.

## Release provenance schema v1 and v_0.125 legacy predecessor

Owner: hosted release-origin/promotion tooling. Schema v1 is permanently supported
for immutable origins, including the already initialized v_0.126. Extended tag,
release-side and tree identities live in separate evidence and future immutable
predecessor records. Never rewrite the existing origin to migrate its schema.
Usage: one confirmed v_0.126 initialization; other usage unknown.

The repository-specific v_0.125 legacy pin protects the actual annotated historical
tag and identical published/release-side tree. Historical qualification is
UNKNOWN_LEGACY. It grants no write/publication authority and cannot qualify any
other predecessor. Retain this historical proof permanently; future initializations
use normal durable qualification evidence. Regression: release_origin_test.mjs and
linear_release_graph_test.mjs, registered in the central audit. Direct CLI release
notes retain their explicit tag-based default; hosted preparation supplies verified
release-side SHA and checks tree equality. Direct CLI usage is unknown.

Tagged releases can precede manual GitHub Release publication. This permanent
ordering is owned by `release-origin.mjs`: when published permanent assets are
unavailable, schema-3 predecessor validation loads that predecessor's original
FULL integration and BASIC release-delta archives through `coverage-artifacts.mjs`
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

New tagged releases may precede manual GitHub Release publication. Their owner is
`release-origin.mjs`; the protected behavior is valid successor initialization
from previous release-side Q without previous-M ancestry or automatic publication.
Complete exact hosted qualification plus the bound reviewed merge is mandatory;
unavailable or expired evidence blocks initialization. This remains supported
while manual GitHub publication is the normal contract. The real-Git linear and
completion contracts cover parent/tree identity and independent publication.

Permanent repairs from hotfix `9d187d3663f96f7fa10451001d61ddb31e276479` are recorded
in `docs/RELEASE_LIFECYCLE.md`. No hardcoded recovery identity, attestation comment
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
malformed/nonempty. Server protection remains authoritative for hidden membership;
visible policy identity, active target, effective checks/review/history requirements
and current-user bypass are independently validated. Usage: observed for both
production Actions-token responses; hidden membership is never claimed as checked.
Retire omission handling only if GitHub guarantees this field for the existing
token permission scope and live/negative regression evidence proves that contract.

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

The earlier owner-only integration bootstrap preserved release-path evidence while the exact
installed main `7dcfadf153b1c941fd81edee43272f8accb475a1` rejects bare upload-action
digests (observed run 38218271690). Its owner is the release lifecycle, not the
normal integration selector. It accepts a narrowly scoped repair feature and
uses actual develop -> immutable origin -> exact source overlay ancestry. For
unpublished `0.127.3` it retains the exact original `d4f9abd7` origin and metadata
bytes, with independently checked unchanged main/predecessor pins; it never
reinitializes that version after a branch deletion.
Existing schema-2 release qualification consumers retain their native job names,
require fresh complete FULL/release owners and ignore only the additive recovery
provenance field. No schema-3 inherited integration coverage is claimed.

Regression evidence: `develop_integration_test.mjs` verifies real Git ancestry,
tree preservation, absence of ref/index/worktree writes, changed marker and wrong
authority/scope refusal; `hosted_release_policy_test.mjs` verifies old FULL-owner
requirements including rejection of skipped Chromium. Live usage is **unknown**;
feature fixtures and maintenance FULL do not prove production recovery acceptance.
After a new main installation the exact-authority guard makes this path unusable.
Retire executable preparation only after no pending recovery origin uses it;
retain or migrate immutable marker/qualification readers before removing parsing.

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
`ci_authority_update: true`. It binds retained stage-1 proof and exact W*, permits
only six approved evaluator transition paths with unchanged scanner closure,
uses trusted inherited BASIC against a clean candidate checkout, and runs FULL
from W* on exact S with PHP 8.1 and 8.5 before leased non-force FF. Records
identify `authority_update` and `inherited_basic` explicitly without claiming
that W* is in main history. Ordinary main review, separate recovery, human PR
approval and manual publication remain in force. Report PENDING until real hosted
runs prove both stages and every write guard; fixtures and maintenance green do
not establish installation.

## Unchanged WinApp release attachments

Owner: `.github/scripts/release-draft-assets.mjs`. CMS-only releases retain the
original installer/update-metadata pair from the pinned published predecessor
when shipped WinApp inputs and the independent version are unchanged. Provider
SHA-256, byte size, metadata installer hash and the already authenticated
predecessor receipt/tag protect this download/update compatibility. Missing proof
fails closed. Usage is unknown until a future approved production release.
`tests/support/release_draft_assets_scenarios.mjs` covers fresh artifacts, reuse, changed-source
refusal, version mismatch and corruption. Permanent support is required while
clients discover the companion through each release's assets; retirement requires
migration of that discovery contract and supported-client acceptance evidence.

## Interrupted GitHub release transfers

Owner: `.github/scripts/release-completion.mjs` and
`.github/scripts/release-draft-assets.mjs`. A failed create response may follow a
successful server write. Read-only inventory reconciliation preserves that draft,
manual edits and any publication without a duplicate POST. Binary transfers retry
at most three times for the documented temporary HTTP/process categories; uploaded
bytes and mutable draft/tag identities are checked before every upload retry.
Permission, integrity and conflict failures remain terminal. The existing central
`release_draft_test.mjs` exercises CLI headers, absolute upload URL, original input
bytes, cleanup, bounded recovery and publication/conflict refusal. Production
complete upload usage is **unknown** after the observed v_0.127.5 transport failure
and manual publication. Retain recovery while GitHub responses may be interrupted;
retirement requires an accepted replacement preserving create-only idempotence,
original bytes and published-release immutability.

## Admin Images background preparation (#202)

The gallery editor owns the complete ordinary/no-JavaScript table fallback.
Drawer `panel=1` responses defer Images through the same authenticated route and
canonical row renderer. Retain the full-page path for supported browsers without
scripting and direct Admin navigation; usage is unknown. Retirement requires a
replacement that preserves all row actions, keyboard/accessibility and accepted
no-JavaScript journeys. `thumbnail_readonly_existing_response_file(..., exactOnly)`
defaults to false so existing visual-preview nearest-derivative fallback remains
compatible. The editor's explicit `existing_only=1` opt-in protects read-only
300px preload; ordinary media loading and generation keep their current owner.
Evidence owners and live acceptance limits are in [ADMIN_IMAGES_PRELOAD.md](ADMIN_IMAGES_PRELOAD.md).

## Manual cache diagnostic logging (#212)

Updater status JSON gains an additive diagnostic field with a nonblocking probe
of the existing worker lock and server-clock checkpoint age. Existing clients
can keep consuming ok/job; unsupported lock probes remain explicit unavailable
evidence and do not manufacture process activity. Usage is unknown. Status reads
never create a missing lock or rewrite lease metadata. Retry now retains the last
owner-redacted failure separately from the current error; legacy jobs without
that history remain supported without reconstruction. The cache fixture covers
metadata preservation, held/free/missing locks and retry history; the browser
fixture covers unchanged counter observations and compact activity labels.

Startup retains traffic-driven maintenance for ordinary routes/settings while
explicit cache actions and passive updater status reads own their work. This
permanent routing boundary prevents a status/stop request from advancing updater
work before authorization/CSRF controller handling. Security checks remain in the
existing dispatch path. Usage is unknown; the actual startup-dispatch fixture
`cache_transport_maintenance_test.php` protects both the exemptions and retained
ordinary behavior. The dashboard fixture protects failed phase counters and safe
owner-provided error/reference presentation.

The cache diagnostic copy control follows the existing Clipboard API plus legacy
selection/copy interaction used by Admin controls. It supports HTTP hosting and
denied Clipboard API access, reports actual copy failure and remains owned by the
cache UI module. Usage is unknown; retain this fallback until supported hosting
and browsers all provide accepted clipboard access. Job progress reuses the
existing authenticated Admin Updates status endpoint; stop reuses the locked
pre-activation cancellation service with canonical panel completion. Existing
direct/no-JavaScript POST fallback remains supported permanently. The dashboard
browser fixture covers copying after injection, phase counters and in-place stop.

The log service preserves the existing void best-effort API and adds a safe
insertion receipt for explicit diagnostic consumers. The cache diagnostic owner
stores a 26-character request-ID prefix to support the installed observability
migration while retaining the full 32-character ID in searchable JSON context.
This is permanent support for existing installations; usage is unknown.
Retirement requires a qualified schema migration covering every supported
installation. `cache_cleanup_diagnostics_test.php` covers column width rejection,
confirmed insertion, safe failure receipts and redaction. Manual internal-browser
verification additionally checked real persisted log entries on the local install.

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

The token-protected cron retains GET-token fallback for existing provider URL-only
schedulers, while preferring a header or POST. Owner: site maintenance controller.
Usage is unknown. Retire the URL fallback only after supported installations have
migrated scheduler credentials and accepted header/POST calls; do not log tokens.
Ordinary external cache-only requests still disable legacy thumbnail chaining.
The automatic cache owner now uses a separate authenticated POST/header chain
with committed single-use tickets, a dedicated live lock and bounded timer hops.
It permanently supports generic configured-origin HTTP hosting without requiring
a provider API or browser continuation. Missing absolute `base_url`, unsupported
network functions, loopback refusal or insufficient runtime is reported as a safe
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

Age-only legacy backup/orphaned-running-job pruning is retired because it cannot prove current/previous recovery ownership. Existing legacy payloads remain protected pending an owner migration; usage is unknown. Known terminal job pruning preserves the last successful rollback, active/source and selected beta references, and new scheduled ownership revalidates them under updater locks. Windows releases the terminal job child worker handle immediately before directory rename while retaining the start lock; terminal workers only read payloads and retries/rollback transitions use the start lock. The disposable lifecycle fixture covers contextual protection.

New GitHub/consumer leases use 256 stable buckets. Reclamation also checks any full-identity lease left by an earlier live checkout of this feature, protecting still-running producers while migrating lock granularity. Usage is unknown; retirement requires proof every earlier producer has exited and no supported caller uses that lease scheme. Bucket collisions are intentionally conservative and delay reclamation rather than weaken safety. The inherited BASIC audit runner remains unchanged. Native completed reports require matching JSON and the final in-run Markdown footer with subsequent publications outside that subtree; interrupted owner-only, missing/truncated Markdown and custom in-subtree publications remain protected. The lifecycle fixture covers these refusal paths and a held consumer lease.

Historical schema-1 audit reports without `source` are accepted only when recognized profile/environment/task statuses, their canonical run-directory identity and final native Markdown publication match. Downloaded exports retain the completed JSON contract because their native run Markdown may be outside the export. This protects real September audit reports observed during analysis without permitting filename-only pruning. Usage is confirmed locally; retirement requires migration/removal of these historical reports. The lifecycle fixture includes historical/current completed pairs and protected interrupted-run markers.

Registered legacy browser profiles and versioned release working copies lack reliable creation markers. Their fixed 24-hour retention uses a filesystem-derived age anchor persisted once; PHP ctime is not treated as portable birth time. Native browser fixtures now write creation evidence. The fallback is owned by cache lifecycle and permanently supports explicitly registered historical disposable roots; usage is confirmed from the incident screenshots. It can retire only after those roots are migrated/removed and all supported producers record creation. Regression fixtures cover recent writes without renewed expiry, the exact 24-hour boundary, fresh/pinned roots, legacy anchor stability and preserved persistent release controls. In-progress inventories initialize newly registered category counters without rewriting already approved previews; earlier observations remain in their original categories until a new scan.

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
ordinary-visit reclamation plus protected-data/OFF assertions. Retirement requires
a hosting support contract that guarantees the native probe for every supported
installation; generic shared hosting currently needs permanent support.

The cache lifecycle owner permanently supports PHP webhosts without
`fastcgi_finish_request`/`litespeed_finish_request`: a 40 ms admission opportunity
runs at shutdown and may add to client latency. Supported response finishing still
occupies the PHP worker and receives 120/600 ms admission; this is not a separate
thread. Usage is unknown until deployed arrival/progress evidence is observed.
The hosted public HTTP fixture covers the unsupported finishing path; disposable
visitor fixtures cover both budgets, contention and resumed deletion metrics.
No cron, provider API or incoming traffic guarantee is introduced. This fallback
can retire only if the supported hosting contract stops including such runtimes;
current generic PHP 8.1+ support requires it permanently.

`maintenance_center_model_mutation_owner(true)` refuses unknown storage before a
new visitor mutation. Existing nullable callers retain their historical
observation contract; the optional strict parameter is owned by the persistence
model and tested by the visitor unknown-claim fixture. Upgrade preference seeding
uses insert-if-absent, never an override of explicit administrator OFF. The old
900/3600-second deployed due markers are interpreted using the new thirty-second
completion/observation anchor; existing unfinished/approved cursors are never
migrated away. A numeric count of blocked inventory directories is partial
coverage, not a boolean worker failure. Actual cleanup blocking retains its
independent safe retry delay. Timer waiting occupies a PHP worker and cannot
guarantee recovery after all hosting processes terminate; independent scheduling
can provide that recovery without changing the generic cache owner.

## Cache registry transition and standalone previews

The cache lifecycle service preserves pre-registry JSON manual/automatic plans, committed queue fences and detached deletion payloads until their original owner drains them. Usage is unknown. The DB registry starts afterwards and retains the last legacy observation as historical until regional coverage is available. Invalid legacy state fails closed. Explicit manual previews and database-free standalone fixture consumers keep the historical API; production automatic workers require the migrated registry and refuse unavailable evidence. Removal requires proof that no deployed unfinished legacy jobs remain and supported standalone consumers have migrated. Regression owners: `cache_lifecycle_scheduler_test.php`, `cache_lifecycle_registry_test.php` and the real autonomous HTTP fixture. New regional work never restarts its root scan merely because a work opportunity or process restart occurred.

Workspace age records now bind their fixed anchor to the current producer marker and filesystem device/inode identity. An unbound historical age record is re-anchored conservatively, and changed identity is revalidated under the exclusive deletion lease before detachment. This protects a replacement at the same path from inheriting expiry. Producer-free legacy workspace usage is unknown; retain the conservative path while supported legacy roots remain.


## Historical flat download and updater archive ownership (#212, #220)

Owners: cache lifecycle policy/storage, the existing download producer and HTTP
stream boundary. Production screenshots confirm native flat gallery ZIPs with
40/64-hex signatures and older updater ZIP snapshot names remain deployed; exact
installation-wide adoption is unknown. Older cleanup only classified these bytes,
while positive eligibility covered newer metadata-backed artifacts. Permanent
historical support now requires native path plus ZIP/header or source-snapshot
proof, stable physical identity and producer/serving leases. Completed download
ZIPs keep the existing seven-day lifetime from payload modification, independent
of permission-change ctime. Native abandoned partials retain six-hour inactivity.
The updater keeps current installed code and a two-copy recovery window, with
active/selected/unknown recovery protected independently. No age-only general
backup/original deletion is restored. Retire this recognition only after deployed
historical payloads are migrated/drained and all supported producers use explicit
metadata. `cache_lifecycle_test.php` covers real ZIP shapes, expiry, active leases,
unknown content and retained copies; exact hosted FULL and production acceptance
remain separate gates. Existing API fields retain their meanings; the optional
manual `operation` evidence separates current progress from historical totals.
