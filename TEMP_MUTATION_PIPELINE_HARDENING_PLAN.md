# TEMP: Admin Mutation Pipeline Fundamental Hardening Plan

> Temporary implementation roadmap. Keep this file in the repository root while the staged hardening is in progress. Update the status/checklists as each stage is completed. The final deletion is intentionally release-deferred: remove this file only during the explicit pre-release cleanup after permanent documentation and validation are complete.

## Status

| Stage | Scope | Status |
| --- | --- | --- |
| 1 | Canonical mutation contract, completion coordinator, test foundation | Completed |
| 2 | Core gallery and image mutation migration | Completed |
| 3 | Embedded and auxiliary workflow migration | Completed |
| 4 | Verified synchronization, multi-context refresh, race/cache hardening | Completed |
| 5 | Enforcement, cleanup, permanent documentation, TEMP file removal | Implemented; release closure deferred |

## Why this work exists

The gallery currently has several independently evolved mutation completion paths. Most server-side mutations are themselves reliable, but the browser does not have one canonical way to answer the questions that matter after a successful mutation:

1. What entity changed?
2. Which public gallery context or contexts are now stale?
3. Which side-panel fragment is stale?
4. What URL should be fetched to re-render those contexts?
5. What state must be observable before the browser may consider synchronization complete?
6. What should happen if the first re-render is stale, arrives out of order, or represents the wrong context?
7. Which behavior is allowed only for the direct-page/non-JavaScript fallback?

The recent "Add gallery here" bug exposed this class of defect clearly. The server completed the mutation, but response metadata was lost in an aggregation layer and the browser treated an HTML replacement as proof that the new state was visible. Similar patterns exist elsewhere.

This project should therefore stop fixing each mutation with a separate success handler. The goal of these stages is to create one explicit mutation completion architecture and migrate every side-panel-owned persistent mutation to it.

## Non-negotiable invariants

These invariants apply to every stage and should become permanent regression requirements.

### Side-panel invariants

For a persistent mutation launched from the Admin side panel while JavaScript is enabled:

- The side panel remains mounted and open unless the user explicitly closes it.
- The browser does not navigate to a standalone Admin route.
- The mutation success path does not assign `window.location`.
- The mutation success path does not call `window.location.reload()`.
- The mutation success path does not use `history.replaceState()` as a substitute for correctly synchronizing the page.
- The visible browser URL remains unchanged during the side-panel operation.
- A normal POST/redirect path remains available only for direct-page or non-JavaScript use.
- Dynamically re-rendered panel controls continue to be intercepted through delegated or otherwise lifecycle-safe event handling.

### Mutation invariants

- Authentication, authorization, CSRF, schema safety, path safety, validation, and existing mutation services remain authoritative.
- A JSON/AJAX request must always receive a JSON response for both success and expected failure paths.
- A successful persistence operation must not fall through to a redirect in the AJAX branch.
- A failed mutation must not be reported as synchronized merely because a fragment fetch succeeded.
- A successful mutation is not considered fully reflected until the relevant observable postcondition is satisfied, or the UI reports a controlled synchronization failure while preserving the successful server mutation result.
- Server-rendered public and panel HTML remain the source of truth. Client-side DOM patching may be used as a bounded fallback or micro-update, not as an independent rendering model.

### Refresh invariants

- Refresh requests use the existing authenticated/public authorization paths. No parallel media or gallery access path is introduced.
- Refresh requests use `cache: 'no-store'` plus the existing cache-busting query convention.
- Gallery identity is determined by stable entity identity such as gallery ID, not only by string equality of URLs.
- The browser URL and the server-render URL are separate concepts. A gallery rename or move may require fetching the new canonical gallery URL while leaving the visible browser URL untouched during the active side-panel workflow.
- Pagination, filters, active Admin tab, side-panel scroll, and public-page scroll are preserved where practical.
- Multi-context mutations explicitly identify every affected context instead of assuming that only the source gallery changed.

## Current pipeline inventory and confirmed weaknesses

This section records the code conditions that motivated the staged work. It is not intended to stay as permanent architecture documentation after Stage 5.

### Existing central side-panel path

Primary browser coordination currently lives in:

- `public/assets/gallery-modules/admin-side-panel.js`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/gallery-modules/admin-browser-upload.js`
- `public/assets/gallery.js`

Current enhanced workflows include at least:

- gallery create/upload
- gallery edit
- image edit
- tag edit
- gallery image bulk actions
- Smart Gallery editing
- Duplicate Photo Detector
- metadata organizer event integration
- image reordering event integration

The current `php-gallery:side-panel-success` event is useful, but the payload and completion semantics depend heavily on the workflow-specific caller.

### Confirmed issue: bulk visibility and NSFW JSON mismatch

`app/controllers/admin_images_bulk.php` has correct JSON branches for several actions, including move and delete, but `draft`, `public`, `private`, `nsfw_on`, and `nsfw_off` can successfully modify the database and then continue into classic flash/redirect behavior.

The side-panel JavaScript submits the same bulk form as AJAX and expects JSON. This is a real request/response contract violation, not only a theoretical stale-render risk.

### Confirmed issue: embedded scan/import bypasses panel completion

`app/controllers/admin_galleries_edit_page.php` renders the `admin_scan_images` form inside the gallery editor. The side-panel preparation logic marks the main edit form and image bulk form, but this scan/import form is not owned by the common AJAX completion path.

When used from the embedded editor it can therefore execute the standalone POST/redirect workflow and leave the side-panel contract.

### Confirmed issue: Force AI metadata regeneration bypasses panel completion

The `force_ai_reprocess` action in the gallery editor performs its mutation and redirects to an Admin edit URL. It needs an AJAX response and explicit affected-context metadata when initiated from the side panel.

### Confirmed issue: gallery rename/move can invalidate the refresh source

Gallery edit can change hierarchy and path identity through fields such as slug/folder/parent. The server can return the new canonical `gallery_url`/`refresh_url`, but the client still has code paths that prefer the old `window.location.href` for the public refresh.

The correct model must distinguish:

- visible browser URL, which remains unchanged during the side-panel workflow;
- current gallery identity, which remains stable by ID;
- canonical render URL, which may change after rename/move/reparent.

### Confirmed issue: Media Renamer is a parallel mutation pipeline

`public/assets/gallery-modules/admin-media-renamer.js` and `app/controllers/admin_media_renamer.php` maintain their own completion model. The browser primarily replaces tool-specific HTML and contains a hard reload fallback.

A physical media rename can affect public image URLs, titles, derivative metadata, ZIP metadata, and visible gallery content. It must participate in the same affected-context invalidation contract as other gallery mutations.

### Confirmed issue: Duplicate Photo Detector uses local deletion as public synchronization

The detector correctly performs its mutation through JSON, but the browser can remove individual visible nodes locally. Image deletion may also affect:

- cover/title image selection;
- gallery image count;
- pagination and page count;
- the current public grid;
- derived gallery metadata.

The detector panel fragment may still be updated locally, but public gallery synchronization must use the common context pipeline.

### Confirmed issue: Metadata Organizer contains reload fallbacks

The metadata organizer dispatches an integration event, but current side-panel handling still contains `window.location.reload()` fallback behavior when panel/public refresh fails.

A synchronization failure should be reported explicitly. A hard reload must not silently mask a broken side-panel pipeline.

### Existing improvement that should become the standard

The recently hardened create/upload path carries `created_gallery`, identifies the owning gallery by ID, performs no-store/cache-busted refreshes, and verifies that the newly created gallery actually appears in the refreshed DOM before synchronization is considered complete.

That model should be generalized. The implementation should not copy the create-specific helper into every workflow.

## Target architecture

The final design should contain three explicit layers.

### Layer A: mutation service

Existing mutation services continue to own database/filesystem state changes. This work should not duplicate or relocate core persistence simply to support AJAX.

Examples include existing gallery creation, save, image move/delete, upload, renamer, metadata, and detector services.

### Layer B: canonical mutation result/envelope

Controllers adapt a successful service result into one common browser completion contract. Direct-page controllers may still translate the same result into a flash message plus redirect when JSON was not requested.

The exact PHP names can be selected during implementation, but the response should conceptually contain data equivalent to:

```json
{
  "ok": true,
  "message": "...",
  "mutation": {
    "type": "gallery.create",
    "entity": "gallery",
    "action": "create",
    "entity_ids": [123]
  },
  "panel": {
    "workflow": "gallery-edit",
    "refresh_url": "/admin/...",
    "keep_open": true
  },
  "contexts": [
    {
      "type": "gallery",
      "gallery_id": 55,
      "render_url": "/gallery/parent/",
      "postcondition": {
        "type": "gallery_present",
        "gallery_id": 123
      }
    }
  ],
  "fallback": {
    "redirect_url": "/admin/..."
  }
}
```

Important principles:

- Do not expose a framework-like generic command language to the browser.
- Keep the contract small and typed around actual gallery mutation needs.
- Stable IDs are authoritative. URLs are render locations, not entity identity.
- More than one affected context is allowed.
- Direct-page redirect information may exist, but the side-panel coordinator must not execute it on a successful enhanced workflow.
- Legacy top-level fields may be emitted temporarily during migration, then removed or normalized in Stage 5 if no longer needed.

### Layer C: browser completion coordinator

One framework-free JavaScript module should own mutation completion semantics. The exact filename can be chosen during Stage 1, but a dedicated module is preferable to continuing to grow `admin-side-panel.js`.

Responsibilities should include:

1. Normalize/validate the mutation envelope.
2. Preserve active side-panel workflow state.
3. Re-render the owned panel fragment when requested.
4. Resolve public contexts by stable gallery identity.
5. Fetch authoritative server-rendered HTML using no-store/cache-busting semantics.
6. Replace only the canonical owned public fragment.
7. Verify the declared postcondition.
8. Perform a bounded retry only when the mutation succeeded but the expected state is not yet observable.
9. Reject stale/out-of-order refresh responses.
10. Report synchronization failure without converting it into a full-page reload.
11. Emit a single completion event for tool-specific UI that needs to update secondary controls.

The coordinator must not become a second renderer. PHP remains responsible for HTML generation.

## Standard postconditions

The initial verifier vocabulary should stay deliberately small and correspond to real mutations.

| Mutation | Expected observable postcondition |
| --- | --- |
| Gallery create | New gallery ID exists in the owning parent subgallery region. |
| Gallery delete | Deleted gallery ID does not exist in the owning parent region. |
| Gallery move/reparent | Gallery ID is absent from source parent and present in destination parent when that context is currently rendered. |
| Gallery rename/path change | Current gallery identity remains the expected ID and server-rendered title/path metadata reflect the saved state. |
| Image upload | Returned image IDs are represented when they belong on the currently rendered page; otherwise gallery count/pagination metadata must be refreshed authoritatively. |
| Image delete | Deleted image IDs are absent from the refreshed public context. |
| Image move | Moved image IDs are absent from source; destination is refreshed when currently visible or otherwise marked as affected without forcing navigation. |
| Image visibility public -> non-public | Affected image IDs are absent from the public grid. |
| Image visibility non-public -> public | Public context is re-rendered; presence is required only when pagination/order says the image belongs on the visible page. |
| NSFW policy change | Current public representation and authorization-sensitive metadata are re-rendered; do not infer access policy only from a local CSS/DOM toggle. |
| Cover/title picture | Refreshed hero/cover identity matches the server-selected cover state. |
| Media rename | Stable image IDs remain, while server-rendered URLs/titles correspond to the new persisted media state. |

Some postconditions require pagination metadata or a server hint to avoid false failures. Those hints should be explicit in the response contract rather than inferred from client-side guesses.

# Stage 1: Canonical contract and completion foundation

## Goal

Create the common architecture before migrating the individual workflows. This stage should intentionally make only the minimum necessary behavior changes needed to prove the contract and coordinator.

## Work

### Server foundation

- Introduce one small controller/helper layer for canonical JSON mutation responses.
- Centralize detection of JSON/AJAX mutation requests where practical instead of mixing raw `HTTP_ACCEPT`, `ajax`, `panel`, and route-specific checks.
- Define helpers for:
  - success envelope creation;
  - expected error envelope creation;
  - panel refresh metadata;
  - affected public gallery context metadata;
  - typed postcondition metadata.
- Preserve existing service calls and direct-page redirect behavior.
- Do not yet rewrite every controller in this stage.

### Browser foundation

- Add a dedicated mutation completion module, imported through the existing cache-busting convention.
- Move generic concepts out of workflow-specific code:
  - context refresh request construction;
  - no-store/cache-buster behavior;
  - stable gallery identity matching;
  - owned-fragment replacement;
  - postcondition verification;
  - bounded retry policy;
  - synchronization error reporting;
  - stale/out-of-order response protection primitives.
- Keep `admin-side-panel.js` responsible for drawer lifecycle and form interception, not the details of mutation synchronization.
- Keep existing workflow handlers functional through an adapter while Stage 2 and Stage 3 migrate them.

### Test foundation

Add focused tests that do not require a live browser where possible:

- PHP contract tests for success/error envelope shape.
- JavaScript model tests for postcondition evaluation.
- JavaScript tests for stable gallery identity versus URL differences.
- JavaScript tests for bounded retry and stale response rejection.
- A static/contract test proving dynamically injected side-panel forms are intercepted through delegated handlers.

The authoritative deployment ZIP currently omits the existing repository `tests/` directory. New focused tests may therefore need to be added to the affected-files package while full-suite execution remains blocked in this packaging environment. In the real repository, run the complete available suite as well.

### Stage 1 implemented contract

Stage 1 establishes the following implementation as the only canonical contract for new mutation migrations:

- PHP envelope helpers live in `app/helpers_mutation.php`.
- `admin_wants_json()` is the shared Admin JSON/AJAX mutation detector. Route-specific detection may remain temporarily where Stage 2/3 has not migrated the controller yet, but new mutation migrations must use the shared helper.
- A successful enhanced mutation uses `admin_mutation_success_envelope()` with these top-level keys: `ok`, `message`, `mutation`, `panel`, `contexts`, and `fallback`.
- `mutation` contains `type`, `entity`, `action`, and stable `entity_ids`.
- `panel` contains `workflow`, `refresh_url`, and `keep_open`; it is refresh metadata, not a browser navigation command.
- Each public gallery context contains `type`, stable `gallery_id` when a persisted gallery exists, `render_url`, `render_mode`, and optional typed `postcondition` metadata.
- `render_mode=preserve_view` keeps current pagination/filter state when stable gallery identity still matches. `render_mode=canonical` is reserved for mutations such as rename/move where the old visible route may no longer render the persisted entity.
- `fallback.redirect_url` exists only for the direct-page/non-JavaScript path. The enhanced coordinator must not execute it after a successful side-panel mutation.
- Expected enhanced failures use `admin_mutation_error_envelope()`. The temporary top-level `error` string remains for compatibility while `error_code` is the stable bounded category.
- Temporary legacy top-level response fields may coexist with the canonical envelope until Stage 5 removes migration adapters.

Browser completion is owned only by `public/assets/gallery-modules/admin-mutation-completion.js`. It normalizes the envelope, matches concrete gallery contexts by stable ID, constructs no-store/cache-busted render requests, performs owned-fragment replacement, verifies typed postconditions, applies bounded retries, rejects stale responses, and reports synchronization failure without a hard reload or navigation fallback.

The existing empty-create and create-with-upload responses now emit this envelope. The current create verified-refresh path is executed through the coordinator, while non-migrated Stage 2/3 workflows continue through their existing adapters.

## Stage 1 exit criteria

- There is exactly one documented mutation envelope for new migrations.
- There is exactly one browser completion coordinator for public-context synchronization.
- Existing create/upload verified-refresh behavior can be expressed through that coordinator without regression.
- No new full reload or navigation fallback is added.
- Foundation tests pass.
- All changed PHP files pass `php -l`.
- All changed JavaScript files pass `node --check`.
- `git diff --check` passes.

# Stage 2: Core gallery and image mutation migration

## Goal

Migrate the mutations that directly alter the public gallery tree, gallery identity, public image set, or cover state. After this stage, the most important public-facing mutations must all use the same completion architecture.

## Work

### Gallery mutations

Migrate at least:

- empty gallery creation;
- create-and-upload gallery creation;
- gallery edit/save;
- rename/slug/folder path changes;
- reparent/move;
- visibility changes;
- gallery deletion where launched from the enhanced public/Admin workflow;
- gallery title/cover changes that are part of the core editor.

For gallery rename/move/reparent:

- return stable gallery ID plus the new canonical render URL;
- keep the visible browser URL unchanged;
- refresh using the new render URL when the old route no longer represents the saved gallery;
- update the rendered context's own source metadata so subsequent mutations do not fall back to the obsolete URL;
- refresh source and destination parent contexts when both are relevant.

### Image mutations

Migrate at least:

- bulk delete;
- single-row delete routed through the bulk controller;
- move to existing gallery;
- move to newly created gallery;
- `draft`/`public`/`private` visibility changes;
- `nsfw_on`/`nsfw_off`;
- cover/title-picture selection;
- ordinary upload into an existing gallery;
- browser-prepared upload batches.

### Mandatory server correction

Fix `app/controllers/admin_images_bulk.php` so every AJAX-recognized action returns JSON on both success and controlled failure. In particular, database success must never fall through into `flash_message()` + `redirect_to()` when `admin_wants_json()` is true.

### Upload aggregation

- Preserve all completion metadata through both classic and browser-assisted aggregation paths.
- Preserve created gallery IDs.
- Preserve uploaded image IDs or equivalent structured identifiers needed for postcondition validation.
- Do not let a batching layer discard context metadata returned by the individual mutation result.

### Panel behavior

- Keep the gallery editor/upload drawer open after all migrated core mutations.
- Refresh the active panel fragment only when its content is affected.
- Preserve active tab and drawer scroll.
- Preserve public scroll where fragment replacement permits it.

## Stage 2 regression matrix

At minimum test:

1. Create gallery under currently visible parent.
2. Create gallery from a paginated/query-bearing parent URL.
3. Rename currently visible gallery.
4. Reparent currently visible gallery.
5. Delete a gallery card from a parent.
6. Upload images into the currently visible gallery.
7. Delete images affecting image count and pagination.
8. Move images out of the current gallery.
9. Move images into a newly created child gallery.
10. Toggle public/private/draft visibility.
11. Toggle NSFW Guard.
12. Change cover/title picture.
13. First refresh returns stale HTML, later bounded refresh returns the saved state.
14. URL remains unchanged and drawer stays open in every enhanced case.

### Stage 2 implemented contract

Stage 2 moves the core gallery/image mutation paths onto the Stage 1 envelope and completion coordinator without introducing a second browser renderer:

- Gallery edit/save returns the stable gallery ID, authoritative post-save render URL, source/destination parent contexts, and an observable gallery render revision. Rename/slug/path changes use `render_mode=canonical`, while the visible browser URL remains unchanged. The coordinator stores the new render source on the refreshed hero so later saves do not fetch an obsolete route.
- Gallery hero and physical gallery-card markup expose bounded mutation verification metadata. Both can verify the persisted `updated_at` value, so a stale first refresh cannot pass merely because the stable gallery ID or visibility is unchanged.
- Public gallery-card deletion has an enhanced JSON path with a `gallery_absent` parent postcondition. Hero deletion keeps its direct-page redirect behavior because a deleted gallery page has no valid same-URL context to refresh. Remaining public inline image/tag/edit workflows stay assigned to Stage 3.
- Bulk image delete, row delete through the bulk controller, move to existing/new gallery, visibility, NSFW Guard, title-picture selection, and thumbnail generation return JSON on AJAX success. Controlled action failures return the canonical JSON error envelope instead of falling through to redirect behavior.
- Image delete/move/upload completion verifies authoritative gallery image counts. Visibility and NSFW actions verify server-rendered image state when those image rows are observable on the current page. Cover changes verify the persisted cover image ID.
- Move-to-new-gallery results include source gallery, destination gallery, and destination-parent contexts. Reparented galleries include the moved gallery plus old/new parent contexts.
- Classic existing-gallery upload and every browser-prepared upload batch emit canonical completion metadata. Browser aggregation retains `mutation`, `panel`, `contexts`, `fallback`, upload events, created gallery IDs, and all uploaded image IDs across batches.
- `admin-side-panel.js` delegates gallery save, core image bulk, and existing-gallery upload synchronization to `admin-mutation-completion.js`. The active drawer remains mounted, active tab and drawer scroll are preserved by the server-fragment refresh, and enhanced core completion does not navigate, reload, or rewrite browser history.
- Focused Stage 2 model/contract checks cover gallery render revision verification on hero/card markup, canonical render-source persistence after rename, image count/visibility/NSFW postconditions, bounded stale-first retry, upload aggregation, delegated core completion, and public gallery-card delete interception. The authoritative deployment ZIP still omits the repository `tests/` directory, so the complete historical `php tests/run.php` suite cannot be executed in this packaging environment.

## Stage 2 exit criteria

- No core gallery/image side-panel mutation relies on route-specific ad hoc success logic for public synchronization.
- Bulk visibility and NSFW AJAX mismatch is eliminated.
- Gallery path identity changes refresh from the correct canonical render source without changing the visible browser URL.
- Upload aggregators retain the identifiers needed for verification.
- Core mutations have explicit postconditions.
- Relevant focused tests and the available project suite pass.

# Stage 3: Embedded and auxiliary workflow migration

## Goal

Remove the remaining parallel mutation pipelines from tools embedded in the gallery editor or reachable through the side panel.

## Work

### Scan/import images

- Mark the embedded scan/import form as panel-owned.
- Add a JSON completion path to its controller.
- Reuse the existing scan/import mutation service.
- Return affected gallery context and any useful imported image IDs/counts.
- Keep standalone POST/redirect behavior for direct-page/non-JavaScript use.

### Force AI metadata regeneration

- Add an explicit JSON path for `force_ai_reprocess`.
- Keep the drawer open.
- Refresh the API/AI editor fragment as necessary.
- Refresh public content only when the operation changes currently visible metadata; do not refresh blindly if the action only queues future background work.
- Make the response distinguish "mutation completed" from "background work queued".

### Media Renamer

- Migrate `admin-media-renamer.js` and server responses to the canonical mutation envelope.
- Preserve tool-specific preview/result HTML inside the panel, but send public invalidation through the shared coordinator.
- Remove `window.location.reload()` from the enhanced path.
- Return stable affected image IDs and gallery IDs.
- Refresh URLs/titles from server-rendered public content after physical rename.

### Metadata Organizer

- Replace event-specific reload fallbacks with the canonical coordinator.
- Allow the organizer to continue emitting its tool event if useful, but the event should carry a canonical mutation result rather than invent another completion schema.
- Synchronization failure must display an error/status while preserving the panel, not hard reload.

### Duplicate Photo Detector

- Keep detector job/panel HTML behavior.
- Route public gallery invalidation after delete through the common coordinator.
- Do not treat local `element.remove()` as sufficient proof that the public gallery is synchronized.
- Include deleted image ID, owning gallery ID, and affected cover/count context in the mutation envelope.

### Image reordering

- Convert the existing `php-gallery:admin-image-order-saved` integration into the canonical result path or a thin adapter that supplies equivalent context/postcondition metadata.
- Verify reordered server-rendered state when the public ordering is visible.

### Image edit and tag edit

- Bring their side-panel lifecycle into the same keep-open rule unless a product requirement explicitly says otherwise.
- Remove side-panel URL mutation through `history.replaceState()`.
- For tag slug changes, refresh from the new server render URL while preserving the visible browser URL, analogous to gallery rename.
- Return explicit affected public contexts rather than relying on route-type guesses.

### Smart Galleries

- Inventory every Smart Gallery action rendered in a side panel, including create/edit, placement update, detach, duplicate, and delete.
- Normalize AJAX responses to the common envelope while preserving Smart Gallery-specific editor HTML.
- Refresh physical gallery contexts whose Smart Gallery placement/output changed.

### Picture Manager and public inline tools

Picture Manager is currently an architectural outlier and may intentionally hard-navigate when used as its own page. Do not force all non-panel workflows to remain in place merely for uniformity.

Instead:

- reuse the shared affected-context semantics where it reduces duplication;
- prohibit hard navigation only when the action is actually side-panel-owned;
- document explicit standalone-navigation exceptions;
- audit public inline admin delete/edit actions for the same JSON/redirect mismatch class.

## Stage 3 exit criteria

- Every persistent mutation rendered inside the side panel has an enhanced JSON completion path.
- No side-panel-owned auxiliary tool uses full-page reload as its recovery mechanism.
- Tool-specific HTML refresh and public gallery refresh are clearly separate responsibilities.
- Direct-page fallback routes remain functional.
- Dynamic re-rendering does not require per-fragment event rebinding.

### Stage 3 Part 1 implementation progress

- Embedded scan/import is panel-owned and has a canonical JSON completion path while retaining its direct-page redirect fallback.
- Force AI metadata regeneration returns a canonical queued-work result, keeps the API editor open, and deliberately declares no immediate public refresh context.
- Gallery-level Media Renamer, Metadata Organizer, Duplicate Photo Detector deletion, and image reordering now hand public invalidation to the shared completion coordinator. Their panel-specific result markup remains owned by each tool.
- Auxiliary synchronization failures remain visible in the mounted tool instead of invoking `window.location.reload()`.
- Image and tag editor saves retain the drawer, and tag slug refresh no longer rewrites the visible browser URL.
- Part 1 handoff originally left Smart Gallery normalization, image/tag affected-context completion, public inline auditing, and the focused regression matrix for later parts; Part 2 below records which of those items are now implemented.

### Stage 3 Part 2 implementation progress

- Every persistent Smart Gallery action rendered by the side-panel editor now has a canonical JSON success/error path: create, update, placement update, detach, duplicate, and delete. Preview remains intentionally non-persistent HTML. Direct-page POST/redirect behavior is preserved.
- Smart Gallery responses identify the root and/or physical gallery contexts whose rendered Smart Gallery cards can change. Placement mode transitions include both the previous and new context sets so moving a Smart Gallery between root/gallery/unlisted modes does not leave the currently visible parent stale.
- Image edit now returns the canonical mutation envelope, keeps the image editor refresh URL separate from the owning public gallery render context, and declares an `image_visibility` postcondition while retaining temporary legacy top-level fields for migration compatibility.
- Tag edit now returns a typed public `tag` context keyed by stable `tag_id`. A slug rename fetches the new canonical tag render URL while the visible browser URL remains unchanged, with `tag_identity` verification in the shared coordinator.
- Public photo-card deletion now uses an enhanced JSON path and the canonical coordinator with an `image_absent` postcondition. Existing gallery-card deletion remains canonical. Hero deletion of the gallery/tag currently being viewed intentionally retains normal navigation because the deleted page has no valid same-context postcondition.
- Picture Manager was audited. Its move/copy/create actions are public-page toolbar operations, not side-panel-owned mutations; its existing post-success hard navigation remains an explicit standalone-navigation exception under the Stage 3 rules.
- The Smart Gallery panel submission remains delegation-safe after server re-rendering. Destructive Smart Gallery confirmation is preserved explicitly because capture-phase interception bypasses the form's inline `onsubmit` fallback.
- Browser cache-busting was advanced through `admin-mutation-completion.js` -> `admin-side-panel.js` -> `admin-operations.js` -> `gallery.js` so deployed clients cannot retain the older coordinator contract.
- PHP syntax validation and JavaScript syntax validation pass for the Part 2 implementation. Focused executable tag-context smoke checks also pass.
- The authoritative deployment ZIP used for Part 2 does not contain the repository `tests/` directory. Stage 3 regression-test additions and reconciliation of older Stage 2 source-pattern tests therefore remain for the next source context that includes those tests; they are not reconstructed from stale descriptions in this deployment package.

### Stage 3 Part 3 implementation progress

- The supplied authoritative deployment tree still does not contain the repository `tests/` directory. The Stage 3 regression additions, Stage 2 source-pattern reconciliation, and complete mutation regression-suite run therefore remain blocked until a source context containing the actual test tree is supplied. Do not reconstruct those missing tests from older deployments or stale descriptions.
- A fresh side-panel lifecycle audit found one real remaining Stage 3 gap in Force AI metadata regeneration. The server route already returned a canonical queued-work envelope, but the standalone form rendered in the gallery editor API tab was not intercepted by the side-panel delegation layer and had no fragment-stable action URL. From an injected drawer it could therefore fall through to classic POST/redirect behavior instead of the enhanced completion path.
- Force AI metadata regeneration is now explicitly marked as a panel-owned auxiliary mutation, uses an explicit Admin edit action URL, preserves the existing non-JavaScript inline confirmation, and has an equivalent delegated JavaScript confirmation before the capture-phase handler suppresses the inline fallback. The named submit control is forwarded into `FormData`, so `action=force_ai_reprocess` reaches the existing controller JSON branch.
- Successful Force AI requests now run through the shared mutation completion coordinator, refresh the API editor fragment from the server-provided panel metadata, keep the drawer mounted, leave the visible browser URL unchanged, and retain the intentional distinction between the completed reset/queue mutation and future Windows-worker processing. The response still declares no immediate public context because the action only queues/regenerates internal AI metadata.
- The side-panel module cache-busting chain was advanced through `admin-operations.js` and `gallery.js` so deployed browsers cannot retain the pre-fix handler.
- The deployment-wide available validation passes after this fix: `php -l` across all 329 PHP files, `node --check` across all 62 public JavaScript files, normalized `git diff --check`, focused Force-AI source-contract assertions, and regeneration plus `--check` of the 439-file core integrity manifest. Stage 3 remains **In progress**, not completed, because the executable mutation regression sources required by the Stage 3 exit criteria are still absent from this deployment package.

### Stage 3 Part 4 implementation progress

- The supplied authoritative deployment tree still has no repository `tests/` directory, so Part 4 does not fabricate Stage 3 regression sources from older archives or prose. Stage 3 remains **In progress** until the authoritative test tree is available and the executable exit-criteria matrix can be completed.
- A broader source-level audit found three additional embedded mutation pipelines that were still outside the canonical side-panel completion contract: gallery-scoped Upload Automation API-key create/revoke, Gallery Migration `target_pull`, and the gallery editor's browser-batched **Create gallery thumbnails** action.
- Upload Automation token create/revoke now returns canonical success/error envelopes for enhanced requests, keeps direct-page redirect fallback intact, and declares `panel.workflow=gallery-edit` with no public contexts. The side-panel handler delegates successful completion through the shared coordinator and refreshes the API-key fragment from the server-provided panel URL instead of maintaining a bespoke success pipeline.
- Gallery Migration Admin requests now keep authentication, CSRF, not-found, expected step failure, and success responses JSON-only. Local `target_pull` is treated as persistent from manifest acceptance because target metadata is applied before asset transfer completes. Pull-manifest and pull-complete results carry the stable local gallery plus owning parent/root public contexts, use the authoritative post-import render URL for slug changes, and preserve the visible browser URL.
- `admin-gallery-migration.js` owns migration progress/log markup while transfer is active. Inside the side panel it forwards canonical local pull invalidation to `php-gallery:auxiliary-mutation-success` and explicitly requests a server-rendered gallery-editor refresh after final or partially applied target pulls. This keeps the active API tab while replacing stale Identity/Access/Display/Media form values that could otherwise overwrite imported metadata on a later Save. If a pull fails after manifest acceptance, the last successful local mutation result is synchronized and the persistent drawer status reports the transfer error. Source-push completion is typed but declares no local public context.
- Gallery-scoped thumbnail generation now keeps its existing bounded browser batch loop, but its final explicit-gallery batch includes a canonical `thumbnail.rebuild`/`thumbnail.refresh_metadata` result plus the edited gallery and its owning parent/root public contexts. The thumbnail progress module forwards that result to the same auxiliary completion bridge only when running inside the side panel, so progress UI remains tool-owned. JSON batch authentication/CSRF/failure paths no longer fall through to HTML redirect/plain-text responses.
- Cache-busting was advanced for the changed migration, side-panel, thumbnail-progress, Admin operations, and main gallery modules so deployed browsers cannot retain the pre-Part-4 event/completion wiring. Permanent architecture and code-map documentation was updated for these integration boundaries; `PATCH_NOTES.md` remains untouched.

### Stage 3 Part 5 implementation progress

- The authoritative deployment tree still does not contain the repository `tests/` directory. Part 5 therefore remains a bounded source-level hardening pass and does not reconstruct missing regression files from older deployments or documentation.
- A fresh persistent-mutation audit found that Duplicate Photo Detector review-ledger actions (`ignore_pair`, `ignore_gallery`, and `clear_ledger`) were still using detector-specific success JSON even though they persist administrator-owned database state. Photo deletion in the same tool was already canonical.
- Ledger mutations now return the canonical Admin mutation envelope with typed `duplicate_photo_ledger.*` descriptors, `panel.workflow=duplicate-photo-detector`, an explicit direct-page fallback URL, and no public contexts because review-ledger state does not change gallery/image presentation. The detector continues to own and replace its result fragment, then forwards only durable canonical mutations to the shared completion coordinator. Temporary scan-job progress remains intentionally outside the persistent mutation contract.
- Duplicate Photo Detector AJAX POST authentication and CSRF validation now run before classic HTML redirect/plain-text helpers. An expired/non-admin session returns a canonical JSON auth error and an invalid CSRF token returns a canonical JSON security error; direct-page/non-JavaScript POST behavior remains unchanged.
- Expected detector AJAX failures are normalized to the canonical error-envelope shape while preserving the existing `error`/`message` compatibility fields consumed by the detector module.
- The Duplicate Photo Detector browser-module import cache-buster was advanced so deployed browsers cannot retain the pre-Part-5 ledger completion wiring. Permanent architecture, code-map, and manual testing documentation was updated; `PATCH_NOTES.md` remains untouched.

### Stage 3 Part 6 implementation progress

- The authoritative deployment tree still does not contain the repository `tests/` directory. Part 6 therefore continues as a bounded source-level hardening pass and does not reconstruct missing executable regression sources from prose or older deployments.
- A fresh gallery-editor mutation audit found one remaining persistent embedded workflow outside the canonical completion contract: the per-gallery EXIF date suggestion **Apply to this gallery** action. Its JavaScript used AJAX and updated the editor locally, but the server returned an ad-hoc `{ok, message, ...}` result and no affected public contexts, so a successful side-panel save could leave the visible gallery hero or parent gallery card stale.
- The focused `admin_gallery_date_suggestion` endpoint now returns the canonical `gallery.date_range_update` success/error envelope for enhanced requests. Successful results keep `panel.workflow=gallery-edit`, preserve the direct-page fallback URL, and declare both the edited gallery and its owning parent/root as affected public contexts. The persisted gallery `updated_at` timestamp is used as the observable postcondition for both contexts, matching the existing server-rendered hero/card identity metadata.
- `admin-gallery-date-suggestion.js` continues to own only its date inputs and compact suggestion fragment. When the tool runs inside the side panel it now forwards the durable canonical result through `php-gallery:auxiliary-mutation-success`, leaving public hero/card replacement and verification to the shared completion coordinator. The visible browser URL remains unchanged and the drawer stays mounted.
- Enhanced authentication and CSRF validation now run before `require_admin()` / `verify_csrf()` for this endpoint, so expired Admin sessions and invalid tokens return canonical JSON errors instead of a login redirect or plain-text CSRF abort. Direct-page and JavaScript-disabled POST behavior remains unchanged.
- The date-suggestion module import cache-buster was advanced, and permanent architecture, code-map, and manual smoke-test documentation was updated. `PATCH_NOTES.md` remains untouched.

### Stage 3 verified regression completion

The authoritative tracked `tests/` tree is present and the Stage 1-3 mutation matrix is now executable. Canonical PHP envelope, stable identity/context/postcondition behavior, bounded retry and stale-response rejection, delegated dynamic side-panel controls, unchanged enhanced-path URLs, workflow-owned fragment refreshes, Smart Gallery/image/tag/public-inline flows, Force AI regeneration, Duplicate Photo Detector ledger actions, Upload Automation token lifecycle, Gallery Migration target pull, gallery thumbnails, and EXIF date suggestions are covered by the focused PHP and JavaScript contracts. Older Stage 2 source-pattern tests were reconciled with the shared completion coordinator while retaining their behavioral assertions.

Stage 3 exit criteria are complete: persistent side-panel mutations have enhanced JSON completion paths, auxiliary tools do not use full-page reload recovery, tool fragments and public contexts remain separate, classic fallbacks remain present, dynamic controls use delegated/rebind-safe handling, and the complete focused regression suite passes. Packaging tests remain opt-in for local source-review ZIPs and production packages continue to exclude `tests/` by default.

# Stage 4: Verified synchronization, multi-context, race and cache hardening

## Goal

After all important workflows use the common pipeline, harden the coordinator so correctness does not depend on timing, cache behavior, request order, or one-context assumptions.

## Work

### Postcondition coverage

- Ensure every migrated persistent mutation declares an appropriate postcondition or explicitly declares that no immediate public postcondition exists.
- Remove workflow-specific verification helpers that duplicate coordinator behavior.
- Avoid false positive verification on paginated pages by using server-supplied placement/page hints where needed.

### Multi-context invalidation

Support explicit affected-context sets for operations such as:

- gallery reparent: source parent, destination parent, moved gallery;
- image move: source gallery and destination gallery;
- move-to-new-gallery: source gallery, destination gallery, destination parent;
- cover changes caused indirectly by image deletion/move;
- Smart Gallery placement changes affecting a physical parent gallery.

Only currently rendered contexts need immediate DOM replacement. Non-visible affected contexts should not cause navigation.

### Race protection

- Introduce a monotonically increasing client completion token or equivalent operation generation.
- Abort or ignore superseded refresh requests.
- Never allow an older refresh response to overwrite a newer successful mutation state.
- Scope refresh operations to the gallery/context identity that initiated them.
- Protect side-panel re-render from an old request replacing a newer panel state.

### Retry policy

- Keep retry bounded and centralized.
- Retry only when the mutation itself succeeded and the declared postcondition is not yet observable.
- Do not retry validation/authentication/CSRF errors.
- Do not scatter arbitrary `setTimeout()` values across workflow modules.
- Record enough diagnostic information to distinguish:
  - request failure;
  - non-JSON response;
  - wrong context response;
  - stale but structurally valid HTML;
  - postcondition not satisfied after retry budget.

### Refresh-source ownership

Add or standardize server-rendered DOM metadata so the browser can answer:

- Which gallery ID does this fragment represent?
- Which canonical render URL produced it?
- Which subgallery region is owned by this gallery?
- Which image/grid region belongs to this page/pagination state?

Do not infer these solely from breadcrumb text or pathname manipulation.

### Optimistic DOM cleanup

Review local mutation shortcuts in:

- Duplicate Photo Detector;
- bulk image actions;
- image editor;
- Media Renamer;
- other public inline admin tools.

Retain local updates only where they improve responsiveness and cannot become the authoritative state. The final server-rendered refresh/postcondition remains decisive.

## Stage 4 diagnostic support

Add lightweight debug information suitable for development/test mode, not a permanent noisy production log. Useful fields include:

- mutation type;
- operation token;
- affected gallery IDs;
- refresh URL used;
- expected postcondition;
- attempt number;
- verification result;
- stale-response rejection reason.

Do not log secrets, CSRF tokens, passwords, protected share tokens, or private media URLs unnecessarily.

## Stage 4 exit criteria

- No migrated workflow equates "HTTP/fragment replacement succeeded" with "mutation is visible" without verification where verification is meaningful.
- Out-of-order refresh responses cannot regress the DOM to an older state.
- Multi-context mutations have deterministic source/destination behavior.
- Retry behavior is shared, bounded, and tested.
- Cache/stale-read simulations pass.

### Stage 4 implementation progress

- The shared completion coordinator now assigns each successful mutation a monotonically increasing operation generation before panel/public synchronization starts. Context ownership is claimed immediately, superseded same-context fetches are aborted, stale retry wakeups are ignored, and panel refreshes use the same generation guard so an older response cannot replace newer drawer state.
- Successful refresh HTML is verified against the declared postcondition before any public DOM replacement. Structurally valid but stale HTML is classified separately and consumes only the coordinator's bounded retry budget. A second live-DOM verification remains after replacement as a defensive ownership/integration check.
- Public gallery, root-index, tag, subgallery-grid, image-list, and Smart Gallery markup now exposes stable ownership/state metadata: gallery/context identity, canonical refresh source, full counts, aggregate revisions, pagination state, image timestamps/order identity, and Smart Gallery placement/order. Refresh-source selection no longer depends only on pathname inference.
- Physical gallery create/delete/reparent/move flows use a shared pagination-safe `gallery_membership` invariant with an authoritative server-side full-context count. Source, destination, moved gallery, and destination-parent contexts remain explicit where each operation changes them.
- Image reorder verifies the persisted ordered ID sequence. Visibility/NSFW edits use a gallery-wide image revision when the changed image is off the current page. Media Renamer uses the same aggregate revision principle, while Metadata Organizer verifies source/destination image counts and carries moved IDs through its aggregation layer.
- Smart Gallery create/update/delete/placement/detach flows now verify authoritative presence plus full count and, for physical placement, top/bottom placement and placement order. Rendered Smart Gallery cards expose the matching placement metadata.
- Every migrated public-context mutation now carries a meaningful observable postcondition except thumbnail cache repair, which explicitly serializes `postcondition: null` because rebuilding generated thumbnail artifacts has no stable DB-backed public-HTML invariant. Explicit-null refreshes are reported as refreshed but unverified rather than treated as verified.
- Development/test diagnostics distinguish request/HTTP/parse/abort failures, wrong-context responses, stale structurally valid HTML, superseded operations, unverified refreshes, and exhausted postconditions. Diagnostic paths strip query strings so CSRF/share/private query tokens are not recorded.
- Stage 4 runtime regressions cover pagination-safe membership, image ordering, off-page aggregate revisions, Smart Gallery placement/order, stale-cache pre-replacement rejection, bounded retry, sleeping retry supersession, active fetch abort, stale panel suppression, wrong-context retry, explicit unverified contexts, and diagnostic URL redaction. PHP source-boundary contracts protect server metadata and workflow wiring.
- Stage 4 exit criteria are complete. Existing Stage 1-3 focused contracts continue to pass with the new coordinator semantics and Stage 4 browser-module cache versions.

# Stage 5: Enforcement, cleanup, permanent documentation, TEMP removal

## Goal

Make the new architecture difficult to accidentally bypass in future development, then remove migration scaffolding and this temporary file.

## Work

### Static/contract regression guards

Add tests or repository checks that detect common regressions such as:

- a side-panel-owned form that lacks an AJAX interception marker/contract;
- a side-panel controller action that accepts AJAX but redirects after successful mutation;
- `window.location.reload()` inside the side-panel enhanced mutation path;
- assignment to `window.location` from a side-panel success handler;
- `history.replaceState()` used to hide a canonical URL mismatch after side-panel mutation;
- a mutation response that drops affected IDs through an aggregation layer;
- a new workflow-specific retry loop instead of the shared coordinator;
- direct binding that fails after panel `innerHTML` replacement.

These guards should be focused enough to avoid banning legitimate standalone navigation elsewhere in the application.

### Remove migration compatibility code

Once all workflows are migrated:

- remove legacy response adapters that are no longer needed;
- remove duplicate refresh helpers;
- remove create-specific or tool-specific postcondition code superseded by the coordinator;
- remove hard reload fallbacks from side-panel-owned paths;
- simplify `admin-side-panel.js` back toward drawer lifecycle/interception responsibilities;
- retain direct-page redirects only in server-side non-AJAX branches.

### Permanent documentation

Update relevant permanent documentation based on the final implementation:

- `AGENTS.md`: concise rules for adding a new persistent side-panel mutation;
- `ARCHITECTURE.md`: canonical mutation result and completion pipeline;
- `CODEMAP.md`: location/responsibility of the new coordinator/helpers;
- `TESTING.md`: mandatory mutation pipeline tests and manual browser matrix;
- user/admin manual only if user-visible behavior needs documentation.

Do not update `PATCH_NOTES.md` as part of this hardening unless separately requested.

### Final validation

Run at minimum:

- all available project tests;
- every focused mutation pipeline regression test;
- `php -l` on every changed PHP file;
- `node --check` on every changed JavaScript file;
- manifest regeneration/check when updater-managed files changed;
- `git diff --check`;
- affected-files ZIP integrity check;
- manual browser matrix for the highest-risk mutations.

Recommended manual browser matrix:

1. Add child gallery.
2. Edit title only.
3. Rename folder/slug.
4. Reparent gallery.
5. Delete gallery.
6. Upload to existing gallery.
7. Upload while creating gallery.
8. Delete image.
9. Move image existing -> existing.
10. Move image existing -> new child gallery.
11. Change visibility.
12. Change NSFW state.
13. Change cover.
14. Scan/import.
15. Media Renamer apply.
16. Metadata Organizer apply.
17. Duplicate Detector delete.
18. Image reorder.
19. Image edit.
20. Tag slug edit.
21. Smart Gallery placement mutation.

For each applicable case verify:

- server mutation succeeded;
- panel remains open;
- browser URL remains byte-for-byte unchanged;
- no full-page navigation/reload occurred;
- current public context reflects the saved state;
- dynamically re-rendered controls still work on the next mutation;
- back/forward navigation remains sane after closing the panel;
- direct-page/non-JavaScript fallback still behaves correctly.

### Stage 5 implementation progress

- `admin-mutation-completion.js` now accepts only the canonical successful mutation envelope. The Stage 1 browser compatibility adapter that inferred mutation/context semantics from workflow-specific top-level fields has been removed. Missing canonical metadata is a contract error instead of silently reconstructed browser state.
- Classic one-file upload aggregation now preserves `mutation`, `panel`, `contexts`, `fallback`, stable `refresh_gallery_id`, and all uploaded `image_ids` across requests. Existing-gallery completion reports the aggregated image IDs, while create-with-upload preserves the initial gallery-creation/membership envelope. Empty side-panel gallery creation also passes through the server-authored canonical envelope instead of rebuilding it.
- Metadata Organizer batching now preserves and requires the canonical envelope from successful apply batches. The legacy refresh-URL reconstruction fallback has been removed.
- The obsolete duplicate `refreshCurrentGalleryContextFromServer()` / full-editor replacement path has been removed from `admin-side-panel.js`; public synchronization remains owned by the shared coordinator and panel lifecycle remains owned by the side-panel module.
- Server comments now distinguish canonical completion metadata from intentionally retained workflow-specific result fields. Those workflow fields remain available for progress/editor UI but are no longer completion adapters.
- `scripts/check_admin_mutation_contracts.php` adds a deployment-tree repository guard for strict envelope consumption, stable ID survival through upload aggregation, Metadata Organizer envelope preservation, dynamic side-panel interception, centralized retry ownership, bulk visibility/NSFW AJAX return boundaries, and navigation/reload/history invariants.
- Permanent architecture rules have been moved into `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, and `TESTING.md`. `PATCH_NOTES.md` remains untouched.
- JavaScript dependency cache revisions were advanced through `admin-operations.js` and `gallery.js` so browsers cannot retain pre-Stage-5 panel handlers.
- The final TEMP deletion is intentionally **not** performed in this stage delivery because the repository owner requested that this roadmap remain until explicit pre-release cleanup. This is a release-deferred closure item, not missing migration work.
- Stage 5 repository validation completed on the deployment tree: the 60-check mutation contract guard passed; every changed PHP file passed `php -l`; every changed JavaScript file passed `node --check`; the 440-file updater manifest regenerated and passed `--check`; and `git diff --no-index --check` reported no whitespace errors. The deployment artifact intentionally contains no tracked `tests/` tree, so `php tests/run.php` cannot be executed from this ZIP. The full authenticated manual browser matrix remains a pre-release qualification step rather than something this deployment-only sandbox can claim to have run.

