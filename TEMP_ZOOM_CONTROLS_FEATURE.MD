# Temporary feature specification: public lightbox image zoom

Status: implemented feature record. The final runtime contract below reflects the completed zoom behavior as of 17 August 2026.

Implementation baseline recorded on 16 August 2026:

- `feature/zooming` and `origin/feature/zooming` both started at `48c6663`.
- The common ancestor with `develop` was `ad4df86` (Version 0.90).
- The only unrelated working-tree item was the untracked `.claude/` directory; it is outside this feature and must remain
  unstaged.
- The existing lightbox entry chain used the `20260811-leaflet-safari-marker-v1` deferred-module cache key.
- `lightbox.js`, `lightbox-deferred.js`, public asset loading, public thumbnail markup, and function-documentation checks
  passed before implementation.

Phase 0.2 integration contract was recorded on 16 August 2026 and is now superseded by the final implementation:

- The existing server-rendered `.lightbox-stage-link` remains the only photo stage. A single inner
  `.lightbox-zoom-surface` is the fitted photograph frame and contains the active `data-lightbox-img`; no parallel viewer
  or second media-request path was introduced.
- At 100%, the zoom surface is explicitly centered on the fitted photograph. Above 100%, the surface itself receives the
  real enlarged CSS width/height and grows around that center. A translation-only transform applies pan. The image fills
  the surface and is never compositor-scaled as the zoom mechanism.
- `openAt()` is the canonical image transition for direct opens, previous/next, picture-strip selection, 3D-carousel
  selection, lazy metadata, mobile swipe, and slideshow advance. It resets zoom state before a new photograph is shown.
- `close()` is the teardown boundary. It clears zoom pointers, pan capture, quality work, transforms, loading state, image
  source, and history state.
- Normal and fullscreen layouts reuse the same stage and zoom surface. Pure fullscreen entry/exit preserves the current
  scale and reclamps translation. The normal lightbox lets the enlarged photo frame extend beyond its original fitted
  stage; fullscreen clips at the browser viewport and preserves both horizontal and vertical pan.
- The existing mobile gesture path keeps one-finger horizontal navigation at 100%. Above 100%, one pointer pans and two
  pointers pinch. Completed pan/pinch gestures suppress the synthetic stage click.
- Zoom anchor math uses canonical fitted dimensions, current scale, and current translation. It does not use an
  in-flight animated DOM rectangle, so repeated rapid zoom stays anchored to the same fractional photograph point.
- Desktop map split, Leaflet controls, navigation, voting, strip/carousel items, help, and the fullscreen close/HUD remain
  outside photo-zoom ownership. Fullscreen controls have explicit stacking above the transformed media surface.
- Zoom state itself remains browser-only: scale `1..4`, step `0.25`, bounded translated pixels, no URL/cookie/storage/
  database/telemetry persistence. The only additional network activity is demand-driven use of the already-authorized
  active photo's full display source.

## Progressive zoom-quality follow-up

The first zoom implementation correctly enlarges the current decoded image, but an enlarged 1600-pixel preview cannot
reveal detail that was removed while generating that preview. The follow-up requirement is to upgrade the active image
source in the background when the current viewport, zoom scale, and display density need more source pixels. This section
supersedes only the earlier statement that zoom has no extra media-fetch state. Zoom remains presentation-only and adds no
URL, cookie, local-storage, database, metadata, or filesystem mutation.

### Chosen design

Use the two safe sources that the public gallery already emits for every lightbox item:

1. the generated 1600-pixel preview, served through the existing protected thumbnail route; and
2. the browser-displayable full source, served through the existing protected media route. For ordinary JPEG/PNG/WebP
   photographs this is the stored source; for DNG it is the existing full display master; and for a missing/non-displayable
   source it is the existing largest safe derivative fallback.

Do not construct media URLs in JavaScript and do not add a raw-file route. PHP must emit a structured, validated candidate
list with the safe URL and effective pixel dimensions already known to the existing thumbnail/media presentation model.
The lazy lightbox JSON endpoint must emit the same candidate model as server-rendered cards so paginated navigation has
identical behavior.

At 100%, the browser should display the 1600-pixel preview when it is sufficient. As soon as the visitor deliberately
zooms above 100%, the active photo must immediately request the full/original-quality candidate. Explicit zoom does not
wait for the pixel-demand threshold, a fullscreen transition, a resize, or any other layout change. The pixel-demand
selector remains useful only for the unzoomed 100% presentation and passive viewport/layout checks. At 100%, it computes
a target source width from:

```text
rendered image CSS width x current zoom scale x bounded device-pixel ratio
x 1.5 rendering-detail headroom
```

