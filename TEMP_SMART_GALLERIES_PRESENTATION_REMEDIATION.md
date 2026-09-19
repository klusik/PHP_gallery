# TEMP - Smart Galleries Presentation Setup Remediation Plan

**Status:** Analysis and implementation plan only. No production source code is changed by this document.  
**Codebase baseline:** `php-gallery-deploy(20260919-142829).zip`  
**Observed application version:** `0.101.2`  
**Scope:** Smart Gallery create/edit presentation setup, persistence, public rendering semantics, Admin preview, and regression coverage.  
**Primary goal:** Make Smart Gallery presentation settings use the same Admin interaction patterns as physical galleries and make every persisted setting either have a deterministic effect or clearly expose the condition under which it is intentionally inactive.

---

## 1. Constraints and architectural rules

1. Preserve the current MVC ownership:
   - Model owns SQL and persistence.
   - Service owns normalization, schema policy, presentation semantics, and reusable domain logic.
   - Controller owns POST parsing and view-model preparation.
   - View owns markup only.
2. Reuse the existing physical-gallery controls instead of cloning their behavior into a second Smart-Gallery-only implementation where possible.
3. Preserve existing docstrings/comments when modifying source.
4. Preserve first-party source/test headers and `Author: Rudolf Klusal` conventions.
5. Do not modify the historical migration `database/migrations/202608170001_smart_gallery_presentation.php` merely to fix runtime behavior. The required `presentation_json` column already has a migration.
6. Do not change Smart Gallery rule semantics, authorization, source-gallery access policy, public media authorization, WinApp APIs, or unrelated gallery behavior.
7. Smart Gallery presentation must remain subordinate to global capability masters such as Lightbox, Downloads, and Image Voting, but editing while a master is disabled must not destroy the stored local preference.
8. Physical source-gallery/image thumbnail bounds remain authoritative. A Smart Gallery may narrow those bounds, never widen them.

---

# 2. Audit summary

The current implementation has two separate classes of problems:

1. **Confirmed persistence/state defects** that can make Presentation settings appear saved while they are not persisted, or can overwrite valid stored preferences.
2. **Admin UI/semantics drift** where Smart Galleries use custom controls and ambiguous behavior instead of the canonical physical-gallery controls.

The user's observed symptom that sorting changes take effect while grid/presentation changes may not is consistent with a concrete source-level defect described in **SG-P0-01** below. Sorting is stored in ordinary `smart_galleries` columns, while the Presentation block is stored in `presentation_json`.

The existing focused tests currently pass:

```text
Smart Gallery presentation tests passed.
Smart Gallery public contract tests passed.
Smart Gallery rule tests passed.
Smart Gallery high-priority hardening tests passed.
Smart Gallery medium-priority hardening tests passed.
```

Those tests do not currently cover the failing end-to-end contract strongly enough. In particular, they do not prove that an Admin POST round-trips Presentation state through schema readiness, model persistence, editor reload, and public page behavior.

---

# 3. Confirmed issues

## SG-P0-01 - Presentation persistence can be silently skipped while the rest of the Smart Gallery is saved

**Severity:** P0, confirmed defect  
**Likely relationship to reported behavior:** High

### Evidence

`app/services/smart_galleries.php` has two different schema concepts:

- `smart_gallery_schema_status()` / `smart_gallery_assert_mutation_ready()`
- `smart_gallery_presentation_schema_ready()`

The main mutation schema currently requires the core Smart Gallery columns but **does not require `smart_galleries.presentation_json`**.

`smart_gallery_save()` therefore performs the normal Smart Gallery mutation guard and then calls:

```php
smart_gallery_model_save(..., smart_gallery_presentation_schema_ready());
```

`app/models/smart_galleries.php::smart_gallery_model_save()` conditionally chooses an UPDATE/INSERT that either includes or omits `presentation_json` depending on that boolean.

Therefore a partially migrated or otherwise unverifiable database can accept the save, update fields such as:

- sort mode
- sort direction
- title
- visibility
- rules

while silently dropping the complete Presentation update.

That is precisely the wrong failure mode for one form submission. The Admin receives a successful save even though part of the submitted state was not persisted.

### Required correction

Make Presentation persistence part of the mutation contract, not an optional best-effort branch.

Preferred implementation:

1. Add `presentation_json` to the Smart Gallery mutation schema requirement.
2. Refuse the complete Smart Gallery save before any UPDATE/INSERT if the presentation schema is missing or cannot be verified.
3. Once the service guard guarantees the schema, remove the model's silent alternate write path that omits `presentation_json` for normal current-version writes.
4. Keep read-side compatibility/fallback for malformed or legacy rows, but do not keep silent partial-write compatibility.
5. Surface a clear Admin migration/schema error instead of reporting `Smart Gallery saved.`.

### Runtime verification

During implementation, verify that the target database actually has:

```text
smart_galleries.presentation_json
```

The code defect exists regardless of the current live schema state, but confirming the live column will determine whether this defect is also the direct cause of the currently observed installation behavior.

---

## SG-P0-02 - The editor is initialized from capability-gated effective state and can destroy stored local preferences

**Severity:** P0, confirmed defect

### Evidence

`smart_gallery_admin_editor_view_model()` currently calculates both:

```php
$presentationOverrides = smart_gallery_normalize_presentation(...);
$presentation = smart_gallery_effective_presentation($gallery);
```

but the controls are populated from `$presentation`, the **runtime effective** state.

`smart_gallery_effective_presentation()` intentionally applies global capability masters after local Smart Gallery preferences:

- `lightbox_modes`
- `downloads`
- `image_voting`

For example, a Smart Gallery may store:

```json
{"version":1,"download_enabled":true}
```

while the global Downloads capability is temporarily disabled. Runtime behavior correctly becomes `download_enabled = false`.

The problem is that the editor also shows that gated `false`. If the Presentation override remains enabled and the Admin saves any unrelated change, `smart_gallery_admin_input()` serializes the unchecked checkbox as `false`, and the stored local preference is overwritten.

This contradicts the service's own intended rule that local preferences remain stored and become effective again if the global master is re-enabled.

The same defect can affect:

- `lightbox_enabled`
- `slideshow_enabled`
- `download_enabled`
- `voting_enabled`

### Required correction

Separate **editor preference state** from **runtime effective state**.

Create one explicit service/controller contract for editor presentation values:

```text
Theme/site defaults
+ normalized stored Smart Gallery overrides
= editor preference state
```

Do **not** apply global capability masters to the values rendered into editable controls.

Use global capability state only to:

- annotate a control as currently suppressed by a site-wide master,
- explain why it is not effective at runtime,
- calculate public runtime behavior.

Saving an unrelated Smart Gallery edit while a global capability is disabled must preserve the previously stored local `true` preference.

---

## SG-P1-01 - Smart Gallery grid controls do not use the canonical physical-gallery slider design

**Severity:** P1, confirmed UI/design drift

### Current Smart Gallery implementation

`app/views/smart_galleries.php::view_render_smart_gallery_presentation_controls()` renders:

```html
<input type="number" name="presentation_grid_columns" ...>
<input type="number" name="presentation_grid_rows" ...>
```

with hardcoded limits `12` and `50`.

### Canonical physical-gallery implementation

`app/views/admin_gallery_edit_tabs.php` renders the grid as range controls with:

- visible live values,
- `data-gallery-grid-columns-display`,
- `data-gallery-grid-rows-display`,
- canonical max values supplied by the controller,
- consistent Admin layout classes.

### Required correction

Smart Gallery create and edit must use the same interaction pattern:

- Columns range slider.
- Rows range slider.
- Live numeric readout.
- Maximum values supplied from `CMS_PAGINATION_MAX_COLUMNS` and `CMS_PAGINATION_MAX_ROWS` through the view model, never duplicated as literal HTML limits.
- Optional `items per page` readout derived from `columns * rows`.
- The same visual grouping/classes used by the physical gallery Display tab where practical.

Do not copy the physical-gallery JavaScript with Smart-specific constants. Either generalize the existing range-display helper or bind Smart Gallery data attributes in `admin-smart-galleries.js` using the same DOM contract.

---

## SG-P1-02 - Smart Gallery thumbnail bounds duplicate UI instead of reusing the canonical dual-pin slider

**Severity:** P1, confirmed UI/design drift

### Current Smart Gallery implementation

The Smart Gallery editor renders two independent `<select>` controls:

- Minimum thumbnail size
- Maximum thumbnail size

### Canonical physical-gallery implementation

Physical galleries use:

```php
Gallery\Views\render_admin_thumbnail_bound_slider()
```

with:

- dual range handles,
- ordered min/max behavior,
- Auto sentinel,
- live min/max labels,
- a summary label,
- shared Admin-side-panel binding.

### Required correction

Reuse the shared thumbnail bound control for Smart Galleries.

A suitable form prefix can remain compatible with the existing controller field names, for example:

```text
presentation_thumbnail_min_size
presentation_thumbnail_max_size
```

The controller should consume the pair through the canonical thumbnail-bound normalization helper rather than separately parsing two arbitrary values.

The Smart Gallery control should make one important semantic difference explicit:

> Smart Gallery bounds are an additional restriction. Source-gallery and per-image bounds remain authoritative and may prevent the Smart Gallery from widening the available candidate set.

This matters because a valid Smart Gallery setting can otherwise look ineffective when the source gallery already imposes a stricter bound.

---

## SG-P1-03 - Rows are presented as an active display setting even when pagination is disabled

**Severity:** P1, confirmed semantic/UI mismatch

### Current behavior

Public Smart Gallery rendering calculates:

```php
$columns = (int) $presentation['grid_columns'];
$rows = (int) $presentation['grid_rows'];
$usePagination = !empty($presentation['pagination_enabled']) || $paginationRequiredForSafety;
```

When pagination is disabled and the result set is at or below the 200-row safety cap:

```php
$limit = max(1, min(SMART_GALLERY_QUERY_MAX_PAGE_SIZE, $total));
```

The `grid_rows` setting is therefore intentionally unused.

Columns still affect CSS grid structure. Rows only determine page size when pagination is active.

### Why this is currently misleading

The Admin UI shows Columns, Rows per page, and Pagination as peers, with no dependency explanation. An Admin can change Rows, save, reopen the public Smart Gallery, and correctly observe no visible difference if pagination remains disabled.

That looks like a broken setting even though the runtime branch is behaving as coded.

### Required correction

Keep the current safe server semantics, but make the dependency explicit:

1. Label Rows as `Rows per page` consistently.
2. Show a short hint that rows affect the result slice only when pagination is enabled. Safety-forced pagination above 200 results remains authoritative.
3. Visually mark the rows control as inactive when local pagination is off, while preserving its stored value for a later re-enable.
4. Do not use HTML `disabled` if doing so would omit the stored value from POST and accidentally clear the override.
5. Add an items-per-page preview when pagination is active.

Do not implement a hidden row cutoff without pagination. That would make matching images inaccessible and would be a semantic regression.

---

## SG-P1-04 - Existing tests do not prove Admin POST -> persistence -> editor reload -> public effect

**Severity:** P1, confirmed regression-coverage gap

### Existing coverage is useful but insufficient

`tests/smart_gallery_presentation_test.php` validates:

- normalization,
- defaults,
- malformed JSON fallback,
- grid normalization,
- thumbnail intersection,
- global capability gating.

`tests/smart_gallery_public_contract_test.php` checks several source-level contracts.

However, the current defect in SG-P0-01 can coexist with those tests because they do not establish the full mutation round trip.

### Required correction

Add regression tests covering at minimum:

1. Presentation schema missing/unknown -> complete save refused before partial persistence.
2. Presentation schema ready -> a save persists `presentation_json` and a subsequent editor reload reads the same override values.
3. Sorting and Presentation values save atomically from the same submission.
4. Grid columns -> correct `pagination-grid-columns-N` class in Smart Gallery result cards.
5. Pagination enabled with `columns = C`, `rows = R` -> page query limit is `C * R` within the safety cap.
6. Pagination disabled -> columns remain effective, rows remain stored but are not used as a page limit.
7. Global capability disabled while local preference is true -> editor still shows/stores local true, runtime remains false, re-enabling the master restores runtime true.
8. Dynamic Admin side-panel reload rebinds Smart Gallery sliders and thumbnail-bound controls.

---

## SG-P2-01 - `Card layout` is ambiguous because it affects the placed Smart Gallery card, not result photo cards

**Severity:** P2, confirmed UX ambiguity

`presentation_card_layout` is consumed when a Smart Gallery itself is rendered as a gallery card on the homepage or beneath a physical gallery.