### TEMP file removal

When all Stage 5 implementation and release-qualification criteria are complete, and the repository owner explicitly starts pre-release cleanup:

1. Move any still-useful architectural material from this file into permanent documentation.
2. Delete `TEMP_MUTATION_PIPELINE_HARDENING_PLAN.md`.
3. Ensure the final affected-files ZIP records its deletion if the delivery process supports deletion manifests, or otherwise document the required deletion explicitly.

## Stage 5 exit criteria

- The application has one canonical side-panel mutation completion architecture.
- All known persistent panel workflows use it.
- Parallel reload/redirect success pipelines are gone from enhanced side-panel flows.
- Static tests make common regressions visible during future development.
- Permanent repository documentation describes the final architecture.
- This TEMP roadmap file has been removed during the explicit pre-release cleanup. Until then, this single exit item is intentionally release-deferred by repository-owner instruction.

# Stage execution rules

For each implementation stage:

1. Start from the newest authoritative deployment/repository state supplied for that stage.
2. Re-read `AGENTS.md` because repository rules may evolve during this work.
3. Reconcile this TEMP plan with changes already implemented in earlier stages.
4. Keep each stage deployable on its own. Do not leave a stage requiring uncommitted files from the next stage to function.
5. Prefer adapters during migration over broad simultaneous rewrites, then remove adapters in Stage 5.
6. Do not change persistence semantics merely to satisfy the UI contract unless a real persistence defect is proven.
7. Do not add Composer, npm dependencies, a framework, workers, external services, or new PHP extension requirements.
8. Preserve PHP 8.1+ compatibility.
9. Preserve all docstrings and meaningful code comments; add comments where the lifecycle/ownership reasoning would otherwise be non-obvious.
10. Update JavaScript cache-busting imports for every changed browser module according to current repository conventions.
11. Regenerate/check `app/core-manifest.json` whenever updater-managed files change.
12. Do not edit `PATCH_NOTES.md` unless explicitly requested.
13. Return an affected-files-only ZIP for each stage, preserving repository-relative paths.
14. Include this TEMP file in each intermediate stage ZIP when its status/checklists are updated.

# Recommended implementation order inside individual stages

Within a stage, use this sequence to minimize partial-state errors:

1. Add/extend regression tests for the intended contract.
2. Add server result metadata without removing legacy fields yet.
3. Add/extend coordinator support.
4. Migrate one mutation family at a time.
5. Run focused tests after each family.
6. Remove only the legacy branch made unreachable by that same stage.
7. Run complete validation and manifest checks.
8. Update this TEMP roadmap's status and notes.

# Definition of "fundamental fix"

A change is not considered complete merely because the original reproduction scenario works.

For this roadmap, a fundamental fix means:

- the mutation has a documented response contract;
- all AJAX success/error branches honor that contract;
- response metadata survives batching/aggregation;
- affected contexts are explicit;
- server-rendered content remains authoritative;
- successful synchronization is verified against the mutation's expected state where meaningful;
- stale/out-of-order refreshes cannot overwrite newer state;
- side-panel lifecycle invariants are preserved;
- direct-page fallback remains independent and functional;
- regression tests cover the contract rather than one hard-coded UI symptom.
