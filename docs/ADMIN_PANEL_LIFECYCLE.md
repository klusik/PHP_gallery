<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/ADMIN_PANEL_LIFECYCLE.md
Module Type: Maintainer Guide
Purpose: Document drawer ownership, text recovery, keyboard focus and create/upload intent retention.
Responsibilities:
  - Define client integration boundaries, scoped regression evidence and remaining recovery limitations.
Author: Rudolf Klusal
-->

# Admin panel lifecycle

Author: Rudolf Klusal

The reusable Admin drawer is modal. Initial loading, ordinary gallery text drafts
and keyboard focus share the same open intent. Server authorization, persistence
and conflict detection still belong to the Controller -> Service -> Model path.

## Ownership

`admin-side-panel.js` selects workflows, mounts prepared HTML and submits existing
forms. `admin-panel-lifecycle.js` owns open/close generations, initial-GET
cancellation, focus and background isolation. `admin-panel-drafts.js` owns the
allowlisted text recovery state. Immutable browser limits and selectors are
documented in `admin-panel-policy.js`.

| Event | Ownership rule |
| --- | --- |
| Open A, then B | B aborts A's initial GET and increments the open generation. |
| A completes after B | A cannot replace body, heading, source URL, status or focus, even if transport ignores abort. |
| Close while loading | Close invalidates the load and pending opening animation. Its exit timer has its own generation check. |
| Close, then open C | Earlier GETs, animation frames and close timers cannot affect C. |
| A mutation completes after changing context | Its public contexts still reach the canonical coordinator; only its originating open intent may affect the drawer. |
| A mutation GET races another save | Both the open guard and the existing coordinator completion guard must accept it. |

The canonical `admin-mutation-completion.js` remains the sole owner of public
refreshes, retries, postcondition checks and mutation ordering. The new open scope
does not cancel a submitted write. An aborted initial GET or suppressed panel
callback does not imply that a server mutation was undone.

Submission handlers capture a panel guard before awaiting and associate it with
the response through a WeakMap, preserving the canonical envelope unchanged.
Detached forms still send successful results to public synchronization; they do
not dispatch a bubbling success event into a newly mounted form.

Independent modules that emit document-level mutation events must carry the
originating guard in `detail.panelOwner`, captured before their asynchronous work:

```javascript
import {captureAdminPanelOwner} from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

const panelOwner = captureAdminPanelOwner(form.closest('[data-admin-side-panel]'));
// Perform the existing explicitly requested mutation and preserve its result.
document.dispatchEvent(new CustomEvent('php-gallery:auxiliary-mutation-success', {
    detail: {result, panelOwner, refreshPanel: true},
}));
```

The same field is supported by `php-gallery:admin-image-order-saved` and
`php-gallery:metadata-organizer-applied`. Image reordering captures the guard
before its POST, and metadata organizer captures it before the first batch/paint
yield and carries it through final completion. The guard stays outside the
canonical result and does not create a second retry/completion workflow.
A connected form/button event can infer
its originating drawer when delivered; a document event without an owner cannot
refresh whichever workflow happens to be open. Capturing the guard only after
the request finishes does not establish ownership.

## Text recovery

Only ordinary gallery edit/create `title` and `description` text controls are
allowlisted. Gallery edit keys use the validated gallery identity; create keys
use the opening form's parent context. Drafts contain this key, the two text
strings and a bounded non-credential editor revision. They never contain a serialized
form, URL, CSRF value, password, share/API token, file, selected upload, visibility
setting or other access preference.

Only enabled hidden `expected_edit_revision` or `edit_revision` fields supply a
revision. Decimal versions and opaque hexadecimal snapshots compare as exact
strings. Up to 128 ASCII letters/digits or `.`, `_`, `:`, `-` are accepted;
missing, malformed or oversized values mean an unavailable revision and require
review. These fields must carry a non-credential concurrency precondition, never
an authentication secret. No arbitrary hidden field is read for draft recovery.

At most eight drafts and 262,144 UTF-16 text code units are retained per document.
These are combined memory limits, not field validation limits. When capacity is
exhausted, the complete edit remains in the current form and context replacement
requires Keep editing or explicit discard. Existing drafts are not evicted and
text is never truncated to fit. Browser storage is not used. Closing the document,
reloading or navigating away destroys this memory; recovery across those actions
requires a separately designed privacy/retention policy.

Editing displays a persistent inline unsaved notice. Closing or opening another
panel workflow offers Keep editing, Keep draft and continue, or Discard and
continue. None of these actions submits a form. Returning to an entity loads fresh
server HTML and offers explicit Restore text for review and Discard draft actions.
Restoration changes only the two text controls and keeps the fresh form's server
revision and CSRF fields. Changed or unavailable revisions produce an explicit
review message; restoration does not authorize automatic resubmission or bypass
the server's stale-edit checks.