It does not change the internal Smart Gallery result-photo card layout.

The current label `Card layout` does not communicate that scope.

### Required correction

Rename or add a hint, for example:

```text
Placed Smart Gallery card layout
Controls the Smart Gallery card when it appears on the homepage or under a physical gallery.
```

Do not change the existing runtime meaning unless a separate feature is explicitly requested for photo-result card layout.

---

## SG-P2-02 - The Smart Gallery editor does not expose enough source/effective-state information

**Severity:** P2, maintainability/diagnostic gap

Physical gallery settings already expose concepts such as current source/inheritance. Smart Gallery Presentation currently shows only the master override checkbox plus controls.

For debugging a reported "setting did not apply" problem, the Admin should be able to distinguish:

- inherited Theme/site value,
- stored Smart Gallery preference,
- runtime suppression by a global capability master,
- source-gallery thumbnail guardrail restriction,
- safety-forced pagination above the query cap.

### Required correction

Add compact, non-sensitive status/help text in the Presentation section. Do not expose raw JSON.

At minimum the view model should be able to state:

```text
Presentation source: Theme defaults / Smart Gallery override
Pagination: inherited/local, plus safety-forced at runtime when applicable
Lightbox/Downloads/Voting: local preference + global master state
Thumbnail bounds: local restriction, source restrictions remain authoritative
```

This is primarily an Admin clarity improvement. Public pages do not need diagnostic metadata.

---

## SG-P2-03 - Hardcoded Smart Gallery control limits can drift from canonical pagination constants

**Severity:** P2, confirmed maintainability defect

`app/views/smart_galleries.php` currently hardcodes:

```text
columns max = 12
rows max = 50
```

The service normalizer correctly uses:

```text
CMS_PAGINATION_MAX_COLUMNS
CMS_PAGINATION_MAX_ROWS
```

Today the values match, but the view is a second source of truth.

### Required correction

Pass the maximums through the presentation view model and render those values. No pagination limit belongs as a view literal.

---

# 4. Implementation program

## Stage 0 - Lock the reproduction and invariants

Before changing behavior:

1. Add focused tests that reproduce SG-P0-01 and SG-P0-02.
2. Confirm the current migration/schema status of `smart_galleries.presentation_json` in the target environment where the issue was observed.
3. Capture one baseline Smart Gallery with:
   - a deterministic rule set,
   - enough images to exercise pagination,
   - known sort order,
   - explicit grid columns/rows,
   - explicit thumbnail bounds.
4. Verify current expected public semantics before changing UI:
   - columns alter the grid class,
   - rows only alter page size when pagination is active,
   - global capability masters can suppress local features,
   - source thumbnail bounds remain authoritative.

**Exit criterion:** the failing partial-persistence path and capability-clobber path are reproducible in automated coverage.

---

## Stage 1 - Make Presentation writes atomic and schema-safe

### Service

Refactor Smart Gallery mutation schema policy so a current-version Smart Gallery save requires `presentation_json`.

Preferred direction:

```text
smart_gallery_schema_status()
  requires presentation_json for mutation

smart_gallery_assert_mutation_ready()
  fails before model write if missing/unknown
```

Read paths may remain backward-compatible.

### Model

Simplify `smart_gallery_model_save()` so current guarded writes always persist `presentation_json`.

Avoid this behavior:

```text
schema missing -> save everything else -> silently omit presentation
```

### Controller

Keep the existing `MutationSchemaUnavailableException` handling and return an explicit form/JSON error. No success response is allowed after a partial write.

**Exit criterion:** one save is all-or-nothing with respect to the Smart Gallery fields submitted by the editor.

---

## Stage 2 - Separate stored editor preferences from effective runtime presentation

Introduce an explicit editor-state builder in the service/controller boundary.

The editor requires two related states:

### A. Preference state

Used to populate form controls:

```text
Theme/site defaults + stored Smart Gallery overrides
```

No global capability suppression is applied here.

### B. Effective runtime state

Used by public rendering and preview where appropriate:

```text
Preference state + global capability masters + runtime safety rules
```

The view model may expose capability status separately for help text.

### Important invariant

This sequence must be safe:

1. Smart Gallery stores `download_enabled = true`.
2. Global Downloads feature is disabled.
3. Admin edits only title or sort order and saves.
4. Stored Smart Gallery preference remains `download_enabled = true`.
5. Runtime remains disabled while the global master is off.
6. Global Downloads feature is re-enabled.
7. Smart Gallery download becomes effective again without another Smart Gallery edit.

Apply the same invariant to Lightbox, Slideshow, and Voting as applicable.

**Exit criterion:** editing cannot destroy a locally stored preference merely because a higher-level capability is temporarily disabled.

---

## Stage 3 - Replace Smart Gallery custom controls with canonical Admin controls

### 3A. Grid

Replace number fields with range sliders matching the physical-gallery Display UI.

Required data:

- current form columns,
- current form rows,
- max columns,
- max rows,
- current presentation source,
- pagination state,
- items-per-page value.

Use canonical layout classes such as the existing range-grid structures where possible.

### 3B. Thumbnail quality bounds

Reuse:

```php
render_admin_thumbnail_bound_slider()
admin_thumbnail_bound_slider_state()
thumbnail_bound_pair_from_post()
```

Do not maintain a Smart-Gallery-specific min/max selector implementation after this stage.

### 3C. Other Presentation controls

Keep the existing renderer/mode selects and feature checkboxes, but group and label them consistently with physical gallery settings.

Clarify:

- card layout scope,
- rows/pagination dependency,
- source thumbnail guardrails,
- global capability suppression.

### 3D. Initialization and side-panel lifecycle

Both create and edit use the same Smart Gallery editor renderer, so the controls must initialize identically in both paths.

`admin-smart-galleries.js` must bind:

- grid slider value displays,
- pagination/rows dependency state,
- optional items-per-page preview,
- Presentation visibility/toggle behavior.

The shared dual thumbnail control is already supported by the Admin side-panel initializer. Verify it after dynamically injecting Smart Gallery editor HTML.

If the browser module changes, bump the cache-busting import query consistently in every entrypoint importing `admin-smart-galleries.js`.

**Exit criterion:** create, full-page edit, and drawer edit expose the same values and interaction behavior.

---

## Stage 4 - Make every Presentation setting's runtime scope explicit and testable

Audit each current Presentation key through the full pipeline:

| Setting | Persisted in `presentation_json` | Public effect to verify |
| --- | --- | --- |
| `grid_columns` | yes | `pagination-grid-columns-N` class and thumbnail `sizes` hint |
| `grid_rows` | yes | page size only when pagination is active |
| `pagination_enabled` | yes | public pagination except mandatory safety pagination |
| `thumbnail_min_size` | optional | narrows generated candidates, subject to source/image bounds |
| `thumbnail_max_size` | optional | narrows generated candidates, subject to source/image bounds |
| `thumbnail_rendering_mode` | yes when overridden | responsive/progressive picture rendering |
| `card_layout` | yes when overridden | placed Smart Gallery card only |
| `metadata_visible` | yes | result-photo metadata overlay |
| `lightbox_enabled` | yes | lightbox markup/config, subject to global master |
| `lightbox_browsing_mode` | yes | lightbox mode config |
| `slideshow_enabled` | yes | slideshow controls, subject to effective lightbox |
| `download_enabled` | yes | Smart Gallery download action, subject to global master |
| `voting_enabled` | yes | vote UI/data, subject to global master and source-gallery voting policy |

For every field, add either:

- a behavioral test proving its effect, or
- an explicit test proving the higher-priority policy that can suppress it.

**Exit criterion:** there is no Presentation control whose behavior is represented only by a source-string assertion.

---

## Stage 5 - Regression and browser coverage

Add or extend tests in the current suite rather than creating duplicate one-off scripts.

Recommended coverage:

### PHP/service/controller tests

- Presentation schema mutation contract.
- Presentation JSON persistence round trip.
- Atomic sort + Presentation save.
- Editor preference vs effective runtime separation.
- Grid page-size semantics.
- Thumbnail pair normalization using the shared helper.
- Capability-master preservation.

### View/source contract tests

- Smart Gallery grid controls are range sliders and use controller-supplied limits.
- Smart Gallery thumbnail bounds use the shared dual-pin view helper.
- The view no longer owns duplicated hardcoded pagination limits.

### Browser/Node tests