Device-pixel ratio must be clamped to a conservative maximum of 2. The selector chooses the smallest candidate whose
width satisfies that target. The 1.5 detail factor gives ordinary 100% desktop lightboxes enough headroom to avoid a visibly
soft preview without eagerly upgrading smaller stages. At 100%, if the preview cannot satisfy the target, the browser may decode the full candidate in the background. Above
100%, deliberate zoom bypasses that passive selection threshold completely: the same button, keyboard, wheel, trackpad,
or pinch event must synchronously assign the existing authorized `data-full-src` to the live active image and start the
request at high priority. No fullscreen event, resize, passive debounce, detached decode, or candidate threshold may be
required before that source assignment. The zoom renderer then enlarges the `.lightbox-zoom-surface` with real CSS width/height and uses only translation for
pan; the active image fills that surface and must not be compositor-scaled from the 100% preview raster. The currently visible preview may remain only
while the browser transfers the original response. Pending preview cross-fades must be invalidated before the original
request starts so a delayed transition cannot write the preview back after zoom.

An 8000x6000 image can require roughly 192 MB of decoded RGBA memory, so the full source must not be eagerly fetched for
every opened image or adjacent-image preload. It is requested only for the active photo when the display calculation calls
for it. Once upgraded, the active photo is not downgraded while it remains open; this avoids repeated network/decode work
when the visitor zooms around the threshold. Navigation and close invalidate pending quality requests with the existing
image token/generation boundary. A late result from a previous image must never replace the new image.

If the high-resolution request fails, the preview remains usable and zoom/pan continue normally. The browser must not
loop retries for every wheel event. The established decoded-image cache may retain the successful full source within its
existing bound. Reduced-data connections should use a lower effective density and must still upgrade when zoom exceeds the
preview's useful resolution; this is a quality optimization, not a permanent denial of the full source.

### Full-quality loading feedback

Because a protected full source can take several seconds to transfer and decode, the active stage must show a compact
loading pill and circular activity ring from the moment the larger candidate starts loading until it succeeds, fails, or
is cancelled. The message is translated as “Loading full-quality image...” in every maintained catalog. It uses the
existing polite zoom status for assistive announcements and marks the stage `aria-busy="true"`; it does not create a
competing live region. The indicator is pointer-transparent, does not block zoom or pan, does not hide the usable preview,
and becomes a static ring under `prefers-reduced-motion`. Navigation, close, teardown, failed decode, and successful
promotion must all remove the class, label, and busy state immediately.

### Immediate original request and deterministic repaint

The first zoom above 100% starts a high-priority request for the original display URL immediately by assigning the
protected `data-full-src` directly to the live `data-lightbox-img` in the same input task. The explicit path does not wait
for a detached-image decode, passive quality timer, fullscreen transition, resize, animation frame, or mode switch before
changing `src`. Pending preview cross-fades and stale passive quality work are invalidated first.

The fitted 100% photograph is represented by `.lightbox-zoom-surface`. Deliberate zoom changes that surface's real CSS
width/height from `fittedWidth * scale` and `fittedHeight * scale`; the active image fills the surface. Pan uses only a
translation transform on the surface, with the base `translate(-50%, -50%)` keeping its geometric center aligned with the
stage center. This avoids compositor-scaling the preview raster and avoids the fixed-frame effect where a larger image was
cropped inside its original 100% box.

Cursor/pinch anchoring is deterministic. For every zoom step, the model derives the current photo rectangle from the
canonical fitted dimensions, committed scale, and committed translation, computes the fractional photograph point under
the pointer/midpoint, and resolves the new translation so that same fraction remains under the anchor. It deliberately
does not read an in-flight animated DOM rectangle, which prevents rapid repeated zoom from drifting toward a corner.

When the original response becomes available, ordinary image resource invalidation paints it inside the already-enlarged
surface without changing scale or pan. Fullscreen switching is not needed to reveal sharper pixels. The request remains
scoped to the active image ID and navigation/quality tokens; failure restores the protected preview and leaves controls
usable.

### Implementation phases and subphases

Each subphase follows the branch/commit/push authorization above and is committed and pushed independently.

#### Phase Q1: document and model the quality decision

- Q1.1: record this architecture, security, memory, fallback, lifecycle, and verification contract in this temporary
  specification.
- Q1.2: extend `lightbox-zoom-model.js` with pure candidate normalization, required-pixel-width, and candidate-selection
  helpers. Add Node tests for 100%, high-DPI, increased zoom, malformed candidates, duplicate URLs, maximum DPR, no
  unnecessary downgrade, and full-source fallback.

#### Phase Q2: emit authorized source candidates

- Q2.1: add a centralized PHP presentation helper that derives the preview candidate's real width/height from the source
  aspect ratio and requested thumbnail bound, appends the protected full source with known dimensions, removes duplicate
  URLs, and emits only positive bounded dimensions.
- Q2.2: use the helper in `public_gallery_lightbox.php`, normal/smart gallery card construction, and
  `gallery_lightbox.php`. Preserve `data-preview-src` and `data-full-src` for compatibility while adding the JSON candidate
  attribute/payload. Add focused PHP contracts for server-rendered and lazy candidates and access-check ordering.