A successful ordinary save acknowledges only the same submitted text, entity and
revision. New typing after submission survives its completion. Unrelated API,
photo, metadata or other successful mutations cannot clear a gallery text draft.
Validation/authentication failures preserve it. After reauthentication, an
administrator may reopen the editor and deliberately restore/review text; no
login retry automatically resends the failed write.

The complete editor's successful POST also returns top-level `edit_revision`:
a positive decimal string from the just-saved row captured under the server's
write ownership, not a subsequent refresh GET. The client advances the existing
enabled hidden revision control synchronously, before dispatching mutation
completion. This keeps the same form usable for a second explicit save while an
older editor GET is pending. It neither creates a revision field nor changes
title, description, privacy controls, CSRF fields or other server values.

Acknowledgment requires the original connected form, exact revision control,
mounted baseline, gallery identity, open generation and latest submission to
still agree. Decimal comparisons use `BigInt`, avoiding Number rounding; missing,
malformed, equal or regressing response revisions never advance the precondition.
Only corresponding retained draft metadata advances with it, preserving text
typed while the POST was pending. Detached, superseded or reopened forms cannot
borrow this response's revision. Another writer advancing the row after that save
still makes the next stale POST fail: no latest/conflict row is auto-merged and no
write is automatically retried. See [the server concurrency contract](GALLERY_EDIT_CONCURRENCY.md).

Before and after a mutation refresh GET, dirty text is checked again. When it
exists, the drawer keeps its current form and notice; the old callback is not
queued for later replay. Public synchronization proceeds independently. To review
fresh server fields, keep the draft, reopen the editor, then restore text
deliberately. A preserved dirty editor is not evidence that its non-text fields or
revision reflect the latest server state; its revision may only have advanced to
the row acknowledged by its own successful save.

## Create/upload intent retention

`admin-operation-keys.js` owns the browser's non-credential create/upload intent
identity. The original enabled hidden `operation_key` must contain 64 lowercase
hexadecimal characters. The module never substitutes CSRF/session/batch identifiers
or silently generates a missing server form key. The shared browser-only
`admin-panel-policy.js` owns 32-byte random
key generation, the explicit non-credential scalar comparison allowlist and the
1,048,576 UTF-16-code-unit comparison limit. This comparison is not a second
server fingerprint, authentication policy or persistence ledger.

The first classic multipart request uses the form key. Every subsequent one-file
request receives a distinct cryptographically random key, retained under that
intent's request index. An explicit retry replays the same sequence with the same
keys and immutable File references; it does not infer success from filenames or
cache server authorization locally. The first create-with-upload response again
supplies the existing target for the remaining chunks. Each replay response goes
through the existing canonical aggregation. No classic retry timer was added.

Empty create and prepared-upload create bootstrap also forward the original key.
The prepared uploader retains its existing session ID, batch indexes and exact
ZIP blobs after server work may have started. Its existing bounded batch retry
and acknowledgment cleanup remain the batch owner; operation keys are not added
to prepared ZIP requests. A later explicit form retry reuses those same batches,
including replaying their server-authorized acknowledgments. Preparation failure
before server work still cleans up its own temporary packages.

Only a confirmed canonical result for the entire intended sequence rotates the
original connected form's key. Network errors, busy/authentication responses,
incomplete envelopes and changed input cannot rotate it. Another form, an older
detached form or a refresh callback cannot inherit this authority. Newly rendered
forms already carry independent server keys. A new deliberate submission after
confirmed completion may create/upload identical content under a fresh key.

Uncertain operation state remains attached to the original form, not to the text
draft map. An inline guard blocks drawer close/context replacement and unrelated
mutation refresh while that form is unresolved. This preserves its key, exact
non-text inputs and original file selection together, instead of copying a key
into a text-only draft with a different semantic payload. Changed input is refused
until the original intent is restored/resolved; there is no clear-key-and-retry
escape hatch. The guard does not submit, open a browser dialog or merge server
settings. It clears after confirmed completion.

No operation key, scalar snapshot, multipart body, CSRF token or session credential
is added to browser storage. The existing prepared-package IndexedDB store remains
unchanged; operation identity/session recovery is document-local. Reloading or
navigating away loses this in-memory association, and unknown server work must not
be replaced by a fresh intent merely because a new form can be loaded. Exact retry
requires the original File objects; reselecting byte-equivalent files is not yet
client-side content-hash recovery. Reauthentication must provide valid current CSRF
in the retained form; this module excludes CSRF from comparisons and reads it afresh
for each request, but does not add a login/CSRF-refresh workflow.

`tests/admin_operation_keys_browser_test.mjs` reuses the confined Chromium runner
with `tests/fixtures/admin_operation_keys.html`. It drives actual create/classic
handlers and real prepared worker/ZIP packaging against a synthetic payload-bound
ledger. It checks lost acknowledgments, per-chunk replay, deliberate duplicates,
current-CSRF forwarding, changed file/thumbnail refusal, incomplete envelopes,
concurrent submission suppression, detached completion and inline guard focus.
It is not SQL/HTTP authentication, physical disconnect or cross-document recovery
evidence. Register it with `browser => true`, `timeout => 60`; the parent owns
central audit and real-server integration.