- Create editor slider readouts.
- Edit editor slider readouts.
- Side-panel reinjection and rebinding.
- Pagination checkbox updates the rows/items-per-page explanatory state.
- Dual thumbnail handles keep min <= max and submit canonical hidden values.

### Verification sequence

During development:

```bash
php scripts/audit.php --profile=quick
```

Before implementation handoff:

```bash
php scripts/audit.php --profile=full
```

Run focused individual tests only while diagnosing a concrete failure or developing a newly added test.

---

## Stage 6 - Documentation update after code is stable

Update `docs/SMART_GALLERIES.md` and, if architecture semantics materially change, the Smart Gallery section of `ARCHITECTURE.md`.

Document explicitly:

1. Presentation persistence is atomic with Smart Gallery saves.
2. Stored local preferences are distinct from globally suppressed effective runtime values.
3. Rows per page requires pagination, except safety-forced pagination.
4. Smart Gallery thumbnail bounds can only narrow source/image bounds.
5. Card layout means the placed Smart Gallery card.

Do not update release/version metadata merely for completing this remediation stage.

---

# 5. Expected implementation touch set

This is the likely future affected-file set. It is **not** changed by this TEMP-only checkpoint.

Core implementation:

```text
app/services/smart_galleries.php
app/models/smart_galleries.php
app/controllers/smart_galleries.php
app/views/smart_galleries.php
public/assets/gallery-modules/admin-smart-galleries.js
```

Likely shared/browser integration if module cache version changes:

```text
public/assets/gallery.js
public/assets/gallery-modules/admin-side-panel.js
```

Potential style change only if existing physical-gallery classes cannot be reused cleanly:

```text
public/assets/styles.css
```

Tests likely to extend/add:

```text
tests/smart_gallery_presentation_test.php
tests/smart_gallery_public_contract_test.php
<new focused Smart Gallery persistence/browser tests as justified>
```

Documentation after implementation:

```text
docs/SMART_GALLERIES.md
ARCHITECTURE.md
```

No new database column is currently indicated by this audit. The existing `presentation_json` migration is sufficient; the defect is that mutation policy currently treats that column as optional during a write.

---

# 6. Acceptance criteria

The remediation is complete only when all of the following are true:

1. A Smart Gallery save cannot report success after silently omitting Presentation persistence.
2. If `presentation_json` is missing or schema inspection is inconclusive, the save fails before sort/rules/title are partially updated.
3. Columns and Rows use the same slider interaction style as physical gallery grid settings.
4. Thumbnail min/max uses the canonical dual-pin thumbnail-bound slider.
5. Smart Gallery create and edit initialize the same control state.
6. Full-page and side-panel edit behave identically.
7. Changing columns produces the expected Smart Gallery grid class on desktop public rendering.
8. Rows per page changes the page slice when pagination is enabled.
9. Rows remain stored but are clearly identified as inactive when pagination is disabled.
10. Safety-forced pagination above `SMART_GALLERY_QUERY_MAX_PAGE_SIZE` remains intact.
11. Sorting continues to work and saves atomically with Presentation state.
12. Temporarily disabling a global feature does not overwrite a stored Smart Gallery local preference when the gallery is edited.
13. Re-enabling the global feature restores the stored local preference without another Smart Gallery edit.
14. Smart Gallery thumbnail bounds cannot bypass source-gallery or per-image bounds.
15. The UI clearly states that `Card layout` applies to the placed Smart Gallery card.
16. Existing Smart Gallery rule, authorization, cycle, placement, lightbox, download, and public-access behavior remains unchanged unless directly required by this plan.
17. Quick audit passes during development and the full audit passes before handoff.

---

# 7. Recommended implementation order

Implement in this order to avoid polishing a UI on top of unsafe persistence:

```text
1. SG-P0-01 schema-safe atomic persistence
2. SG-P0-02 stored preference vs effective runtime separation
3. Grid slider replacement
4. Shared thumbnail-bound slider replacement
5. Pagination/rows dependency UX
6. Scope/help text cleanup
7. Full field-by-field behavior tests
8. Browser side-panel lifecycle tests
9. Documentation
10. Full audit and affected-files ZIP
```

The first two stages are correctness work. The slider/design changes should not begin until Presentation round-trip persistence is trustworthy.