#### Phase Q3: quality evaluation and explicit source promotion

- Q3.1: hydrate the same candidate data for lazy cards and parse it defensively. Track the active displayed candidate and
  a per-image quality-request token without adding persistence.
- Q3.2: retain passive 100% quality evaluation for large/high-DPI stages, but route every deliberate zoom above 100%
  through the immediate live-image `data-full-src` assignment. Do not debounce or defer that explicit request.
- Q3.3: cancel pending preview transitions before deliberate promotion, preserve the centered growing zoom surface and
  current pan while the source changes, and restore the authorized preview on failure. Never preload adjacent originals.
- Q3.4: show translated, accessible, non-blocking loading feedback while a larger source is pending; clear it on every
  success, failure, cancellation, navigation, close, and teardown boundary.
#### Phase Q4: integration, documentation, and release integrity

- Q4.1: add lifecycle/static regression coverage for no eager original download, demand-driven promotion, stale-request
  rejection, failure fallback, preserved access/NSFW routes, lazy hydration, and unchanged zoom state during a swap.
- Q4.2: update `README.md`, `ARCHITECTURE.md`, `TESTING.md`, and `docs/PHP_Gallery_Manual.tex`; compile the manual PDF.
  Explain that sharpness improves only up to the available full source and that deep zoom may use substantial memory and
  bandwidth. Leave `PATCH_NOTES.md` unchanged.
- Q4.3: run changed-PHP syntax validation, all zoom tests, the complete PHP and Node suites, `git diff --check`, refresh
  `app/core-manifest.json`, verify it with `--check`, commit, push, and confirm the remote branch tip.

### Acceptance cases

- A sufficiently large ordinary desktop or high-DPI stage may promote the full source passively at 100%; a smaller stage
  may remain on the generated preview.
- The first deliberate zoom above 100%, through button, keyboard, wheel/trackpad, or pinch, starts the protected
  `data-full-src` request in that same input task. It must not wait for fullscreen, resize, an animation frame, or a later
  quality debounce.
- The 100% photograph is fitted and centered. Above 100%, the photograph frame itself grows around that center; there is
  no fixed original-size crop frame.
- Repeated off-center zoom through 400% preserves the same fractional photograph point under the pointer or pinch midpoint,
  including rapid inputs while the CSS transition is still visually in progress.
- In fullscreen, wide images retain both horizontal and vertical pan once zoom creates additional photograph area.
  Fullscreen close/HUD controls remain the top hit targets and work at every zoom level.
- After the loading signal clears, the sharper original is visible immediately in the current lightbox/fullscreen mode
  without changing scale/pan and without toggling fullscreen.
- Open, promote, navigate, and promote at least three consecutive photographs, then close and repeat from another card.
  Every photograph has an independent loading/request lifecycle; stale work from an earlier image cannot overwrite a later
  one.
- Rapid navigation before the original finishes cannot display the previous photograph's full source.
- A failed full-source request restores the protected preview and leaves zoom/pan usable without repeated retries.
- Private, shared, NSFW-restricted, Smart Gallery, direct-photo, and asynchronously paginated images use only established
  authorized thumbnail/media URLs and retain their existing access decisions.
- Zooming one photograph never preloads neighboring originals.

## Branch, commit, and push authorization

The implementing agent is explicitly authorized to use the Git branch `feature/zooming` for this feature. The agent may
stage, commit, and push the work to the configured remote as part of implementation. Do not wait for a separate commit
request after each phase.

The branch workflow is mandatory:

1. Confirm the starting branch, remote, working-tree status, and the common ancestor with the integration branch before
   editing. Preserve unrelated user changes and never stage `.env`, `config.php`, credentials, caches, uploads, logs,
   generated deploy archives, or unrelated work.
2. Create or check out `feature/zooming` before feature edits. If the branch already exists, fetch its remote state and
   continue from its current tip without rewriting published history.
3. Split implementation into the phases and subphases below. Each subphase is a separately reviewable unit and must
   produce a commit, even when the subphase changes only this brief, a test contract, or an implementation ledger.
4. At the end of every subphase, run the checks listed for that subphase, inspect `git diff` and `git diff --check`, then
   stage only that subphase's files.
5. Create a focused commit for every subphase, using an imperative message that names the completed behavior. Do not make
   one giant feature commit and do not silently combine unrelated fixes.
6. Push every successful subphase commit immediately to `origin/feature/zooming` (or the configured feature remote if
   `origin` is unavailable). Confirm the push succeeded and verify the remote branch tip matches the local commit before
   starting the next subphase.
7. Never use `git push --force`, `git reset --hard`, history rewriting, or destructive checkout commands. If the remote
   branch has advanced, fetch and reconcile safely with a normal merge or rebase only after inspecting the incoming work.
8. Keep a short implementation ledger in the agent response/log for each subphase: commit ID, pushed remote branch,
   checks run, and any intentionally deferred item. Do not put credentials, tokens, private paths, or raw command secrets
   in that ledger.