## Modal keyboard behavior

Opening immediately focuses the persistent Close control during loading, marks
background body siblings inert and observes newly inserted siblings. Loaded
content receives its first visible eligible control. Errors focus the direct-page
recovery link. Tab/Shift+Tab use the current rendered controls, including disabled
fieldset, hidden, inert and tabindex handling. A loading/empty body can always
fall back to Close or the dialog itself.

Window-level key handling lets title completion and destination pickers consume
their own Escape/Tab events first. An expanded legacy picker is also observed
before its target handler closes it, because that handler did not cancel Escape.
An unclaimed Escape asks the same inline draft guard used by the Close button.
The draft guard is an inline notice, not another modal or browser confirmation.

Accepted body replacement restores an equivalent visible control by id/name, or
chooses a meaningful current control. Closing restores the connected opener or a
visible replacement action/main landmark. Temporary landmark focusability lasts
until blur; removing tabindex immediately would discard focus in Chromium.
Previously inert background nodes retain their exact prior attribute values.
The closing drawer itself is inert during its exit animation.

## Regression and integration

`tests/admin_panel_lifecycle_browser_test.mjs` serves the production browser
modules and `tests/fixtures/admin_panel_lifecycle.html` from a confined loopback
server. It uses a fresh Chromium profile, native DevTools Tab/Shift+Tab/Escape,
real DOM focus and controlled delayed fetch results that deliberately ignore abort.
It requires Chromium and Node 22+ native WebSocket support. It does not load PHP,
the checkout's configuration, any database, or gallery/media data. Only its exact
owned browser profile is deleted during cleanup.

Covered cases include reversed initial responses, close/reopen timing, dirty
context guards, explicit restore, revision review, secret-field exclusion, failed
saves, edits during POST/GET, unrelated mutations, detached completion, repeated
dynamic saves, memory-cap refusal, nested title/picker keys, inert restoration,
removed openers and unchanged document/URL. It also exercises real reorder and
metadata-organizer emitters with delayed responses, current-owner refreshes,
opaque revision comparison, and bulk moves using an enabled hidden parent,
explicit root or legacy select. Its ordinary-editor POST stub enforces exact
revision equality. It covers synchronous revision acknowledgment before a held
GET, successful sequential saves on the original form, stale-writer refusal,
in-flight text/privacy preservation and detached/superseded acknowledgment guards.
This fixture's responses are synthetic;
it does not prove authentication, persistence or database conflict behavior.

The fixture uses minimal layout CSS and Chromium on one host. Product-stylesheet
visual review, Firefox/WebKit, mobile/touch and screen-reader review remain
separate acceptance work. Existing disposable PHP/browser workflow tests and the
central full audit remain necessary integration evidence.

Register the lifecycle fixture in `scripts/audit_registry.php` with `browser => true` and
`timeout => 60`. Cache-bust both the `admin-side-panel.js` re-export in
`admin-operations.js` and the `admin-operations.js` import in `gallery.js` whenever
this behavior changes; use `20260920-operation-keys-v1` or a later coordinated
revision. Update source contracts that assert the old import revision. The
image-reordering and metadata-organizer re-exports also use
`20260920-panel-lifecycle-v1`; the side panel's direct image-reordering import uses
the same revision so there is one module instance for that workflow.
The side panel's browser-upload and draft imports use
`20260920-operation-keys-v1`; both import the same operation-key module instance.

The bulk new-gallery move submission accepts either an enabled canonical hidden
`new_gallery_parent_id` input or the legacy select. It preserves the exact string
value, including root `0`, and ignores disabled no-JavaScript fallback controls.
See [the bounded picker contract](GALLERY_PICKER.md). The server remains the
destination validation and mutation authority.

All new browser copy has an English `i18n` fallback. Translation catalogs should
provide these keys under `admin.side_panel`:

| Suffix | English fallback |
| --- | --- |
| `close` | Close |
| `draft_unsaved` | Unsaved title or description. Text is kept only while this page remains open. |
| `draft_capacity` | Draft memory is full. Keep editing or explicitly discard before leaving this form. |
| `draft_review` | A text draft is available. Review the current server values before restoring; the server revision has changed or cannot be verified. |
| `draft_available` | A text draft is available for this gallery. Restore it only when ready to review and save. |
| `draft_restore` | Restore text for review |
| `draft_discard` | Discard draft |
| `draft_keep_editing` | Keep editing |
| `draft_keep_continue` | Keep draft and continue |
| `draft_discard_continue` | Discard and continue |

The notice reuses `.notice` and `.button.secondary`; `.admin-panel-draft`,
`[data-admin-panel-draft]` and `[data-admin-panel-draft-action]` are the explicit
style/test hooks. Catalog, stylesheet and entry-import integration are independent
of the lifecycle modules' fallback behavior.
