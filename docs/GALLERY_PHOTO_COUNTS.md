# Gallery and subgallery photo count badges

## Scope

Physical gallery cards and the opened-gallery hero share a contained-photo count for the gallery itself and every descendant physical subgallery. The count is based on registered live image rows; it does not count PDF attachments, unregistered upload batches, gallery cover-only assets or trashed/deleted content. Nested images are aggregated once per gallery branch.

The two counts have distinct security meanings:

- **Public**: the historical, access-aware public count. A regular visitor sees only pictures covered by the current gallery/media visibility, listing, password/share-link and NSFW policies. It is not equivalent to ignoring gallery restrictions and merely counting all `images.visibility = 'public'` rows.
- **All**: a privileged Admin-only total of every registered live photo in the branch, regardless of gallery/image publication, privacy or NSFW status.

An Admin session is the historic `current_user()` identity domain. Viewer Accounts are not administrators. Neither ordinary visitors nor Viewers receive All counts or the difference between the two in HTML, data attributes, JavaScript state, public endpoints, search metadata or shared caches.

## Display behavior

The global settings are located in **Admin → Theme → Layout → Cards & badges → Contained-picture badge**. The first two controls are deliberately compact:

1. **Display**: `Public only`, `All only`, `Both` or `Hidden`.
2. **Show icons**: ON/OFF, completely independent of Display mode. It remains saved in Hidden mode.

In Admin views, Public is preceded by an eye icon and All by a stacked-images icon when icons are enabled. For example, Public = 12 and All = 18 renders **eye 12 / images 18**. With icons disabled it renders **12 / 18**. When the two values are identical under Both, the Admin sees only **images 18** (or **18** without icons).

| Display mode | Admin | Ordinary visitor or Viewer Account |
| --- | --- | --- |
| Public only | Public | Public |
| All only | All | Public |
| Both | Public / All, collapsed to All when equal | Public |
| Hidden | No badge | No badge |

A public count displayed for an ordinary visitor retains the existing single-badge appearance. The Admin icon switch does not change the visitor's badge icon/style.

### Per-gallery overrides

**Edit gallery → Display** provides `Inherit from Theme`, `Public only`, `All only`, `Both`, and `Hidden`. Each gallery can override the global mode without overriding the global Show icons switch.

Historical SQL enum values are kept for compatibility: `show` is interpreted as `both`; `hide` is `hidden`; `NULL` inherits the Theme setting. A new migration extends `galleries.count_badge_visibility` with `public`, `all` and `both`. Existing sidecars, imports and gallery edit actions continue using the same canonical override key.

### Upgrade defaults

At **first deployment of this feature**, the global mode defaults to **Both** and **Show icons = ON**, including when the old global enabled checkbox was turned off. This is an intentional product change. Explicit per-gallery Hide overrides still suppress their badges. Later upgrades preserve explicitly stored new mode and icon selections. No migration should keep resetting these settings.

## Query and privacy architecture

`gallery_branch_image_counts()` batches descendant traversal plus direct image aggregates. The public badge uses public-only counting with the Admin authorization bypass disabled, while an Admin All/Both badge also requests an all-visibility branch aggregate. Non-admin and Hidden requests do not compute privileged all counts; page view models omit them.

Admin HTML containing total photo counts must never be shared with an ordinary visitor through page/fragment caching. Photo publishing, visibility changes, hierarchy moves, upload and deletion should invalidate normal affected page/branch content revisions.

For regressions, cover `0/0`, `0/N`, `N/N`, nested subgalleries and inaccessible ancestors, together with all eight mode/icon combinations, Theme changes, per-gallery Show/Hide inheritance, visitor and Viewer sessions, and the Setup Wizard.