9. After the final subphase, run the complete release-quality verification, commit any final documentation/manifest
   refresh as its own focused subphase, push it, and report the final branch tip and the complete commit list.

If a commit or push is blocked by authentication, remote divergence, policy, or unavailable tooling, stop before claiming
the subphase is complete. Report the exact safe blocker and leave the local commit recoverable; do not squash or discard
completed work to hide the interruption.

## Goal

Allow a visitor to inspect details of the currently displayed photograph without leaving the existing lightbox. The
feature must work in the normal lightbox and in the CSS/browser fullscreen variants, preserve the current adjacent-photo
navigation, slideshow, map, voting, metadata, direct-photo links, protected-media checks, and no-JavaScript fallback,
and must not create a second image viewer.

Zoom is a client-side presentation state. It must never change the stored original, generated thumbnails, image metadata,
gallery order, URL, database state, or viewer language preference.

## Current architecture to preserve

Inspect these files before editing and extend their existing contracts rather than replacing the lightbox:

- `public/assets/gallery-modules/lightbox.js` owns lightbox state, navigation, fullscreen, slideshow, map split, keyboard
  shortcuts, touch gestures, lazy metadata, and dynamic card hydration.
- `public/assets/gallery-modules/lightbox-deferred.js` opens the server-rendered viewer and handles deferred hydration.
- `public/assets/gallery.js` and `public/assets/public-gallery.js` load the lightbox modules and carry cache-busting
  import versions.
- `app/controllers/public_gallery_lightbox.php` renders the shared lightbox shell, stage button, image, toolbar, help
  panel, navigation controls, fullscreen controls, map controls, and accessible labels.
- `app/controllers/public_gallery_page.php` and `app/controllers/smart_galleries.php` emit the image-card data and
  lightbox configuration consumed by the shared viewer.
- `public/assets/styles/lightbox.css` owns public lightbox/fullscreen presentation. Do not put the feature in Admin-only
  stylesheets.
- `public/assets/usage.js` and `public/assets/telemetry.js` observe lightbox interactions. Do not accidentally turn every
  wheel, pointer, or pinch event into telemetry; add a bounded semantic event only if the existing contract requires it.
- `app/helpers_page_rendering.php` controls lightbox assets and browser-module cache busting.
- Existing public media, thumbnail, gallery-access, NSFW, share, and lightbox JSON routes remain authoritative. Zoom must
  never request an unauthorized original or bypass an existing media check.

Before coding, inspect the complete lightbox markup and the state transitions in `lightbox.js`, especially:

- stage activation and `data-lightbox-stage`
- current image lookup through `data-lightbox-img`
- `is-fullscreen` and `is-mobile-fullscreen` transitions
- `is-map-split`, `is-slideshow`, loading, and navigation classes
- touch gesture handlers and pointer capture
- keyboard event routing and `isLightboxControlTarget()`
- teardown through `AbortController`
- image replacement while navigating or loading lazy metadata

## Recommended interaction model

The implemented model uses real layout dimensions for zoom and a translation-only transform for pan. Do not use the CSS
`zoom` property and do not restore `transform: scale(...)` on the preview image. The single `.lightbox-zoom-surface` is the
real photograph frame: at 100% it equals the fitted photo size and is centered in the stage; at larger scales its
width/height become `fitted size * scale`.

The authoritative state is:

```text
scale = 1..4
translateX = bounded pixels
translateY = bounded pixels
rotation = 0 (not part of this feature)
```

Discrete step:

```text
step = 0.25
```

A scale change is anchored by photograph fraction, not by a transform origin. Given the current fitted dimensions, scale,
and translation, resolve the current photo rectangle around the stage center, find the fractional point under the
pointer/pinch midpoint, then choose the next translation that leaves that fraction under the same anchor after the frame
grows. Clamp only after resolving the desired center. The calculation must use committed model state, not a
`getBoundingClientRect()` sampled while the 120 ms transition is between states.

At the maximum scale, keep pan bounded so the photograph remains recoverable and the browser page itself does not become a
scrollable zoom canvas. Normal lightbox may display the enlarged frame beyond the original fitted stage; fullscreen clips
at the viewport.

### Required controls

The existing toolbar/HUD provides:

- Zoom in (`+`)
- Zoom out (`−`)
- Reset/current percentage (`100%` at the default state)
- Current percentage up to `400%`

The normal and fullscreen controls act on the same state. Fullscreen controls remain above the media layer for pointer hit
testing.

### Mouse and trackpad

- Mouse wheel/trackpad over the photo zooms around the pointer.
- Ctrl/Command-modified wheel remains browser/page zoom and is not intercepted.
- Handled wheel input is scoped to the photo stage only; map, toolbar, voting, strip/carousel, and form controls are not
  photo-zoom targets.
- Repeated rapid zoom must preserve the same fractional photo point under the cursor without cumulative corner drift.
- At scales above 100%, pointer drag pans the enlarged surface in both axes where bounds permit.

### Pointer and touch

- At 100%, established one-finger horizontal navigation remains active.
- Above 100%, one pointer pans.
- Two pointers pinch around their midpoint and suppress one-finger navigation/fullscreen click side effects.
- Pointer capture is released on pointer end/cancel, navigation, reset, and teardown.
- `touch-action` remains scoped to the dedicated stage rather than the whole document.

### Keyboard

- `+` or `=`: zoom in
- `-` or `_`: zoom out
- `0`: reset to 100%
- `Esc`: retain existing close/fullscreen behavior
- Existing navigation/slideshow/map/help/voting shortcuts remain unchanged.

When a valid in-stage pointer position is still available, discrete/keyboard zoom may reuse it; otherwise it falls back to
the photograph center. Shortcuts do not replace text editing in editable controls.

## Rendering and state design

There is one authoritative zoom state per lightbox instance:

```js
{
    scale: 1,
    translateX: 0,
    translateY: 0
}
```

Pointer/pinch tracking, pan capture, quality request tokens, and remembered pointer position are transient companion state,
not persisted zoom settings. Scale is not stored in the URL, cookies, Admin settings, database, card metadata, or telemetry.

The server-rendered structure keeps the existing semantic stage button and adds one inner media frame:

```html
<button class="lightbox-stage-link" data-lightbox-stage data-lightbox-zoom-viewport>
  <span class="lightbox-zoom-surface" data-lightbox-zoom-surface>
    <img data-lightbox-img alt="">
  </span>
</button>
```

At 100%, JavaScript measures the contained/fitted photo dimensions and applies them to the zoom surface. CSS positions that
surface at `left: 50%`, `top: 50%` with a base `translate3d(-50%, -50%, 0)`. Above 100%, JavaScript changes the surface's
real width/height and appends bounded X/Y translation. The image fills that frame. The image itself is not independently
scaled or translated for desktop zoom.

Reset scale/translation when the displayed photograph changes, slideshow advances, the viewer closes, or the viewer is
reopened. A pure fullscreen transition preserves scale and reclamps translation to the new fitted geometry. Recalculate
fitted dimensions after image load, fullscreen/orientation changes, map-split layout changes, and relevant resize
notifications.

Pan bounds derive from the fitted 100% photograph and current scale. Fullscreen uses the fitted photograph as the reference
for both axes rather than the entire letterboxed screen, so wide images can still gain vertical pan at modest zoom. Normal
lightbox allows the zoom surface to overflow the original stage when enlarged; fullscreen clips at the viewport. Close/HUD
controls must remain outside and above this media stacking context.

Discrete zoom may animate width, height, and translation for 120 ms. The next zoom step must still use committed model
state, never the visually interpolated DOM rectangle. This is required to keep cursor anchoring deterministic during rapid
input.

## Fullscreen and mobile behavior

Support all current modes:

- ordinary modal lightbox (`.lightbox`);
- browser fullscreen (`.lightbox.is-fullscreen` and `document.fullscreenElement`);
- CSS-only mobile fullscreen (`.lightbox.is-mobile-fullscreen`);
- picture-strip and 3D-carousel modes;
- fullscreen map split.

Zoom applies only to the primary photo stage. Nearby strip/carousel items, map tiles, metadata, controls, navigation, and
voting never scale or pan with the photograph. Leaflet keeps ownership of map zoom.

Fullscreen differs only in clipping and available geometry. The zoom surface is still centered on the fitted photograph
and grows with scale, but the stage clips it to the browser viewport. Pan bounds preserve both horizontal and vertical
movement when the enlarged photograph extends beyond its fitted 100% area. The close button and HUD have explicit stacking
above the transformed media surface so they remain clickable at every zoom level.

On narrow screens, controls remain reachable, respect safe-area insets, and coexist with mobile swipe/pinch behavior. At
high browser zoom, the existing HUD may wrap or scroll, but photo interaction remains bounded. `prefers-reduced-motion`
removes the short discrete zoom transition and keeps quality-loading feedback static rather than removing it.

## Accessibility and semantics

The lightbox is an existing modal-like experience. Follow the existing behavior, but verify it against the WAI-ARIA modal
dialog pattern: focus enters the viewer, `Tab` remains within the active controls, `Escape` closes or exits the current
fullscreen layer according to existing semantics, and focus returns to the invoking card when the viewer closes. See the
[WAI-ARIA modal dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) and its
[keyboard guidance](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/examples/dialog/).

Requirements:

- Every zoom button has a translated visible or accessible name and a visible focus indicator.
- The percentage status has an accessible label such as `Zoom: 100 percent`.
- Do not communicate zoom level by color alone.
- Do not require wheel, pinch, drag, or a pointer device; buttons and keyboard shortcuts must provide complete zoom/reset
  access.
- Do not remove browser-level zoom or set a document-wide `user-scalable=no` policy.
- Do not use the image as the only way to discover that zoom exists. Add help text to the existing help panel.
- Preserve useful alt text and do not replace the image with a canvas that loses semantic image content.
- Announce failures or unsupported interaction only through a bounded status message; do not spam live regions for every
  pointermove.

## Internationalization

Add all new strings to the maintained catalogs:

- `app/lang/en.json`
- `app/lang/cs.json`
- `app/lang/de.json`
- `app/lang/sv.json`

Likely keys include zoom in, zoom out, reset zoom, current zoom, zoom help, and any reduced-motion/unsupported notice.
Use the existing server-to-browser i18n payload. Do not hardcode English UI text in PHP, JavaScript, or CSS. Keep keys,
placeholders, and catalog counts aligned.

## Security and privacy

Zoom is presentation-only. Do not add an endpoint, database column, persistent setting, telemetry payload, or original-file
fetch solely for zoom. The browser may zoom the image source already authorized and loaded by the existing lightbox.

Do not expose a larger original merely because the user zoomed. Keep the current preview/full-source selection and access
checks. If a future high-resolution source is considered, it requires a separate authorization and bandwidth review.

## Phased implementation and mandatory commit checkpoints

Every phase and every numbered subphase below must end in its own commit and push. A subphase may contain small related
edits, but it must not include unfinished behavior from a later subphase. The suggested commit subjects are examples;
adapt them to the repository's established imperative style while keeping each commit focused.

### Phase 0: branch and baseline

#### 0.1 Repository baseline

- Confirm `feature/zooming`, integration-branch ancestry, remote configuration, clean/dirty state, current tests, and
  existing lightbox cache-busting versions.
- Do not change application files in this subphase unless a baseline test fixture genuinely requires it.
- Record the baseline result in this brief or its focused test contract so the subphase has a reviewable repository
  change.
- Commit and push: `chore(zoom): establish feature branch baseline`.

#### 0.2 Feature contract

- Confirm the current markup, keyboard contract, touch-swipe contract, fullscreen variants, map split, and no-JavaScript
  fallback from source and tests.
- Add or adjust only a focused design/test contract if the repository needs one to make later work reviewable.
- If inspection finds no implementation change, add the confirmed baseline notes and file inventory to this brief rather
  than skipping the checkpoint.
- Suggested commit: `docs(zoom): record lightbox zoom implementation contract`.

### Phase 1: semantic controls and styles

#### 1.1 Server-rendered controls

- Add zoom-in, zoom-out, reset, and status controls to the existing lightbox shell in `app/controllers/public_gallery_lightbox.php`.
- Preserve existing stage semantics, labels, button order, map controls, fullscreen HUD, and no-JavaScript output.
- Add the initial English translation keys and update all maintained catalogs if the project requires catalogs to remain
  aligned at every commit.
- Add static rendering contracts where practical.
- Run PHP syntax and focused rendering/translation tests.
- Commit and push: `feat(zoom): add accessible lightbox zoom controls`.

#### 1.2 Base zoom viewport CSS

- Add the viewport, overflow, transform, cursor, focus, fullscreen, mobile safe-area, reduced-motion, and status styles to
  `public/assets/styles/lightbox.css`.
- Ensure map tiles, metadata, controls, strips, and neighboring items are not transformed with the primary image.
- Run JavaScript/PHP syntax checks and inspect a representative rendered fixture if available.
- Commit and push: `style(zoom): add bounded lightbox zoom presentation`.

### Phase 2: bounded state and button/keyboard behavior

#### 2.1 Zoom state model

- Add one authoritative per-viewer state in `public/assets/gallery-modules/lightbox.js`.
- Implement scale bounds, translation bounds, image/viewport measurement, reset, resize/fullscreen recalculation, and
  teardown cleanup.
- Reset on image replacement/navigation; preserve only behavior explicitly approved for pure fullscreen transitions.
- Add browser-independent model tests or static contracts for bounds and reset.
- Commit and push: `feat(zoom): add bounded lightbox zoom state`.

#### 2.2 Controls and keyboard shortcuts

- Wire the server-rendered buttons and status to the state model.
- Add `+`/`=`, `-`/`_`, and `0` behavior while preserving existing navigation, close, fullscreen, slideshow, map, help,
  voting, and form-control exclusions.
- Update the existing help panel with translated shortcuts and avoid noisy live-region announcements.
- Run focused JavaScript/static/accessibility tests.
- Commit and push: `feat(zoom): support keyboard and button controls`.

### Phase 3: pointer and wheel interaction

#### 3.1 Wheel zoom

- Add a narrowly scoped non-passive wheel listener to the zoom viewport.
- Implement bounded pointer-anchored scaling, page-scroll preservation outside active zoom, and modified-wheel policy.
- Test mouse wheel, high-resolution trackpad deltas, scale limits, pointer anchoring, and cleanup after close.
- Commit and push: `feat(zoom): add pointer-anchored wheel zoom`.

#### 3.2 One-pointer pan

- Add drag panning only above 100%, with pointer capture/cancel handling and clamped edges.
- Preserve one-finger swipe navigation at 100% and distinguish drag/pan from click/fullscreen activation.
- Test mouse, pen, touch, pointer cancellation, and reopening the viewer.
- Commit and push: `feat(zoom): add bounded image panning`.

#### 3.3 Two-pointer pinch

- Add active-pointer tracking, midpoint anchoring, distance-based scale changes, `pointercancel`, and narrowly scoped
  `touch-action` behavior.
- Prevent pinch from triggering one-finger swipe navigation or accidental fullscreen toggles.
- Test Android/iOS touch where available and verify browser page pinch zoom remains available outside the viewport.
- Commit and push: `feat(zoom): add touch pinch interaction`.

### Phase 4: integration with existing viewer modes

#### 4.1 Fullscreen and mobile fullscreen

- Integrate zoom state with browser fullscreen, CSS-only mobile fullscreen, safe-area controls, HUD visibility, orientation,
  and resize transitions.
- Verify fullscreen entry/exit does not leave stale transforms or hidden controls.
- Commit and push: `feat(zoom): integrate fullscreen zoom behavior`.

#### 4.2 Navigation, lazy loading, and slideshow

- Verify reset/recalculation across previous/next, lazy lightbox metadata, direct-photo opening, picture strip, 3D
  carousel, slideshow advance, and image decode replacement.
- Ensure no transform or pointer state leaks between image IDs.
- Commit and push: `fix(zoom): reset state across lightbox navigation`.

#### 4.3 Maps, voting, and protected media

- Confirm zoom events do not intercept Leaflet map controls, voting controls, toolbar buttons, or map split navigation.
- Verify no new media request bypasses existing authorization, NSFW, share, or protected-thumbnail boundaries.
- Commit and push: `test(zoom): preserve lightbox integrations and access boundaries`.

### Phase 5: cache busting, localization, and documentation

#### 5.1 Browser asset versioning

- Update the versioned import in the actual loader chain (`public/assets/gallery.js`, `public/assets/public-gallery.js`, or
  their current owner) whenever the changed lightbox module requires cache invalidation.
- Run asset-loading/static contracts and commit/push: `chore(zoom): refresh lightbox asset cache key`.

#### 5.2 Catalog completion

- Add and translate every new string in `app/lang/en.json`, `app/lang/cs.json`, `app/lang/de.json`, and `app/lang/sv.json`.
- Run translation consistency and placeholder checks.
- Commit and push: `i18n(zoom): translate lightbox zoom controls`.

#### 5.3 Documentation

- Update `README.md`, `ARCHITECTURE.md`, `TESTING.md`, `docs/PHP_Gallery_Manual.tex`, and `CODEMAP.md` if ownership or
  workflow changes. Keep this temporary brief as the implementation plan until release documentation supersedes it.
- Compile and inspect the PDF if the manual is changed.
- Commit and push: `docs(zoom): document lightbox zoom controls`.

### Phase 6: final verification and handoff

#### 6.1 Focused and complete checks

- Run every focused test named in this brief, all changed-file syntax checks, the complete `php tests/run.php`, browser
  manual matrix, and `git diff --check`.
- Fix failures in separate focused commits; do not amend or squash already pushed subphase commits.
- Commit and push each corrective fix separately.

#### 6.2 Final manifest and release handoff

- After the final source edit, run `php scripts/generate_manifest.php` and `php scripts/generate_manifest.php --check`.
- If the manifest or final documentation changes, commit and push them as a final separate subphase.
- Do not modify `PATCH_NOTES.md` in this feature branch unless a later release-preparation task explicitly requests it.
- Suggested commit: `chore(zoom): refresh release integrity manifest`.

## Suggested implementation sequence

1. Read the current lightbox controller markup, `lightbox.js`, deferred loader, CSS, fullscreen state, and touch-swipe code.
2. Write a small state/model helper in `lightbox.js` or a dedicated module only if the existing module boundaries support it.
3. Add server-rendered zoom controls and translated labels to `app/controllers/public_gallery_lightbox.php`, preserving the
   no-JavaScript stage and existing toolbar/HUD structure.
4. Add CSS for the viewport, overflow, cursor states, controls, status, mobile safe-area layout, fullscreen, map split,
   reduced motion, and high zoom. Keep ownership in `public/assets/styles/lightbox.css`.
5. Implement button/keyboard zoom first with clamped scale and reset-on-image-change.
6. Implement wheel anchoring with a non-passive, narrowly scoped listener.
7. Implement pointer drag and two-pointer pinch with pointer capture, cancellation, midpoint anchoring, and clamped
   translation. Integrate carefully with existing one-finger swipe navigation.
8. Integrate fullscreen, slideshow, picture-strip, 3D-carousel, lazy image replacement, map split, teardown, and dynamic
   navigation.
9. Update cache-busting imports in `public/assets/gallery.js`, `public/assets/public-gallery.js`, or the actual loader
   owner whenever a changed browser module is imported by a versioned URL.
10. Add translations, focused static/model tests, browser-independent event/state tests where practical, and documentation.
11. Run the complete regression suite and manually test real browsers and touch hardware before release.

## Testing requirements

### Static and unit/model contracts

- Every changed PHP file passes `php -l`.
- Every changed JavaScript module passes `node --check`.
- `php tests/function_documentation_test.php` remains green for named functions.
- `php tests/translation_catalog_consistency_test.php` confirms all maintained labels.
- Run all seven PHP `lightbox_zoom_*` contracts and `node tests/lightbox_zoom_model_test.mjs`.
- The Node model must cover bounds, two-axis pan, centered/fractional anchors, and repeated off-center zoom through 400%
  without drift.
- `git diff --check` and the release-manifest check pass.

### Browser manual matrix

Test current Chromium/Edge, Firefox, and Safari/WebKit where available, with desktop mouse/trackpad/keyboard and touch where
available.

- Confirm 100% is fitted and centered and that the photo frame itself grows when zooming.
- Put the pointer over an off-center recognizable detail and zoom repeatedly to 400%; the same detail stays under the
  pointer. Repeat rapidly during the transition and with pinch midpoint anchoring.
- In normal lightbox, confirm the enlarged frame can extend beyond the original fitted stage without sliding down/right.
- In fullscreen, pan a wide zoomed image both horizontally and vertically and confirm close/HUD controls remain clickable.
- Confirm one deliberate zoom above 100% starts the active protected full-source request immediately and becomes sharp in
  the same mode without a fullscreen toggle.
- Confirm failure restores the preview, stale requests cannot overwrite a later photo, and adjacent originals are not
  prefetched.
- Confirm reset, navigation, slideshow, close/reopen, fullscreen/map split, strip/carousel, voting, help, protected/share/
  NSFW/Smart Gallery access, no-JavaScript fallback, browser page zoom, reduced motion, and pointer cleanup remain intact.

### Regression commands

Run:

```text
php tests/run.php
php tests/lightbox_zoom_lifecycle_test.php
php tests/lightbox_zoom_integration_test.php
php tests/lightbox_zoom_translation_test.php
php tests/lightbox_zoom_quality_candidates_test.php
php tests/lightbox_zoom_quality_rendering_test.php
php tests/lightbox_zoom_quality_lifecycle_test.php
php tests/lightbox_zoom_quality_indicator_test.php
node tests/lightbox_zoom_model_test.mjs
```

Also run PHP syntax checks for every changed PHP file, JavaScript syntax checks for every changed JS file, and
`git diff --check`. Refresh and verify `app/core-manifest.json` after the final managed-file edit.

## Documentation after implementation

The final behavior must be kept synchronized in:

- `README.md` for visitor controls, centered growing-frame zoom, cursor anchoring, immediate original loading, and limits.
- `ARCHITECTURE.md` for canonical geometry, source-promotion lifecycle, fullscreen clipping/pan, and cache revision.
- `CODEMAP.md` for JavaScript model/runtime, server controls, quality-candidate services, styles, tests, and revision owner.
- `TESTING.md` for focused contracts and the browser regression matrix.
- `docs/PHP_Gallery_Manual.tex` and the rebuilt `docs/PHP_Gallery_Manual.pdf` for non-technical visitor behavior.
- `AGENTS.md` for contributor constraints that prevent fixed-frame, compositor-scale, delayed-quality, anchor-drift, and
  fullscreen hit-testing regressions.

`docs/LATEX_BUILD.md`, `DATABASE.md`, and the Admin settings inventory need no feature-specific change unless their build,
schema, or settings ownership actually changes. Zoom remains browser presentation state and introduces no database or
Admin setting.

Do not modify `PATCH_NOTES.md` or `PATCH_NOTES_TEMPLATE.md` as part of this documentation synchronization.

## Definition of done

The feature is complete when an authorized visitor can open any existing public photograph and:

- zoom from 100% to 400% with controls, keyboard, wheel/trackpad, or pinch;
- keep an off-center cursor/pinch photo point stable through repeated rapid zoom;
- see the fitted photo frame itself grow around its center rather than zooming inside a fixed 100% crop;
- pan every available photo edge, including both axes in fullscreen;
- use fullscreen close/HUD controls while zoomed;
- receive the active photo's authorized full/original display source immediately on the first deliberate zoom above 100%,
  without requiring fullscreen/resize/mode switching;
- reset, navigate, start slideshow, toggle fullscreen/map split, and close/reopen without stale zoom or quality state.

The same behavior must preserve picture-strip/3D-carousel navigation, maps, voting, access control, NSFW policy,
translations, browser page zoom, reduced motion, and the no-JavaScript fallback. Zoom remains non-persistent and does not
change stored media or metadata.

