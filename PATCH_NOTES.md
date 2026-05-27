# Patch notes

## Version 0.71

- Added Flight Simulator camera-location uploads for watched screenshots.
  - The Windows watcher can now read the current MSFS 2024 camera position through SimConnect and send latitude,
    longitude, and altitude to PHP Gallery during upload.
  - Added a checkbox to turn camera-location tagging on or off, with the feature enabled by default.
  - Added automatic SimConnect DLL discovery plus a local SimConnect.dll fallback in the winapp folder.
  - Added a system tray mode for the Windows uploader, including a tray icon and minimize-to-tray behavior.
  - Fixed the top Picture manager panel refresh bug so it no longer gets stuck open after editing gallery data from the
    right admin panel.
  - Improved gallery maps so a route and a photo GPS point can appear together on the same map.
  - The combined map now shows the gallery route line, route points, and the active photo marker at the same time, with
    the photo marker visually emphasized.
  - Updated the admin and lightbox map rendering so route-only and photo-only cases still behave as before.
  - Added the supporting server-side upload handling, tests, and manifest updates for the new GPS metadata flow.

## Version 0.70.1

- Fixed a bug where the top Picture manager panel could reopen in a stuck state after editing gallery data from the
    right admin side panel.
  - The panel now rebinds correctly after admin-side fragment refreshes, so it can be collapsed again without reloading
    the page.
  - No database changes, no new features, and no behavior changes outside this admin UI fix.

## Version 0.70

Version 0.70 expands gallery tooling around SimBrief route content, adds a dedicated picture manager workflow, and continues polishing mobile lightbox and admin usability. It also tightens uploader diagnostics, improves tag management, and cleans up backend boundaries around the newer admin features.

### Highlights

#### Added SimBrief route-backed gallery maps and descriptions

  - Added SimBrief route-backed gallery maps for flight-themed galleries.
  - Added automatic gallery description generation from SimBrief route data.
  - Added a dedicated SimBrief admin workflow for managing route content.
  - Added flight-map persistence support through a new database migration.
  - Added translation and admin UI support for the new SimBrief tooling.
  - Kept route generation and description generation integrated with the existing gallery admin model.

#### Added a dedicated picture manager workflow

  - Added a dedicated picture manager controller and service layer.
  - Added picture manager browser modules for managing gallery images more directly.
  - Added drag-and-drop and drop-action support for public and admin image handling.
  - Added a HUD overlay for the picture manager so image actions stay visible on top of the photo.
  - Added gallery picker and drag-ghost UI support for image organization tasks.
  - Consolidated picture-management behavior into a more explicit workflow instead of spreading it across unrelated admin screens.

#### Improved mobile gallery and lightbox behavior

  - Reworked the mobile gallery into a more isolated layout layer.
  - Improved mobile lightbox swipe handling and fullscreen interaction on touch devices.
  - Refined viewport handling so the mobile lightbox behaves more predictably during gestures.
  - Added a fullscreen slideshow path for lightbox viewing.
  - Improved lightbox fullscreen presentation and supporting CSS for mobile and desktop layouts.
  - Preserved the existing gallery navigation model while making touch behavior less fragile.

#### Improved admin gallery management

  - Refined admin tag management with sortable usage and usage links.
  - Improved gallery list, reordering, and bulk action interactions in the admin UI.
  - Added a safer admin side-panel refresh flow for updated gallery content.
  - Fixed the API manager panel and upload API revoke flow.
  - Cleaned up MVC boundaries and controller/service responsibilities around the newer admin pages.
  - Improved admin dashboard rendering and telemetry handling for the updated admin stack.

#### Improved upload watcher diagnostics

  - Added a watcher health indicator for the Windows uploader.
  - Added color-coded upload log output for easier scanning during batch uploads.
  - Ignored Python cache files in the uploader workflow.
  - Improved Windows companion app behavior for upload automation and API-key handling.
  - Tightened upload automation behavior to better support the newer gallery workflows.

#### Updated supporting frontend assets

  - Updated gallery JavaScript modules for admin operations, side panels, navigation data, and image reordering.
  - Updated public gallery and lightbox assets to support the new mobile, fullscreen, and picture-manager flows.
  - Updated admin and public CSS to match the newer layouts and interaction patterns.

## Version 0.69

Version 0.69 is a major large-gallery scalability, fullscreen-map stability, uploader automation, and deferred lightbox-loading release. It focuses on making very large galleries usable without blocking the browser, improving fullscreen map behavior, introducing lazy lightbox dataset generation, expanding the Windows uploader tooling, and stabilizing dynamic public refresh behavior for galleries with thousands of images.

### Highlights

#### Added deferred lazy lightbox dataset generation

  - Added deferred lightbox dataset generation for large galleries.
  - Added asynchronous background preparation of remaining lightbox items.
  - Added progressive lightbox-state hydration instead of requiring full blocking initialization.
  - Added gallery-aware lazy item expansion for paginated galleries.
  - Added safer initialization guards for race conditions during rapid opening and closing.
  - Preserved keyboard navigation, fullscreen mode, voting, EXIF overlays, pagination, and public admin controls during deferred loading.

#### Added fullscreen lightbox loading progress UI

  - Added a dedicated lightbox loading state.
  - Added a loading overlay inside the lightbox frame.
  - Added progress text such as `Preparing photo 1 of 1500`.
  - Added animated progress behavior while lazy lightbox data are generated.
  - Prevented empty fullscreen frames during initial lightbox preparation.
  - Limited the progress UI to initial lightbox loading, not normal photo switching.

#### Improved fullscreen map mode

  - Fixed fullscreen map mode for galleries where some photos have GPS EXIF data and some do not.
  - Photos without GPS now keep the map split area visible but show it as unavailable instead of reusing the previous photo map.
  - Added disabled-map messaging for photos without coordinates.
  - Blocked fullscreen map behavior when gallery EXIF or GPS map support is disabled.
  - Fixed keyboard shortcut behavior so maps cannot be activated when the gallery does not allow GPS maps.
  - Fixed horizontal image fitting in fullscreen map split mode so images fit by their longest dimension instead of being cropped or zoomed.
  - Preserved fullscreen map mode while navigating between GPS and non-GPS photos.

#### Added lazy lightbox JSON endpoint

  - Added the `gallery_lightbox_data` public route.
  - Added `app/controllers/gallery_lightbox.php`.
  - Added `app/services/lightbox_metadata.php`.
  - The endpoint returns ordered windows of image metadata for asynchronous lightbox navigation.
  - The endpoint enforces the same gallery access checks as the public gallery page.
  - The endpoint respects public-only visibility rules.
  - The endpoint avoids exposing restricted NSFW image rows to anonymous visitors.
  - The endpoint keeps visitor vote state private and disables shared caching.
  - The endpoint returns map metadata only when GPS maps are allowed for the gallery.

#### Optimized public gallery rendering for very large galleries

  - Public gallery pages now query only the currently visible photo page when pagination is enabled.
  - Full-gallery lightbox metadata is no longer rendered eagerly into hidden DOM nodes.
  - Gallery photo counts are queried separately from visible photo rows.
  - Lightbox counts are computed separately from normal grid pagination counts when restricted items must be hidden.
  - Direct image links now compute the requested image position without loading the whole gallery image list.
  - Public image cards now expose stable `data-lightbox-index` values for asynchronous lightbox order.
  - Public reorder toolbar totals now use the full image count instead of the current visible slice.
  - SEO and social-preview fallback metadata stay bounded to visible content.

#### Improved lightbox browser modules

  - Updated deferred lightbox activation to work with asynchronous dataset loading.
  - Updated full lightbox navigation to request missing metadata windows as needed.
  - Added lazy window loading around the active image.
  - Added item cache handling for fetched lightbox metadata.
  - Added loading-state rendering for the first requested image.
  - Added progress animation while initial metadata are still being prepared.
  - Added safer teardown behavior for pending lazy-load operations.
  - Preserved voting panel synchronization after asynchronous lightbox item insertion.
  - Preserved map split state when navigating through lazily loaded items.
  - Improved resilience when users click photos before the full lightbox module has finished loading.

#### Added upload API manager

  - Added the `admin_api_manager` route.
  - Added an admin-wide API manager page for upload automation keys.
  - Added an API manager entry to the admin menu.
  - Added a dedicated API tab to the gallery editor.
  - Moved gallery-scoped upload API key management out of the image-management tab.
  - API keys remain scoped to one gallery.
  - The global manager lists active upload API keys across all galleries.
  - Admins can revoke keys from either the gallery editor or the global API manager.
  - Revocation redirects now preserve the correct return context.
  - Added schema-readiness guards to avoid fatal errors on partially migrated installations.
  - Fixed the API manager query to use existing user schema fields instead of a missing `display_name` column.

#### Improved upload automation concurrency

  - Added a gallery-scoped advisory lock around upload automation storage, scanning, and thumbnail installation.
  - Parallel Windows uploader requests can still run, but server-side mutation of one target gallery is serialized.
  - Prevented duplicate image insertion races when multiple upload requests scan the same gallery folder at the same time.
  - Added a clear busy-gallery error when the target gallery is already being processed.
  - Kept the existing gallery upload pipeline as the source of truth.

#### Added client-generated thumbnail upload support

  - Upload automation can now accept thumbnails generated by the Windows companion app.
  - Added request-local client IDs to correlate original images with uploaded thumbnails.
  - Added validation for client thumbnail size, format, MIME type, dimensions, and uploaded-file integrity.
  - Accepted thumbnail formats are limited to supported gallery thumbnail formats.
  - Client thumbnails are installed only for images accepted by the existing upload pipeline.
  - Added fresh uncached image lookup after scanning so thumbnails can attach to newly imported images in the same request.
  - Added counters for installed, skipped, and failed client thumbnails.
  - Added thumbnail installation diagnostics to upload automation JSON responses and admin logs.

#### Improved Windows uploader behavior

  - Extended the Windows uploader workflow for manual bulk upload alongside watch-folder uploading.
  - Preserved existing watch-folder behavior.
  - Added support for ignoring files that already existed before watch mode starts.
  - Added client-side thumbnail generation mode.
  - Improved installer behavior for launching the `.pyw` app without a console window.
  - Improved dependency installation handling for Windows environments with multiple Python versions.
  - Added multiprocessing-style parallel worker behavior for faster thumbnail generation and uploading on many-core CPUs.
  - Improved worker communication and upload-result reporting.
  - Clarified rejection and skip reporting in the uploader output.

#### Improved admin side-panel refresh behavior

  - Updated admin side-panel JavaScript to handle refreshed gallery content more safely.
  - Preserved side-panel workflow context after upload and gallery-editor transitions.
  - Improved handling for dynamically loaded admin tabs inside panel content.
  - Reduced stale DOM state after public gallery fragments are replaced.
  - Kept responsive thumbnails, back-to-top behavior, and lightbox modules aligned after dynamic refreshes.

#### Added and updated translations

  - Added English and Czech strings for lightbox initial loading.
  - Added English and Czech strings for lightbox loading progress counts.
  - Added English and Czech strings for unavailable fullscreen map state.
  - Added admin strings for the API manager and gallery upload automation tab.
  - Added upload automation error strings for client thumbnail validation and gallery busy states.
  - Updated browser-side i18n exports for no-GPS fullscreen map messaging.

### Technical Details

#### Backend

  - Added route registration for `gallery_lightbox_data`.
  - Added route registration for `admin_api_manager`.
  - Added `app/controllers/gallery_lightbox.php`.
  - Added `app/services/lightbox_metadata.php`.
  - Loaded the new lightbox metadata service from `app/services.php`.
  - Loaded the new lightbox controller from `app/controllers.php`.
  - Added reusable lightbox metadata helpers for:
    - total photo counts
    - paged image fetching
    - gallery-local image position lookup
    - public visibility filtering
    - restricted NSFW filtering
  - Refactored public gallery image loading to avoid eager full-gallery row loading.
  - Added JSON serialization helpers for lightbox image metadata.
  - Added private no-store cache headers for lightbox JSON responses.
  - Added gallery-scoped upload automation locking.
  - Added client thumbnail validation and installation helpers.
  - Added API key manager query helpers.

#### Frontend

  - Updated `public/assets/gallery-modules/lightbox.js`.
  - Updated `public/assets/gallery-modules/lightbox-deferred.js`.
  - Updated `public/assets/gallery-modules/admin-side-panel.js`.
  - Updated `public/assets/gallery.js`.
  - Updated `public/assets/styles/lightbox.css`.
  - Added loading-state UI inside the existing lightbox frame.
  - Added animated loading progress styling.
  - Added disabled fullscreen-map styling for photos without GPS.
  - Added lightbox map availability checks.
  - Added lazy lightbox metadata fetching and caching behavior.
  - Preserved teardown support for dynamic public content replacement.

#### Upload automation

  - Added multipart handling for client-generated thumbnails.
  - Added `image_client_ids[]`, `thumbnail_client_ids[]`, `thumbnail_sizes[]`, and `thumbnail_formats[]` request handling.
  - Added strict server-side validation before any client thumbnail is written into the cache.
  - Added support for reporting client thumbnail installation results in JSON responses.
  - Added compatibility checks for the upload automation token schema.
  - Added a global admin manager for active upload API keys.

### User Impact

#### For visitors

  - Large galleries open faster.
  - The first fullscreen photo appears without waiting for the full gallery dataset.
  - Initial fullscreen loading now shows progress instead of an empty frame.
  - Fullscreen map mode behaves correctly when navigating between GPS and non-GPS photos.
  - Horizontal photos fit correctly in fullscreen map split mode.
  - Galleries with many photos should feel lighter and less likely to stall the browser.

#### For administrators

  - Large paginated galleries are cheaper to render and easier to browse.
  - Upload automation keys can be reviewed globally from the API manager.
  - Gallery-specific API keys have a dedicated gallery editor tab.
  - Parallel uploader activity is safer against duplicate scan/import races.
  - Windows uploader bulk uploads can use client-generated thumbnails for faster server-side processing.
  - Upload logs now expose client thumbnail install, skip, and failure counts.
  - The uploader workflow is better suited for many-core Windows systems.

### Notes

  - The lazy lightbox endpoint intentionally returns private, no-store JSON because vote state can be visitor-specific.
  - Full-gallery hidden lightbox source nodes are no longer emitted for paginated galleries.
  - The public gallery grid still renders only visible page content.
  - The lightbox can still navigate across the whole gallery by fetching metadata windows as needed.
  - GPS map controls are disabled when gallery EXIF map support is not available.
  - Photos without GPS no longer reuse the last valid map while fullscreen map mode is active.
  - Client-generated thumbnails are accepted only after the original image is accepted by the existing upload pipeline.
  - The upload automation gallery lock serializes server-side mutation for one gallery, not the entire site.
  - The core manifest was refreshed for the new controllers, services, scripts, styles, translations, and upload automation changes.

### Files changed

  - `app/bootstrap.php`
  - `app/controllers.php`
  - `app/controllers/admin_galleries_edit.php`
  - `app/controllers/gallery_lightbox.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/upload_automation.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/en.json`
  - `app/services.php`
  - `app/services/lightbox_metadata.php`
  - `app/services/upload_automation.php`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/lightbox.css`

## Version 0.68

Version 0.68 extends the Windows packaging and uploader workflow, keeps deployment paths aligned, and continues the release of the broader admin and gallery maintenance work.

### Highlights

#### Added Windows packaging and uploader workflow updates

  - Added the Winapp installer script path to the release packaging surface.
  - Added the Winapp uploader path so uploader changes can ship with the same release.
  - Added the Winapp uploader companion entry to keep related tooling grouped together.
  - Kept the deployment files aligned with the current branch structure.

#### Continued admin and gallery maintenance

  - Refined the admin and gallery release surface to match the current module split.
  - Kept the core versioned files aligned for the 0.68 release.
  - Preserved the existing patch notes format for the next tag.

## Version 0.67

Version 0.67 is a large maintenance, update-system, diagnostics, and admin-workflow release. It focuses on making GitHub update checks safer and cheaper, adding optional automatic stable updates, improving URL rewrite compatibility handling, expanding telemetry exports, making admin logs more usable, preserving navigation context across login, upload, and lightbox flows, and refreshing the project documentation.

### Highlights

#### Added a central GitHub API gateway

  - Added `app/services/github.php` as the single controlled access layer for GitHub REST API calls.
  - All updater GitHub Contents API requests now pass through the shared GitHub gateway.
  - Added local file-backed GitHub API cache metadata.
  - Added ETag and Last-Modified validator storage.
  - Added conditional GitHub requests so unchanged GitHub content can return `304 Not Modified`.
  - Added cached body reuse when GitHub returns `304 Not Modified`.
  - Added persisted GitHub response diagnostics for the Updates page.
  - Added support for recording:
    - HTTP status
    - ETag
    - Last-Modified
    - rate-limit resource
    - used quota
    - remaining quota
    - reset time
    - Retry-After wait windows
  - Added a wait-state model so GitHub primary rate-limit and Retry-After responses are respected by later update checks.
  - Avoided calling GitHub `/rate_limit` just to inspect quota state.
  - Kept the updater based on normal required API responses instead of adding extra quota-consuming diagnostic requests.

#### Added five-hour update-check caching and safer force checks

  - Changed the update status flow so the admin page uses a cache-aware update status with a five-hour TTL.
  - Added a Force check action that bypasses the local five-hour cache when an admin explicitly asks for a fresh GitHub check.
  - Force checks still record and respect GitHub rate-limit headers after the response.
  - Added a non-network fallback status when the installation is waiting for a GitHub retry window.
  - Added clearer handling for unknown cached update state when no remote metadata has been cached yet.
  - Improved remote version probing across allowed branches.
  - Added diagnostics when a branch is reachable but does not expose a usable version marker.
  - Prevented stale remote branch metadata from making the update page appear to offer a downgrade.
  - Added parsing of version markers from remote `PATCH_NOTES.md` headings as an additional version source.
  - Improved update-source labels so the admin can see when the detected version came from bootstrap metadata, patch notes, or branch diagnostics.

#### Added optional automatic stable updates

  - Added an admin setting for automatic stable updates.
  - Automatic updates are disabled unless explicitly enabled.
  - When enabled, safe browser requests can check for a stable update at most once every five hours.
  - Automatic checks do not run on unsafe request methods.
  - Automatic checks do not run while another automatic update check is locked.
  - Automatic checks respect beta installations and do not replace beta code automatically.
  - Added automatic update dry-run support.
  - Dry runs check metadata and update diagnostics without installing files.
  - Beta installs use dry-run behavior so update detection can be validated without replacing the beta build.
  - Added an admin dry-run button on the Updates page.
  - Added persisted automatic update diagnostics:
    - last automatic check time
    - relative check age
    - last result
    - no-update result
    - check-failed result
    - updated result
    - dry-run result
  - Added admin log events for:
    - automatic update installed
    - automatic update failed
    - automatic update dry run checked
    - automatic update dry run failed

#### Added URL rewrite settings and compatibility diagnostics

  - Added `url_rewrite_enabled()` and `set_url_rewrite_enabled()` app-setting helpers.
  - Clean URL generation remains enabled by default to preserve existing behavior.
  - Admins can now disable clean rewritten public URLs when their hosting does not support rewrite routing.
  - Added rewrite compatibility checks for typical Apache, LiteSpeed, and shared-hosting signals.
  - Added `.htaccess` marker checks so the app can detect whether rewrite rules are likely present.
  - Added runtime compatibility diagnostics with status values such as:
    - supported
    - likely supported
    - unsupported
    - disabled intentionally
    - unknown
  - Added an `admin_url_rewrite` route for saving the setting.
  - Added a URL rewrite card to the dashboard maintenance area.
  - Added a dashboard warning when URL rewrite is enabled but support is not detected.
  - Updated public gallery, image, and tag URL helpers so they fall back to `index.php` URLs when clean URL emission is disabled.
  - Kept query-string routes as the durable fallback for hosts where pretty URLs are unreliable.

#### Preserved login return targets

  - Added `current_login_return_target()` for capturing the current browser request as a same-site relative return target.
  - Added `sanitize_login_return_target()` to reject unsafe login redirects.
  - Login return targets reject:
    - absolute external URLs
    - protocol-relative URLs
    - control characters
    - login routes
    - logout routes
    - setup routes
    - password reset routes
  - The public Admin login link now includes the current page as a safe return target.
  - Successful login now redirects back to the originating page instead of always opening the admin dashboard.
  - Failed login attempts keep the sanitized return target in a hidden form field.
  - Admin account and upload redirects now preserve the intended post-login context.

#### Fixed paginated gallery return behavior from photo view

  - The lightbox now treats the current browser URL as the preferred gallery return URL when a photo is opened.
  - When a visitor opens a photo from a paginated gallery page, closing the photo now restores that exact paginated gallery URL.
  - Added URL comparison logic so direct photo URLs can still fall back to the server-rendered base gallery URL when appropriate.
  - Avoided resetting a paginated gallery back to the base gallery URL after viewing a photo.
  - Kept normal non-paginated lightbox navigation behavior intact.

#### Improved side-panel upload refresh context

  - Added `admin_upload_safe_refresh_url()` to validate the page that opened a side-panel upload workflow.
  - Side-panel upload forms now include the source page URL.
  - Existing-gallery uploads can refresh the exact page that opened the upload panel.
  - Paginated gallery views preserve their active `photo_page` or clean pagination URL after upload.
  - Upload JSON responses now return an editor URL that opens the image-management tab after upload.
  - The side panel can switch to the gallery editor after both new-gallery and existing-gallery upload flows.
  - Reworded the side-panel loading status from created-gallery-specific wording to generic gallery editor wording.

#### Added dashboard original-storage metric

  - Added an admin dashboard metric for total imported original file storage.
  - The metric sums `images.file_size` from imported source files.
  - Generated thumbnails, DNG display masters, caches, and other derivatives are excluded.
  - The value is formatted as a compact byte label in the Galleries summary card.
  - The query is profiled through the existing admin dashboard render profiler.

#### Expanded standalone telemetry HTML export

  - Expanded the telemetry export from a basic report into a much richer standalone diagnostics document.
  - Added report helper functions for bounded windows, scalar queries, table counts, and reusable table rendering.
  - Added session quality metrics:
    - sessions
    - page views
    - photo views
    - total capped duration
    - average pages per session
    - average photos per session
    - average session duration
    - bounced sessions
    - recent versus previous sessions
  - Added daily trend rows for sessions, page views, photo views, photo viewing time, client errors, and media bytes.
  - Added top gallery engagement reporting.
  - Added top route reporting.
  - Added browser, device, locale, viewport, and referrer-style distributions where telemetry data exists.
  - Added performance metrics and web-vital style summaries.
  - Added client error distributions.
  - Added recent anonymized telemetry event access-log output.
  - Added database telemetry summaries and fingerprint hot-spot tables.
  - Added recent telemetry job-run reporting.
  - Added compact bar-chart and trend-chart HTML renderers for the export.
  - Improved metric cards with optional explanatory hints.
  - Kept the telemetry report privacy-oriented and based on already collected anonymous telemetry data.

#### Redesigned admin log filters

  - Reworked the admin Logs filter panel into a more coherent grouped interface.
  - Added fieldset and legend structure for better semantic grouping.
  - Added a persistent multi-select severity filter.
  - Severity selections are stored in app settings and reused across log views.
  - Empty severity selection now explicitly means all severities.
  - Added severity filter reset behavior.
  - Added active severity summary text.
  - Preserved selected severities when building sort and filter URLs.
  - Kept backward compatibility with the legacy single `severity` query parameter.
  - Updated live filtering JavaScript to handle severity checkboxes and summary updates.
  - Added a new `admin_log_severity_filter_test.php` test.

#### Improved unpublished-gallery visibility for admins

  - Public gallery cards now expose normalized visibility metadata in `data-gallery-visibility`.
  - Logged-in admins now get a visible marker for unpublished galleries in public listings.
  - Anonymous preview mode does not show the admin unpublished marker.
  - Added dedicated public CSS for unpublished gallery cards.
  - Unpublished galleries visible only to admins are visually greyed and labeled instead of looking identical to public galleries.
  - Added English and Czech strings for the unpublished admin hint.

#### Improved theme background optimization UI

  - Added UI strings and styling for optimized theme background handling.
  - Theme media controls can now show whether an optimized WebP background is active.
  - Added controls and labels for:
    - generating an optimized background
    - regenerating an optimized background
    - deleting the optimized background
    - viewing the original image
    - viewing the served image
    - selecting optimized background size
  - Added admin theme preview CSS for background optimization states.
  - Added clearer labels for whether the site is serving the original background or the optimized WebP copy.

#### Improved admin tab hash behavior

  - Updated admin tab JavaScript to preserve and resolve tab hashes more reliably.
  - Added configurable hash suppression for cases where a panel should not write browser history.
  - Added helper behavior for activating admin tabs inside dynamic side-panel roots.
  - Updated module versioning for the side-panel and tab modules.

#### Updated project documentation

  - Rewrote `README.md` into a more structured project overview.
  - Expanded feature descriptions for gallery management, image management, thumbnails, access control, tags, voting, navigation, downloading, theming, updates, admin tools, telemetry, and security.
  - Reorganized installation instructions around the one-file shared-hosting installer.
  - Added clearer local-development and troubleshooting sections.
  - Rewrote `ARCHITECTURE.md` for the modern v0.66+ codebase.
  - Documented the request flow, route table, app directory structure, data model, feature responsibilities, performance model, security practices, and extension workflow.
  - Updated documentation to reflect the split controller and service structure.

### Updates and GitHub API details

  - The update page now uses cached update status by default.
  - Normal update-page reloads no longer need to consume a fresh GitHub API request each time.
  - The Force check button intentionally performs a fresh check.
  - GitHub API diagnostics are shown on the update page using stored response headers.
  - The app records rate-limit state from the responses it already needed to make.
  - The app does not spend an extra API request only to display rate-limit status.
  - `Retry-After` and primary reset times are turned into a local next-safe-check window.
  - Cached GitHub response bodies are reused for unchanged remote files.
  - Branch probes now retain diagnostics when a branch is reachable but missing a usable marker.
  - Update status can distinguish:
    - current installation
    - pending update
    - stale remote marker
    - unavailable remote marker
    - rate-limited wait state
    - unknown cached state

### Automatic update behavior

  - Automatic stable updates are deliberately conservative.
  - They run only when enabled by admin setting.
  - They run only from safe browser requests.
  - They are throttled by a local five-hour interval.
  - They use a lock setting to avoid overlapping checks.
  - They never silently switch a beta installation back to stable.
  - Beta installations can still run dry checks for metadata and diagnostics.
  - Automatic install results are written into admin logs with update category and severity metadata.
  - Failures are logged with current version, beta state, PHP version, and error detail.

### URL rewrite behavior

  - Clean URLs remain the default behavior.
  - Admins can turn clean URL emission off from the dashboard maintenance area.
  - When disabled, public helpers emit compatible query-string URLs.
  - Compatibility checking is advisory rather than a false guarantee.
  - The system checks practical signals such as rewrite environment variables, request routing state, and `.htaccess` markers.
  - The dashboard warning explains likely hosting causes such as missing `.htaccess` support or missing rewrite support.

### Admin logs behavior

  - The severity filter is no longer a single dropdown.
  - Multiple severities can be selected at once.
  - The selection persists between visits.
  - Resetting severity returns the page to all severities.
  - Search, status, category, severity, and time-order filters remain compatible with live AJAX refresh.
  - The filter layout now has clearer visual hierarchy and better grouping.

### Telemetry export details

  - The standalone HTML telemetry export now has broader operational value.
  - It includes engagement, performance, error, access-log-like, database, and job-run sections.
  - Tables use reusable rendering helpers.
  - Charts are generated as compact HTML/CSS report elements.
  - The report remains local and anonymous.
  - Existing telemetry tables are queried defensively so missing optional telemetry tables do not break export rendering.

### Public and admin navigation fixes

  - Admin login now returns users to the page that initiated login.
  - Upload workflows preserve the side-panel source URL.
  - Photo view close behavior preserves paginated gallery state.
  - Existing gallery uploads reopen the relevant gallery editor tab after completion.
  - Public clean URL generation can be disabled without breaking the underlying query-string routes.

### Translations

  - Added English and Czech strings for:
    - URL rewrite settings and warnings
    - GitHub API policy diagnostics
    - Force check actions
    - automatic update settings
    - automatic update dry runs
    - automatic update log messages
    - admin log severity filter summaries
    - theme background optimization controls
    - dashboard original-storage metric
    - unpublished gallery admin marker
    - date picker JavaScript labels
  - Updated PHP translation fallback files with the new string coverage.
  - Kept the multilingual structure compatible with existing `t()` and JavaScript translation usage.

### Tests

  - Added `tests/admin_log_severity_filter_test.php`.
  - Added `tests/url_rewrite_settings_test.php`.
  - Covered severity-filter normalization, persistence behavior, reset semantics, and compatibility with legacy query values.
  - Covered URL rewrite setting defaults, saved values, compatibility states, marker detection, and query-string fallback behavior.

### Files changed

  - `ARCHITECTURE.md`
  - `README.md`
  - `app/bootstrap.php`
  - `app/controllers/admin_auth.php`
  - `app/controllers/admin_dashboard.php`
  - `app/controllers/admin_logs.php`
  - `app/controllers/admin_telemetry.php`
  - `app/controllers/admin_uploads.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/updates.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/cs.php`
  - `app/lang/en.json`
  - `app/lang/en.php`
  - `app/security.php`
  - `app/services.php`
  - `app/services/app_settings.php`
  - `app/services/github.php`
  - `app/services/logs.php`
  - `app/services/updates.php`
  - `public/assets/gallery-modules/admin-logs.js`
  - `public/assets/gallery-modules/admin-operations.js`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/admin-tabs.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/admin-theme-preview.css`
  - `public/assets/styles/admin.css`
  - `public/assets/styles/public.css`
  - `tests/admin_log_severity_filter_test.php`
  - `tests/url_rewrite_settings_test.php`

### Notes

  - Clean rewritten URLs remain enabled by default.
  - Disable URL rewrite only on hosting where clean routed URLs do not work.
  - Normal update-page reloads should now use cached update status instead of spending repeated GitHub API calls.
  - Use Force check only when a fresh GitHub request is intentional.
  - Automatic updates install only stable releases and are skipped for beta code.
  - Automatic update dry runs never install files.
  - The dashboard original-storage metric counts imported original files only.
  - The telemetry export depends on telemetry tables and collected telemetry data; missing optional data results in empty report sections, not fatal errors.
  - The core manifest was refreshed for the new services, controllers, scripts, styles, tests, and documentation changes.

## Version 0.66

### Large internal refactor

  - Split the large gallery admin controller into focused controller files while preserving the old include contract through `app/controllers/admin_galleries.php`.
  - Moved gallery discovery, bulk gallery operations, gallery editing, gallery reordering, image bulk actions, image reordering, public inline admin actions, and shared admin renderers into separate files.
  - Split the thumbnail service into focused service files for formats, sources, HTML rendering, cached bundles, generation, maintenance, and DNG display derivatives.
  - Split the browser admin JavaScript into focused ES modules while keeping `admin-operations.js` as the legacy re-export entry point.
  - Split the admin CSS into focused stylesheet files for dashboard, layout, gallery list, media tools, reordering, tags, update page, patch notes, and theme editor areas.
  - Regenerated the integrity manifest for the new file structure.

### Admin tag management

  - Added a new `admin_tags` route and controller.
  - Added a dedicated Admin Tags page where admins can list, edit, rename, delete, and review reusable tags.
  - Added editable tag metadata:
    - display name
    - slug
    - public description
    - usage counts
  - Added a `tag_metadata` service for tag normalization, metadata editing, public lookup, and deletion logic.
  - Added database migration `202605120002_tag_metadata.php` for tag descriptions and metadata support.
  - Kept tags lowercase and safe for clean public URLs.
  - Added public tag admin actions so logged-in admins can edit a tag directly from a public tag page.
  - Added compact tag rendering so gallery cards can show a limited number of tags without expanding the layout too much.
  - Added Czech and English translation coverage for the new tag management UI.

### Clean public tag URLs

  - Refactored public tag rendering into `app/controllers/public_tags.php`.
  - Kept the legacy `tags.php` include contract while moving public tag page logic into focused code.
  - Added clean tag URL support so tag pages no longer have to rely only on query-style URLs.
  - Added public tag lookup by slug.
  - Added public gallery lookup by tag id.
  - Added tag descriptions to public tag pages when configured by the admin.

### Lightbox and fullscreen voting

  - Moved voting rendering into a focused vote controller.
  - Added reusable vote form rendering through `render_vote_form_html()`.
  - Added a dedicated `lightbox-votes.js` module for synchronizing lightbox and fullscreen vote controls.
  - Lightbox and fullscreen voting now clone and reuse the same vote form concept used by gallery cards instead of maintaining a separate inconsistent implementation.
  - Vote button state is synchronized after voting so gallery cards, lightbox, and fullscreen views stay consistent.
  - Voting controls are hidden when voting is disabled for the gallery or picture context.
  - Vote score display is suppressed when voting is disabled, preventing a half-visible inactive voting UI.
  - Adjusted fullscreen toolbar layout so the vote arrow aligns inline with the other controls.
  - Refined fullscreen map and toolbar spacing so the map label and empty lower gap do not reappear in map split mode.

### Admin side panel workflow

  - Moved side-panel behavior into a focused `admin-side-panel.js` module.
  - Improved side-panel form preparation for gallery edit, image edit, upload, and bulk image actions.
  - Added better incremental refresh handling after side-panel saves.
  - Added public gallery fragment replacement so side-panel actions can update the visible gallery page without a full manual reload.
  - Added image row updates after side-panel image edits.
  - Added public image card updates after side-panel image edits.
  - Added created-gallery focus handling so newly created galleries can be visually located after panel actions.
  - Improved upload progress handling inside the panel.
  - Improved upload result propagation for uploaded, scanned, thumbnail-created, and thumbnail-failed counts.

### Admin date picker

  - Added a focused `admin-date-picker.js` module.
  - Reworked native date inputs into a compact admin control.
  - Moved the clickable calendar icon before the date value.
  - Added Today and Delete quick actions.
  - Kept the real submitted value on the native date input.
  - Re-applies the enhancement when forms are loaded through the admin side panel.
  - Added CSS for consistent date picker sizing and alignment in both admin zone and side-panel forms.

### Admin logs diagnostics

  - Added database migration `202605120003_admin_log_diagnostics.php`.
  - Added diagnostic columns for admin logs:
    - fingerprint
    - HTTP method
    - AJAX flag
  - Added indexes for log fingerprint and route/method/time filtering.
  - Added migration logic to categorize older logs more accurately.
  - Added migration logic to mark low-severity todo logs as done when appropriate.
  - Added migration logic to infer subject type for older gallery, image, thumbnail, update, telemetry, and tag events.
  - Updated log rendering to force English labels on the logs page, making exported and displayed operational logs easier to share for debugging.
  - Improved live log filters through the new `admin-logs.js` module.

### Thumbnail and DNG handling

  - Refactored thumbnail handling out of the monolithic thumbnail service.
  - Added `dng_derivatives.php` for DNG display master generation and derivative handling.
  - Added support checks for DNG derivative generation.
  - Added fallback paths for embedded DNG previews when full RAW decoding is not available.
  - Added thumbnail bundle helpers for cached variant selection.
  - Added focused thumbnail source helpers for paths, URLs, srcsets, WebP srcsets, and fallback selection.
  - Added focused thumbnail generation helpers for JPEG and WebP output.
  - Added focused thumbnail maintenance helpers for inventory and repair workflows.
  - Improved partial-file cleanup when thumbnail generation fails.
  - Preserved EXIF-sensitive WebP generation paths where supported.

### Public gallery rendering

  - Updated public gallery rendering to work with the new tag metadata and compact tag display.
  - Improved public gallery card tag layout so tags can sit near date metadata without forcing unnecessary vertical expansion.
  - Added public CSS refinements for tag and metadata display.
  - Improved public gallery admin actions for tags.
  - Kept public gallery rendering compatible with the existing visibility and admin-edit workflows.

### Theme and background handling

  - Updated theme asset handling so theme CSS revisions can be refreshed more reliably after admin theme changes.
  - Added focused theme editor and theme preview CSS files.
  - Improved gallery background service handling.
  - Added small modern-theme CSS refinements.
  - Improved update-page and patch-notes styling through dedicated admin stylesheets.

### Patch notes viewer styling

  - Added a dedicated `admin-patch-notes.css` stylesheet.
  - Restyled patch-note content cards, headings, paragraphs, lists, inline code, and preformatted code.
  - Added loading-state styling for dynamically refreshed patch-note fragments.
  - Improved the version picker styling so installed and latest markers are easier to scan.
  - Kept the patch notes panel visually consistent with the dashboard-style admin update page.

### Admin dashboard and gallery list JavaScript

  - Added `admin-core.js` for shared browser helpers used by multiple admin modules.
  - Added `admin-gallery-list.js` for gallery filters, tree handling, dashboard reordering, and public page reordering.
  - Added `admin-image-reordering.js` for image table drag sorting and name sorting.
  - Added `admin-refresh-progress.js` for refresh progress feedback.
  - Added `admin-thumbnail-progress.js` for thumbnail progress feedback.
  - Added `admin-tabs.js` for reusable admin tab behavior.
  - Added `tag-suggestions.js` for safer tag autocomplete behavior.
  - Added `admin-picture-game.js` for keyboard support on the picture game admin screen.

### Upload workflow

  - Simplified upload controller responsibilities after moving shared side-panel logic into browser modules.
  - Improved upload progress display.
  - Improved upload result reporting for side-panel workflows.
  - Kept normal upload routes and non-JavaScript fallback behavior intact.

### Translations

  - Updated English and Czech JSON translation files for the new admin tag page, tag metadata, logs, date picker, patch notes viewer, and admin UI refinements.
  - Updated English and Czech PHP translation loaders with the new string coverage.
  - Kept the multilingual structure compatible with existing `t()` usage.

### Integrity, migrations, and tests

  - Updated `app/core-manifest.json` for the new controllers, services, browser modules, stylesheets, and migrations.
  - Added new migrations for tag metadata and admin log diagnostics.
  - Updated the initial schema migration with the new tag metadata field.
  - Extended the gallery branding model test coverage.
  - Preserved legacy include entry points where large files were split, reducing regression risk for existing routes.

## Version 0.65.7

### Tag normalization and autocomplete sanitizing

  - Added canonical tag normalization so stored tag names and slugs are forced into a safe lowercase form.
  - Existing tags are merged when they resolve to the same canonical value.
  - Gallery sidecar tag lists now stay normalized in the same format as database tags.
  - Browser tag autocomplete now mirrors the server-side normalization instead of preserving raw tag text.
  - Tag helper text was expanded to explain the lowercase safe-tag behavior to admins.

## Version 0.65.6

### Tag suggestion autocomplete refresh

  - Extended tag suggestions so autocomplete can run inside a specific DOM root instead of only on the whole document.
  - Wired the admin side-panel loader so newly loaded panel content initializes tag suggestions too.
  - Restyled the public tag suggestion dropdown so reused tag chips are easier to scan and select.
  - Relaxed the root guard so tag suggestions work with any render root that exposes `querySelectorAll`.

## Version 0.65.5

### Theme content revision caching

  - Bumped the public content revision when the gallery description layout changes so public HTML caches see the new
  card class right after a Theme save.
  - Split anonymous cache handling so gallery pages that render DB-backed card HTML revalidate on refresh.
  - Kept short public caching for static routes such as robots, sitemap, and theme_css.
  - Refreshed the integrity manifest for the revised theme and security code.

## Version 0.65.4

### Updates page layout refresh

  - Reworked the Updates page into a dashboard-style layout with summary cards and clearer primary actions.
  - Added a dedicated advanced tools section for beta installs, restores, and clean reinstalls.
  - Added matching admin styling for the new hero, metric cards, and responsive layout.
  - Regenerated the integrity manifest for the updated controller and stylesheet.

## Version 0.65.3

### Updates page patch notes picker

  - Replaced the plain patch-notes version select with a grouped picker that shows release streams, installed status,
  and latest markers.
  - Kept the native select in sync so form submission and fallback behavior still work.
  - Added translation strings for release counts and the Installed/Latest badges.
  - Refined the patch-notes panel styling so the new picker and version badges fit the admin layout cleanly.

## Version 0.65.2

### Updates page hardening

  - Patch notes and update version checks now use the GitHub Contents API instead of raw branch file URLs.
  - Remote HTTP fetching for update data now uses shared headers and timeout handling more consistently.
  - The update page now reuses cached patch-note data more deliberately while still fetching fresh content when needed.

## Version 0.65.1

Version 0.65.1 tightens public thumbnail loading so the first visible cards paint more consistently and progressive replacements do not flicker as aggressively during decode.

### Highlights

- Public gallery cards now prioritize the first visible thumbnails during initial paint.
- Progressive thumbnail upgrades now preload the likely replacement image before swapping the visible `srcset`.
- Public thumbnail slots keep a stable painted background while images decode.

## Version 0.65

Version 0.65 focuses on gallery metadata, public card presentation, translation coverage, admin diagnostics, update visibility, and access hardening.

### Highlights

#### Added configurable gallery description layouts

- Added a Theme-level default gallery-card description layout.
- Added per-gallery override support.
- Added two public card systems:
  - `Vertical system`
  - `Horizontal system`
- Existing galleries inherit the Theme default unless overridden.
- Horizontal cards place the picture at the top, then title, date, tags, and a compact Markdown-capable description.
- Added database support for `galleries.description_layout`.
- Added sidecar support for gallery description layout metadata.

#### Added manual gallery dates

- Added optional manual gallery dates to galleries.
- Dates are admin-entered and independent from upload dates or EXIF dates.
- Existing galleries keep the date empty by default.
- Empty dates are not displayed publicly.
- Added date fields to create, edit, side-panel, and create-and-upload workflows.
- Added public rendering for gallery dates in hero metadata and gallery cards.
- Added database support for `galleries.gallery_date`.

#### Improved gallery description formatting

- Public gallery descriptions now preserve user-entered line breaks.
- Added Markdown formatting hints in gallery description editors.
- Added guidance for bold, italic, inline code, links, and paragraph spacing.
- Improved description display in public gallery cards.

#### Added admin login and password reset throttling

- Added rate limiting for admin login attempts.
- Added rate limiting for password reset requests.
- Added visitor-level and identifier-level throttle buckets.
- Stored only hashed throttle subjects.
- Avoided storing raw submitted usernames, raw email addresses, or raw IP addresses in the throttle table.
- Added database support for `auth_rate_limits`.

#### Improved password reset and account localization

- Converted account, login, forgot-password, reset-password, SMTP, and password reset messages to translation keys.
- Localized password reset email subject and body.
- Localized SMTP diagnostics and password reset delivery messages.
- Localized account settings notices and validation errors.

#### Expanded translation infrastructure

- Added a dedicated translation service.
- Added request language bootstrap during routing.
- Added English fallback behavior for missing translation keys.
- Added browser-side translated string export.
- Added translation coverage diagnostics in Theme language settings.
- Expanded Czech and English language packs.

#### Added admin dashboard render profiling

- Added an admin-only dashboard render profiler.
- Added counters and timers for schema checks, DB queries, setting reads, gallery ordering, thumbnail maintenance summary reads, preview cover lookup, and rendered gallery rows.
- Added diagnostic output for dashboard performance tuning.
- Kept profiling admin-only.

#### Optimized admin dashboard thumbnail maintenance checks

- Dashboard thumbnail maintenance can now use cached summaries.
- Expensive exact thumbnail scans can be deferred.
- The dashboard can show that thumbnail status was not checked instead of forcing heavy work during first render.
- Dedicated thumbnail actions remain available for exact scans and repairs.

#### Added dynamic patch notes viewer to Updates

- Added a collapsible patch notes panel on the Updates page.
- Patch notes can be fetched from GitHub.
- Parsed patch notes are cached locally.
- Bundled local patch notes are used as fallback when GitHub cannot be reached.
- Admins can select installed, pending, or other available versions.
- Version switching loads dynamically without a full page reload.
- The patch notes panel received modern admin styling.

#### Hardened Apache access rules

- Disabled directory indexes.
- Blocked common sensitive file extensions.
- Blocked `.git` access.
- Blocked dotfiles except `.well-known/`.
- Extended direct-access protection to additional private directories.
- Hardened gallery directory access rules.

#### Improved admin UI localization and polish

- Converted more dashboard, gallery, upload, logs, telemetry, integrity, update, reset, theme, tag, media, and picture game UI strings to translation keys.
- Improved dashboard action labels and notices.
- Improved gallery editor labels, hints, and helper text.
- Improved side-panel field layout for date, description, and layout controls.
- Improved compact tag/date placement in horizontal gallery cards.

### Technical Notes

New or heavily updated areas include:

- `app/services/translations.php`
- `app/services/auth_throttle.php`
- `app/services/admin_render_profiler.php`
- `app/services/gallery_dates.php`
- `app/services/gallery_description_layout.php`
- `app/services/updates.php`
- `database/migrations/202605110001_auth_rate_limits.php`
- `database/migrations/202605110002_gallery_description_layout.php`
- `database/migrations/202605110003_gallery_manual_date.php`
- `app/controllers/admin_auth.php`
- `app/controllers/admin_dashboard.php`
- `app/controllers/admin_galleries.php`
- `app/controllers/admin_theme.php`
- `app/controllers/updates.php`
- `app/controllers/public_gallery.php`
- `app/lang/en.json`
- `app/lang/cs.json`
- `.htaccess`
- `galleries/.htaccess`

### User Impact

#### For visitors

- Gallery cards can use a new horizontal presentation.
- Gallery dates appear only when admins set them.
- Descriptions preserve intentional line breaks.
- Public pages keep a cleaner metadata layout.
- Protected and private content remains guarded by the same access model.

#### For administrators

- Theme settings can define the default gallery description layout.
- Individual galleries can override that layout.
- Gallery dates can be managed from normal and side-panel workflows.
- Password reset delivery and SMTP diagnostics are easier to understand.
- Login and reset endpoints are more resistant to repeated attempts.
- The Updates page can show release notes before installing an update.
- Dashboard performance is easier to diagnose.

### Notes

- Existing galleries do not display dates until an admin sets one.
- Existing galleries inherit the Theme gallery-card layout default.
- Login throttling requires the new `auth_rate_limits` migration.
- Gallery dates require the new `gallery_date` migration.
- Per-gallery layout overrides require the new `description_layout` migration.
- The patch notes viewer caches fetched release notes under the application cache.
- The core manifest was refreshed for the updated file set.

## Version 0.64

Version 0.64 is a major public rendering performance and admin workflow refinement release focused on making large galleries faster, reducing unnecessary thumbnail work, improving dynamic refresh stability, and restoring the full create-and-upload gallery workflow from the public hero and gallery editor actions.

This release is especially important for real galleries with many photos, GPS metadata, pagination, subgalleries, and active admin-side editing. A large part of the work is internal optimization, but the result should be visible in normal browsing: faster first render, fewer filesystem checks, fewer repeated thumbnail lookups, lighter gallery map handling, and cleaner admin side-panel behavior.

### Highlights

#### Public gallery rendering was profiled and optimized

A new admin-only public render profiler was added for public gallery and home page rendering.

What changed:

- added a dedicated public render profiling service
- added request timing for public home and gallery pages
- added counters for:
  - database queries
  - filesystem checks
  - thumbnail lookups
  - thumbnail direct hits
  - thumbnail fallback searches
  - thumbnail fallback checks
  - thumbnail fallback hits
  - thumbnail media fallbacks
  - thumbnail bundle requests
  - thumbnail bundle cache hits
  - thumbnail bundle cache misses
  - thumbnail bundle variant hits
  - gallery scan calls
  - gallery map cache hits
  - gallery map cache misses
  - rendered subgalleries
  - rendered images
  - SEO JSON-LD images
- added named timers for expensive render phases such as:
  - gallery image query
  - child gallery lookup
  - image tag lookup
  - image vote lookup
  - gallery grid settings
  - picture game lookup
  - background asset lookup
  - SEO metadata lookup
  - gallery card rendering
  - image card rendering
  - thumbnail bundle discovery
  - filesystem checks
- added thumbnail-purpose tracking so expensive thumbnail work can be tied back to the exact render feature that caused it
- added a compact admin-only diagnostic panel on public pages
- kept the profiling invisible to anonymous visitors

User impact:

- admins can now see what actually makes a public gallery page expensive
- large gallery performance tuning is much easier
- thumbnail and filesystem pressure can be diagnosed directly from the rendered page
- anonymous visitors do not see the diagnostic panel
- normal visitor behavior is not changed by the profiler UI

#### Thumbnail rendering now uses request-local thumbnail bundles

Thumbnail lookup behavior was heavily optimized by resolving available thumbnail variants once per image during a request.

What changed:

- added request-local thumbnail bundle discovery
- added a stable thumbnail bundle cache key per image
- collected generated JPEG and WebP variants in one pass
- reused discovered variants for:
  - visible image cards
  - subgallery covers
  - subgallery collages
  - lightbox preview URLs
  - map marker thumbnails
  - responsive `srcset` output
- added bundle-aware URL selection
- added bundle-aware `srcset` generation
- added safe media fallback handling when no generated thumbnail exists
- preserved existing fallback behavior for missing, partially generated, deleted, regenerated, or DNG-derived thumbnails
- reduced repeated `is_file()` checks for the same image and size combinations
- added request-local caching to legacy `thumbnail_url()` calls
- added profiling counters for direct thumbnail hits, fallback hits, media fallbacks, and cache hits

User impact:

- gallery pages with many visible photos should render with fewer repeated thumbnail checks
- public pages should spend less time repeatedly searching for the same thumbnail variants
- DNG display derivatives and fallback media behavior remain safe
- thumbnail generation and maintenance behavior remains compatible with existing galleries
- admins get better diagnostic visibility into which thumbnail paths are expensive

#### Progressive thumbnail rendering was added

Public gallery cards now use a progressive thumbnail strategy.

What changed:

- added `thumbnail_progressive_picture_html()`
- visible cards initially render with a small thumbnail candidate
- larger responsive `srcset` candidates are attached as deferred progressive data
- the browser can paint the public grid sooner
- JavaScript upgrades thumbnails after initial render when appropriate
- first visible images can be marked eager with high fetch priority
- later images remain lazy and low priority
- gallery covers and collage images also use the progressive thumbnail path
- existing `thumbnail_picture_html()` was updated to support precomputed thumbnail bundles

User impact:

- first paint should feel faster on image-heavy gallery pages
- large image grids should avoid forcing all responsive candidates immediately
- public galleries should feel more responsive during initial load
- browser bandwidth and decode pressure should be better aligned with what is actually visible

#### Responsive thumbnail sizing was rebuilt for lifecycle-safe updates

The responsive thumbnail JavaScript module was updated to work safely with dynamically replaced public gallery content.

What changed:

- added teardown support for responsive thumbnail behavior
- added lifecycle state tracking with `AbortController`
- added deferred idle work for thumbnail upgrades
- measured card widths are used to update `sizes`
- progressive thumbnails are upgraded only when needed
- high-DPI screens are handled with a capped device-pixel-ratio heuristic
- thumbnails are processed in small batches instead of all at once
- responsive thumbnail listeners are cleaned up before public gallery fragments are replaced
- responsive thumbnail behavior is rebound after server-rendered content refreshes

User impact:

- dynamically refreshed gallery content no longer keeps stale thumbnail listeners
- side-panel edits and uploads can refresh the public gallery more safely
- thumbnail sizing remains accurate after gallery content changes
- large galleries avoid a large burst of immediate client-side thumbnail work

#### Public lightbox loading is now deferred

The public lightbox module was split so the heavy viewer logic is loaded only when needed.

What changed:

- added a new `lightbox-deferred.js` module
- the full lightbox implementation is loaded dynamically
- the real lightbox is activated:
  - after page load and idle time
  - immediately when a visitor clicks a photo
  - immediately when a visitor opens a photo map
  - immediately when a gallery map is opened
- the first user click is replayed after the full module is loaded
- deferred activation ignores admin controls and side-panel triggers
- the existing lightbox implementation remains preserved
- gallery.js now imports the deferred lightbox entry point instead of the full module directly

User impact:

- public gallery pages do less JavaScript work during first render
- visitors still get normal lightbox behavior when clicking a photo
- gallery maps and photo maps still work when requested
- admin edit/delete/sidebar actions do not accidentally trigger lightbox initialization
- large photo pages should feel lighter before the first photo is opened

#### Lightbox lifecycle cleanup was added

The full lightbox module now supports explicit teardown and cleaner reinitialization.

What changed:

- added `teardownGalleryLightbox()`
- added internal lightbox state tracking
- added `AbortController` based listener cleanup
- cleaned up pending animation frames and timers
- cleaned up fullscreen and map-related state before DOM replacement
- removed stale map instances during teardown
- removed stale split-map resize observers during teardown
- refreshed lightbox order after public content replacement
- kept public gallery reorder integration compatible with the refreshed DOM

User impact:

- dynamic gallery refreshes are safer
- side-panel saves, uploads, and gallery changes are less likely to leave stale lightbox state behind
- map overlays are less likely to reference removed DOM nodes
- fullscreen navigation order remains aligned with the current rendered gallery state

#### Gallery map handling is now lazy and cacheable

GPS gallery map payloads were optimized so normal gallery rendering no longer has to build full map marker data just to decide whether the map button should appear.

What changed:

- added a cheap `gallery_has_map_points()` availability check
- gallery pages now check whether map points exist without building every marker payload
- full map marker payload generation remains available through the map endpoint
- added a gallery map cache directory under `cache/gallery-maps`
- added deterministic map payload cache fingerprints
- map cache fingerprints include:
  - gallery id
  - public/admin access mode
  - recursive/direct mode
  - point count
  - image update timestamps
  - GPS extraction timestamps
  - gallery update timestamps
- added cache hit and miss profiling counters
- added pruning of older cache files after writing a fresh map payload
- added a global map cache clear helper
- thumbnail maintenance now clears cached gallery map payloads so marker thumbnails do not keep stale fallback URLs
- `image_map_point()` can now skip thumbnail generation when only metadata is needed

User impact:

- gallery pages with GPS-enabled branches should render faster
- the gallery map button can still appear correctly
- full marker data is built only when the map payload is actually needed
- cached map payloads make repeated map openings cheaper
- regenerated thumbnails no longer leave old marker thumbnail URLs behind

#### Hidden lightbox source nodes no longer resolve large previews eagerly

Pagination-aware lightbox source nodes were optimized to avoid resolving large preview thumbnails for non-rendered photos during normal page rendering.

What changed:

- hidden lightbox source nodes now keep preview URLs empty
- hidden source nodes remain available for fullscreen ordering
- visible image cards still provide their normal preview data
- map metadata for hidden nodes can skip thumbnail generation
- JSON-LD image metadata is capped to the visible page slice

User impact:

- paginated galleries no longer spend work resolving 1600px thumbnails for every hidden image
- fullscreen ordering remains compatible with pagination
- large galleries avoid unnecessary preview URL construction during normal page load
- SEO metadata remains present but is kept bounded and practical

#### SEO JSON-LD image output was capped to visible content

Gallery JSON-LD rendering was adjusted to avoid expensive metadata generation for very large galleries.

What changed:

- gallery JSON-LD now receives the currently visible image slice
- JSON-LD image output is capped to the first 20 visible images
- NSFW-restricted images continue to be skipped
- thumbnail resolution for JSON-LD content URLs is now profiled
- hidden lightbox ordering remains separate from crawler metadata

User impact:

- large galleries avoid unnecessary SEO thumbnail lookups across the whole image set
- crawler metadata remains useful without turning public rendering into a full-gallery thumbnail scan
- paginated galleries now keep structured metadata aligned with the visible page

#### Create gallery here now uses create-and-upload mode

The `Create gallery here` workflow was restored and corrected so it opens the combined gallery creation and optional upload workflow.

What changed:

- added `upload_mode=new` support to the upload controller
- added `parent_id` handling for the create-and-upload workflow
- added validated parent-gallery prefill logic
- added contextual notices for the selected parent gallery
- updated non-panel upload rendering so create-and-upload mode shows only the new-gallery form
- updated side-panel rendering so create-and-upload mode opens a focused gallery workflow
- the side panel now explains that photos are optional
- the workflow can still create an empty gallery when no photos are selected
- `Create gallery here` in the gallery editor now links to `admin_upload` with `upload_mode=new`
- the old empty-gallery-only admin-new-gallery side-panel path is no longer used for this action

User impact:

- admins can create a child gallery and upload photos in one workflow
- the action no longer creates only an empty gallery unless the user intentionally uploads nothing
- the parent gallery context is explicit
- gallery creation from the editor is consistent with the public hero action
- fewer clicks are needed when building nested galleries

#### Add gallery here was restored to the public hero action bar

The public gallery hero now includes a compact admin-only child-gallery creation action.

What changed:

- added `render_public_gallery_admin_add_child_link()`
- added an admin-only `Add gallery here` hero icon
- the icon is hidden during anonymous preview mode
- the icon opens the side panel in create-and-upload mode
- the action uses the current gallery as the new gallery parent
- the action includes accessibility labels and title text
- the button uses the compact hero icon button styling

User impact:

- logged-in admins can create a child gallery directly from the public gallery hero
- the public page workflow matches the admin edit workflow
- anonymous preview remains clean and visitor-like
- public gallery management is faster when building nested albums

#### Dynamic public gallery refresh now has proper lifecycle teardown and rebind

The side-panel refresh pipeline was improved so replacing public gallery content also resets dependent browser modules cleanly.

What changed:

- public gallery refresh now tracks whether the public gallery was replaced
- responsive thumbnails are torn down before content replacement
- back-to-top behavior is torn down before content replacement
- lightbox behavior is torn down before content replacement
- public gallery lifecycle modules are rebound after replacement
- public page reordering is rebound after replacement
- a `php-gallery:public-content-replaced` event is dispatched after replacement
- the back-to-top shell can be preserved while replacing the server-rendered gallery frame
- attributes are copied from the fresh server-rendered frame to the persistent frame
- stable controls such as the back-to-top button are preserved instead of being discarded unnecessarily
- subgallery refresh now uses the same replacement path as the full public gallery refresh

User impact:

- side-panel upload and edit workflows refresh the visible gallery more reliably
- back-to-top behavior survives dynamic gallery updates
- lightbox behavior does not keep stale references after updates
- responsive thumbnails continue working after panel saves
- public reorder mode remains compatible with refreshed server-rendered content

#### Back-to-top behavior was made refresh-safe

The back-to-top module was rewritten to avoid holding stale DOM references.

What changed:

- added module-level lifecycle state
- added `teardownBackToTopButton()`
- DOM elements are looked up on demand
- click handling is delegated safely
- scroll and resize listeners use `AbortController`
- animation-frame updates are cancelled during teardown
- visibility checks now confirm that current elements are connected
- fullscreen and lightbox states continue to suppress the button

User impact:

- back-to-top no longer breaks after gallery content is dynamically replaced
- the button remains correctly hidden during fullscreen/lightbox states
- repeated refreshes do not stack duplicate event listeners
- long gallery pages keep the expected scroll helper behavior

#### Browser rendering of large public grids was improved

Public gallery cards and image cards now allow the browser to skip work for offscreen content when supported.

What changed:

- added `content-visibility: auto` for public gallery cards and image cards
- added intrinsic size hints for skipped cards
- cards become fully visible when focused or dragged
- support is applied only in browsers that support `content-visibility`

User impact:

- large public grids can be cheaper for the browser to lay out and paint
- keyboard focus and drag interactions remain safe
- unsupported browsers simply keep the previous behavior

### Public UI and Admin Workflow Refinements

#### Hero and editor gallery creation

- The public hero now exposes the missing `Add gallery here` action for logged-in admins.
- The admin gallery editor now routes `Create gallery here` to the combined create-and-upload workflow.
- Both paths use the same parent-gallery context.
- Both paths support optional photo upload during gallery creation.
- Empty gallery creation remains possible by submitting without selecting photos.

#### Upload panel copy and context

- Existing-gallery upload mode now clearly says it adds photos to an existing gallery.
- Create-and-upload mode now clearly says it creates a child gallery and optionally uploads photos.
- Errors in create-and-upload mode now use `Create or upload failed` wording.
- Parent context is shown when available.

#### Admin-side public refresh behavior

- Public gallery refresh now prefers server-rendered HTML as the source of truth.
- The dynamic refresh path avoids stale module state.
- The refresh system now handles gallery frames, subgallery sections, and image lists more consistently.

### Performance Details

This release reduces several expensive public render behaviors.

#### Reduced repeated thumbnail work

Before this release, a visible photo could trigger multiple independent thumbnail checks for the same generated files. The new thumbnail bundle path discovers available generated variants once and reuses them for multiple outputs during the same request.

This affects:

- image card thumbnail HTML
- lightbox preview URLs
- map marker thumbnails
- progressive picture HTML
- WebP source sets
- JPEG source sets
- subgallery covers
- subgallery collages

#### Reduced full-gallery work during paginated rendering

Paginated galleries now avoid some work that was previously performed across the complete image set during normal rendering.

Reduced work includes:

- large preview URL generation for hidden lightbox source nodes
- full map marker payload construction just to show the map button
- JSON-LD thumbnail resolution across the whole gallery

#### Better first-render behavior in the browser

The browser now performs less immediate work on large gallery pages because:

- full lightbox setup is deferred
- responsive thumbnail upgrades are batched
- progressive thumbnails start smaller
- offscreen cards can use `content-visibility`
- lifecycle modules are rebound only after server-rendered refreshes

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/admin_galleries.php`
- `app/controllers/admin_uploads.php`
- `app/controllers/public_gallery.php`
- `app/helpers.php`
- `app/services.php`
- `app/services/exif.php`
- `app/services/gallery_backgrounds.php`
- `app/services/gallery_lookup.php`
- `app/services/public_render_profiler.php`
- `app/services/thumbnails.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/gallery-modules/back-to-top.js`
- `public/assets/gallery-modules/lightbox-deferred.js`
- `public/assets/gallery-modules/lightbox.js`
- `public/assets/gallery-modules/responsive-thumbnails.js`
- `public/assets/gallery.js`
- `public/assets/styles/public.css`
- `app/core-manifest.json`

### Internal Changes

#### New backend service

- Added `app/services/public_render_profiler.php`
- Loaded the profiler from `app/services.php`
- The profiler is admin-only and disabled for CLI requests
- The profiler exposes helpers for:
  - request start
  - gallery id assignment
  - counters
  - timers
  - database timing
  - filesystem timing
  - thumbnail-purpose tracking
  - final diagnostic panel rendering

#### Thumbnail service changes

- Added request-local thumbnail URL caching
- Added thumbnail bundle discovery
- Added bundle variant selection
- Added bundle `srcset` generation
- Added progressive picture HTML generation
- Added profiling instrumentation around thumbnail lookups
- Added profiling instrumentation around filesystem checks
- Added map cache invalidation when thumbnail maintenance changes

#### EXIF and map service changes

- Added optional thumbnail generation to `image_map_point()`
- Added map query helper reuse
- Added map availability checks
- Added map payload cache files
- Added map cache fingerprinting
- Added map cache pruning
- Added map cache clearing

#### Front-end module changes

- Added deferred lightbox bootstrap module
- Added teardown support for lightbox, responsive thumbnails, and back-to-top modules
- Added dynamic import for the full lightbox
- Added public content replacement lifecycle handling
- Added cache-busted imports for refreshed modules

#### Public rendering changes

- Gallery and home rendering now start a public render profile for admins.
- Home gallery queries are profiled.
- Gallery image queries are profiled.
- Child gallery lookup is profiled.
- Tag, vote, picture game, grid settings, background, and SEO lookups are profiled.
- Subgallery and image rendering are profiled.
- Visible image cards use thumbnail bundles and progressive picture HTML.
- Hidden lightbox source nodes avoid large preview resolution.
- Gallery map button availability no longer requires full marker payload generation.
- JSON-LD image output is capped to visible content.

### User Impact

#### For visitors

- Large galleries should load faster.
- Initial gallery rendering should feel lighter.
- Photo grids should become interactive sooner.
- Lightbox behavior remains the same when a photo is opened.
- Gallery maps remain available when GPS points exist.
- Offscreen cards may cost less browser rendering work.
- Paginated galleries avoid unnecessary work for non-visible photos.

#### For administrators

- `Add gallery here` is available again in the public hero action bar.
- `Create gallery here` in the gallery editor now opens the create-and-upload workflow.
- New child galleries can be created with photos in one side-panel workflow.
- Empty child galleries can still be created when needed.
- Public gallery refreshes after side-panel actions are more stable.
- Admins get a new public render profile panel for diagnosing slow galleries.
- Thumbnail, filesystem, database, map, and render costs are now visible per request.

### Notes

- The public render profiler is intentionally admin-only.
- Anonymous visitors do not see profiling output.
- The thumbnail bundle cache is request-local only and does not persist thumbnail metadata.
- Gallery map payload cache is persisted under `cache/gallery-maps`.
- Thumbnail maintenance clears gallery map cache files to avoid stale marker thumbnails.
- The full lightbox implementation is preserved, but it is now loaded through the deferred entry point.
- The create-and-upload workflow still allows empty gallery creation when no files are selected.
- The core manifest was refreshed for the updated file set.

## Version 0.63

Version 0.63 is a major public-page admin workflow refinement release focused on replacing bulky inline editing with compact contextual actions, improving side-panel workflows, stabilizing live refresh behavior, and modernizing gallery interaction ergonomics.

This release transforms the public gallery page into a cleaner, more content-focused experience while preserving the existing full admin editing system as a fallback.

### Highlights

#### Public inline editors were replaced with compact contextual admin actions

The old large inline Edit gallery and Edit photo forms were removed from public gallery pages and replaced with compact contextual icon actions.

What changed:

- removed bulky inline public-page edit panels from:
  - gallery cards
  - photo cards
  - gallery hero sections
- added compact pencil edit actions for:
  - galleries
  - photos
  - current gallery hero
- added compact delete/remove actions for:
  - galleries
  - photos
- added confirmation dialogs before CMS removal actions execute
- preserved full-page admin edit routes as fallback entry points
- reused the existing side-panel admin workflow instead of introducing a second editing system
- added accessibility labels and titles for all compact admin actions
- unified the new controls with the translucent glass-style badge design already used by gallery collection indicators

User impact:

- public gallery pages are significantly cleaner
- admin controls no longer dominate gallery content
- editing now feels integrated into the gallery itself instead of layered on top of it
- gallery management is faster and visually lighter

### Side-panel workflows were redesigned into focused actions

The side-panel administration flow was reorganized into clearer, more task-focused workflows.

What changed:

- separated:
  - Upload photos here
  - Create gallery here
  into distinct workflows
- removed the confusing mixed upload/create panel behavior
- upload panels now focus only on uploading into existing galleries
- gallery creation panels now focus only on creating empty galleries
- added a dedicated compact Create gallery icon into the hero action bar
- improved side-panel gallery creation UI with:
  - dedicated identity section
  - cleaner field grouping
  - focused parent selection
  - improved toggles and spacing
- upload side-panels now display:
  - explicit target gallery
  - cleaner upload drop area
  - simplified upload messaging

User impact:

- workflows are easier to understand
- upload and gallery creation are no longer mixed together
- admins make fewer mistakes during nested gallery management
- side-panel interactions feel more intentional and modern

### Public-page editing now fully uses side-panel workflows

Public-page editing was fully integrated into the existing side-panel architecture.

What changed:

- gallery edit icons now open:
  - the existing gallery admin side panel
- photo edit icons now open:
  - the existing photo admin side panel
- panel actions now stay isolated from fullscreen/lightbox behavior
- edit actions now stop event propagation before lightbox handlers execute
- lightbox click handling explicitly ignores:
  - admin edit actions
  - admin delete actions
  - side-panel triggers

User impact:

- clicking photos still opens fullscreen normally
- clicking edit icons now always opens the correct admin panel
- no accidental fullscreen openings during editing
- editing feels faster and more stable

### Dynamic gallery refresh behavior was stabilized

The public refresh pipeline was redesigned to avoid duplicate gallery and photo rendering after edits or uploads.

What changed:

- replaced fragmented refresh logic with a single gallery-frame refresh model
- removed conflicting partial DOM replacement paths
- removed additive update flows that caused duplicate cards after:
  - uploads
  - edits
  - panel saves
- public refreshes now replace the gallery content as a single source of truth
- gallery and photo save handlers now:
  - refresh once
  - avoid local duplicate mutation passes
- hero refresh handling was stabilized during partial updates

User impact:

- edited photos no longer appear twice
- uploaded photos no longer duplicate visually
- gallery updates feel cleaner and more reliable
- panel-based workflows now behave consistently without full reloads

### Hero action bar was modernized into compact icon controls

The gallery hero action bar was redesigned into a cleaner compact icon-based system.

What changed:

- converted:
  - Download gallery
  - Play picture game
  into compact icon buttons
- added compact hero icons for:
  - edit gallery
  - remove gallery
  - create child gallery
- added icon-based visual treatment using:
  - translucent glass surfaces
  - blur effects
  - compact hover states
- unified hero actions with:
  - collection counters
  - subgallery indicators
  - overlay badge styling
- improved icon spacing and responsive sizing
- fixed overlapping hero action positioning
- fixed overlap between:
  - reorder handles
  - compact admin controls
  on smaller gallery cards

User impact:

- the hero area feels dramatically cleaner
- actions remain accessible without dominating the layout
- visual consistency across overlays and controls is improved
- small gallery cards remain usable even with reorder mode active

### Public reorder and compact action overlays were improved

The interaction between drag handles and compact action overlays was refined.

What changed:

- cards with reorder handles now receive dedicated layout handling
- compact edit/delete overlays automatically reposition below reorder handles
- gallery and photo action overlays now avoid collision with:
  - subgallery counters
  - drag handles
  - compact overlay badges
- overlay opacity and hover transitions were refined

User impact:

- reorder mode remains readable on compact cards
- overlay controls no longer visually collide
- gallery card interactions feel more polished

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/public_gallery.php`
- `app/controllers/admin_galleries.php`
- `app/controllers/admin_uploads.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/gallery-modules/lightbox.js`
- `public/assets/styles/public.css`
- `public/assets/styles/side-panel.css`
- `public/assets/styles/utilities.css`

### Notes

- This release intentionally removes the old bulky public inline editing model in favor of compact contextual admin controls.
- Existing full admin edit routes remain fully functional as fallback workflows.
- Public-page administration now primarily uses the side-panel editing system.
- The refresh pipeline was intentionally simplified to avoid duplicate rendering paths and stale fragment conflicts.

## Version 0.62.2

This is a focused admin workflow bugfix release for side-panel gallery creation, upload refreshes, and photo move behavior.

- Fixed side-panel refresh context after creating a new gallery, so the UI now refreshes the correct parent gallery instead of guessing from the newly created gallery URL.
- Fixed create-and-upload refresh behavior for new galleries, including root-level and nested gallery creation.
- Improved the Move to new gallery workflow so admins can explicitly choose the parent gallery for the newly created destination.
- Updated move-to-new-gallery inheritance so the new gallery now inherits visibility, voting, and file-name display settings from the selected parent gallery.
- Fixed AJAX handling for moving photos from the side panel, including move-to-existing-gallery and move-to-new-gallery actions.
- Kept the side panel open after photo move operations, matching the existing dynamic behavior for cover changes and deletions.
- Improved JSON responses for bulk photo moves with source gallery, destination gallery, parent gallery, and refresh URL metadata.
- Fixed public inline gallery visibility controls so public galleries show Unpublish and non-public galleries show Publish.
- Split the large public stylesheet into smaller imported CSS files for base, public, lightbox, admin, side-panel, and utility styling.
- Refreshed the core manifest for the updated file set and stylesheet structure.

## Version 0.62.1

This is just a small bugfix:

- admin panel (right) now correctly reloads panel & gallery view during addin and removing pictures from gallery

## Version 0.62

- Fixed the version number

## Version 0.61B

- Fixed gallery inline editor overflow so the public gallery edit form stays inside the card layout instead of
  spilling under the content on the right.

## Version 0.61

Version 0.61 is a major public UI and UX refinement release focused on modernizing the gallery presentation layer, reducing visual clutter, improving typography consistency, and making public gallery management dramatically more compact and efficient for both visitors and logged-in admins.

This release introduces a redesigned hero layout, modernized gallery cards, compact inline editors, glass-style collection badges for subgalleries, and a broad typography unification pass across the entire public interface.

### Highlights

#### Public gallery hero was completely redesigned

The public gallery hero panel was rebuilt to reduce vertical space usage while preserving visual hierarchy and gallery identity.

What changed:

- redesigned the hero into a compact horizontal layout
- moved gallery actions into a compact top-right action cluster
- reduced oversized stacked button layouts
- reduced vertical whitespace and dead padding
- improved spacing between title, actions, tags, and breadcrumbs
- preserved large visual gallery titles while making the overall panel much smaller
- reorganized hero metadata into:
  - title area
  - actions area
  - tag area
  - breadcrumbs
- improved mobile responsiveness for smaller screens
- added more compact action button sizing
- refined hero spacing and glass-panel rendering
- improved hero typography consistency and heading rhythm

User impact:

- gallery pages feel significantly more modern
- the hero no longer dominates the page vertically
- visitors reach gallery content faster
- actions remain accessible without overwhelming the layout

#### Modernized subgallery card layout

The subgallery section was redesigned into a more modern, compact media-card presentation inspired by contemporary gallery and social-media layouts.

What changed:

- removed the visible `Subgalleries` section heading from public rendering
- redesigned gallery cards into horizontal split layouts
- gallery thumbnails now act as dedicated media surfaces
- gallery metadata was reorganized into cleaner visual blocks
- reduced vertical spacing inside gallery cards
- improved spacing and density for gallery descriptions and tags
- compacted gallery panels and pagination spacing
- refined responsive behavior for mobile gallery cards
- gallery cards now visually align more closely with the admin dashboard styling direction

User impact:

- more galleries fit on screen at once
- gallery browsing feels faster and cleaner
- visual scanning of subgalleries is easier
- the public gallery UI now feels significantly more premium and modern

#### Added glass-style stacked-image collection badges

Subgallery thumbnails now include a modern stacked-image indicator inspired by social-media multi-image overlays.

What changed:

- added a stacked-image collection icon overlay in the top-right corner of subgallery thumbnails
- added live image counts directly into the overlay badge
- implemented glass-style translucent rendering with backdrop blur
- added semi-transparent layered rendering for the stack icon
- integrated the same collection-badge system onto the homepage gallery listing
- removed redundant visible image-count text from gallery cards while preserving it invisibly for SEO and accessibility
- ensured badges honor theme corner-radius settings
- refined badge spacing and compact sizing for mobile layouts

User impact:

- visitors immediately understand which cards contain nested galleries
- the UI feels more visual and less text-heavy
- gallery cards gained clearer visual hierarchy
- repeated "X images" labels no longer clutter the layout

#### Inline public admin editors were redesigned

The logged-in inline editing experience on public pages was modernized and heavily compacted.

What changed:

- redesigned `inline-editor` layouts into compact dashboard-style control cards
- reduced vertical spacing and oversized form layouts
- reorganized edit forms into:
  - content fields
  - option toggles
  - compact action bars
- improved action button hierarchy
- added dedicated destructive-action styling
- refined responsive stacking behavior
- improved edit summaries and contextual helper labels
- aligned inline-editor styling with the newer admin dashboard design language
- improved compact toggle rendering
- improved inline form spacing and typography consistency

User impact:

- public-page editing is significantly faster
- admins can edit content inline without large visual interruptions
- editing tools now feel integrated into the page instead of bolted on

#### Typography was unified across the entire public interface

The public UI received a broad typography consistency pass focused on readability, spacing rhythm, and modern UI alignment.

What changed:

- standardized body typography variables
- standardized heading line-height behavior
- standardized tracking and letter spacing
- unified heading rendering across:
  - hero titles
  - gallery titles
  - buttons
  - forms
  - inline editors
- switched default sans-serif rendering to:
  - Inter
  - system UI fallback stack
- improved text rendering quality using:
  - optimized legibility
  - antialiasing
  - grayscale smoothing
- refined hero heading proportions
- refined brand-title typography
- reduced inconsistent font-height behavior across sections

User impact:

- the interface feels visually coherent
- typography now matches modern application UI expectations
- headings feel cleaner and more balanced
- readability is improved across desktop and mobile layouts

### Public UI Refinements

Additional visual and layout refinements include:

- theme radius settings are now consistently honored by:
  - hero buttons
  - tag pills
  - gallery badges
  - inline editors
  - reorder controls
- tags were redesigned into smaller compact pills
- tag placement was moved into cleaner right-side metadata areas
- public hero buttons now inherit theme colors and styling correctly
- reorder handles were visually corrected and repositioned
- gallery-card positioning behavior was hardened for overlays and floating controls
- hero panel spacing was optimized further after initial redesign feedback

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/public_gallery.php`
- `app/controllers/theme_assets.php`
- `public/assets/styles.css`
- `public/assets/custom.css`
- `custom_css/modern.css`

### Notes

- This release focuses primarily on UX/UI modernization and public-page editing ergonomics.
- The visual redesign intentionally reduces vertical page expansion without removing functionality.
- Existing theme settings and custom CSS compatibility were preserved.

## Version 0.60

This release focuses on major admin workflow improvements, direct public-gallery management, gallery editing UX modernization, and front-end maintainability improvements.

### Highlights

#### Public gallery pages now support direct admin reordering

Logged-in admins can now manage gallery ordering directly from the public gallery view without switching back to the dedicated admin dashboard.

What changed:

- added direct drag-and-drop reordering for subgalleries on public gallery pages
- added direct drag-and-drop reordering for pictures on public gallery pages
- gallery and picture ordering now reuse the existing backend ordering infrastructure
- public gallery reordering respects the existing layout structure:
  - subgalleries remain grouped first
  - pictures remain grouped underneath
- pagination-aware move handling was added so reordering only affects the currently visible page
- public gallery reorder behavior was integrated into the modular front-end gallery operations system

User impact:

- admins can reorganize galleries much faster during normal browsing
- gallery maintenance now feels more natural and less disconnected from the public presentation
- moving galleries and pictures requires fewer page transitions and less context switching

#### Admin gallery editing was redesigned

The gallery editing experience received a broad UI and workflow overhaul focused on clearer structure, faster media management, and modernized interaction patterns.

What changed:

- added a new side-panel editing workflow for gallery administration
- gallery editing panels now preserve active tabs and editing context more reliably
- upload workflows now dynamically refresh edited content without unnecessary full-page reloads
- image management panels now keep their previous state after operations complete
- improved upload progress visibility and automatic viewport positioning during uploads
- upload and media-management interactions were visually reorganized into cleaner grouped layouts
- added direct upload entry points inside gallery editing flows
- improved admin image selection handling and bulk-selection feedback
- added dedicated bulk image move workflows with integrated destination handling
- gallery edit actions now provide clearer visual hierarchy and spacing
- improved gallery move and image move controls with more readable visual states
- refined selected and unselected button states for better accessibility and contrast

User impact:

- media management workflows are faster and easier to understand
- admins spend less time navigating between separate administration screens
- large upload sessions are easier to monitor visually
- gallery editing feels more responsive and modern

#### Admin dashboard and gallery tree interactions were improved

The admin dashboard gained additional structural and interaction refinements for large gallery trees.

What changed:

- gallery tree movement behavior was refined for better nesting clarity
- gallery movement feedback now updates more consistently during drag operations
- public gallery links refresh more reliably after gallery moves
- dashboard interaction states were visually softened and cleaned up
- admin-side panels now use more structured layouts and consistent spacing
- gallery ordering interactions received additional styling and usability refinements

User impact:

- large gallery hierarchies are easier to reorganize
- drag-and-drop workflows feel more predictable
- visual clutter during gallery management was reduced

#### Front-end assets and CSS structure were reorganized

The public and admin front-end assets were cleaned up and reorganized to improve maintainability.

What changed:

- reorganized the main stylesheet into clearly indexed sections
- grouped related UI styles into dedicated structural areas
- improved separation between:
  - public layout styling
  - lightbox styling
  - admin dashboard styling
  - telemetry and maintenance styling
  - gallery ordering styling
  - theme preview styling
- removed older fragmented styling comments and redundant layout markers
- expanded modular JavaScript bootstrapping for new admin and public-gallery features

User impact:

- future UI development is easier to maintain
- styling consistency across admin and public pages is improved
- front-end behavior initialization is more modular and easier to extend

### Technical Notes

Files changed include:

- `public/assets/gallery.js`
- `public/assets/styles.css`
- `public/assets/gallery-modules/admin-bulk-actions.js`
- `public/assets/gallery-modules/admin-operations.js`
- gallery editing templates and related admin UI components

## Version 0.59

This release focuses on telemetry reliability, upload workflow improvements, DNG image support groundwork, and breadcrumb correctness for nested unpublished galleries.

### Highlights

#### Telemetry is now more accurate and exportable

The anonymous telemetry subsystem now tracks public activity more reliably and can generate standalone HTML reports for diagnostics and sharing.

What changed:

- added a downloadable HTML telemetry export report from the admin telemetry page
- telemetry reports now include:
  - anonymous sessions
  - page views
  - photo opens
  - average viewing time
  - browser mix
  - cache-event statistics
  - top viewed photos
  - longest viewed photos
- image transfer statistics now use human-readable byte formatting
- telemetry collection was attached more consistently to public gallery rendering
- added clearer admin guidance explaining why logged-in admins may not see their own telemetry events
- public telemetry collection now uses a more neutral endpoint naming strategy to reduce blocking from privacy filters
- telemetry schema support was expanded for the new `thumb_1280` thumbnail variant

User impact:

- telemetry statistics are more trustworthy during real browsing sessions
- admins can export clean standalone telemetry reports for diagnostics or support
- responsive thumbnail traffic is now represented more accurately in telemetry metrics

#### Breadcrumbs now preserve unpublished gallery hierarchy

Public gallery breadcrumbs now reflect the real gallery structure even when unpublished galleries exist in the parent chain.

What changed:

- breadcrumb generation now walks the true gallery ancestry instead of filtering unpublished ancestors
- unpublished parent galleries remain visible inside the breadcrumb trail for public child galleries
- breadcrumb logic was separated from public gallery-list visibility filtering

User impact:

- nested galleries no longer appear disconnected from their real hierarchy
- direct-linked public galleries inside unpublished branches now show their full structural path
- breadcrumb navigation is more predictable and easier to understand

#### Upload workflow and admin editing were improved

The admin upload flow now behaves more consistently after uploads and reports failed processing more clearly.

What changed:

- upload redirects now preserve the currently active `Media` tab after upload completion
- upload redirects now jump directly to the image-management section
- upload responses now report:
  - failed thumbnail generations
  - failed image scans
  - filenames that could not be processed
- upload-related admin logging was expanded with failed-scan diagnostics

User impact:

- admins stay in the correct editing context after uploads
- failed uploads and processing issues are easier to diagnose
- large upload sessions provide clearer operational feedback

#### DNG support groundwork was added

The gallery now includes safer handling and recognition for Adobe Digital Negative files.

What changed:

- added explicit DNG extension detection helpers
- DNG support now uses dedicated conversion capability checks
- direct public access to raw `.dng` files is blocked through gallery `.htaccess` rules

User impact:

- DNG handling is more explicit and safer
- raw source negatives are less likely to be exposed directly
- future RAW/DNG processing support is easier to extend safely

### Technical Notes

Files changed include:

- `app/controllers/admin_telemetry.php`
- `app/controllers/admin_uploads.php`
- `app/controllers/public_gallery.php`
- `app/services/gallery_lookup.php`
- `app/helpers.php`
- `database/migrations/202605080001_telemetry_thumbnail_variants.php`
- `public/assets/gallery-modules/admin-bulk-actions.js`
- `galleries/.htaccess`

### Notes

- This release continues the telemetry and responsive-thumbnail work introduced in previous versions.
- DNG support currently focuses on safer detection and infrastructure preparation rather than full RAW rendering support.

## Version 0.58

This release brings a broad admin and public-gallery update set:

- gallery access rules were simplified and made more explicit
- anonymous preview was added in the public gallery view
- per-gallery branding assets were added
- the admin dashboard and gallery tree UI were redesigned
- thumbnail handling gained quality and bounds controls
- gallery path handling was hardened for nested moves and clean public URLs
- update/maintenance helpers now cache more expensive checks
- new tests were added for visibility and branding behavior

### Highlights

#### Admin gallery management is much more capable

The admin area now supports more direct tree editing, clearer gallery status display, and a reorganized dashboard layout.

What changed:

- galleries can be nested and re-parented directly from the dashboard tree
- drag-and-drop ordering now supports moving galleries left and right to change nesting
- the gallery tree shows live nesting depth feedback while dragging
- the dragged row and ghost preview are visually softened so the move state is easier to read
- the dashboard tabs and navigation hashes were updated for the new admin layout
- the gallery detail editor was reorganized into clearer sections
- admin actions now use more consistent status messaging and metadata updates

User impact:

- nesting and un-nesting galleries is faster
- the current tree structure is easier to understand while dragging
- the public gallery link in the admin tree updates immediately after a move
- the admin interface feels more structured and less crowded

#### Public gallery access is more explicit

The public gallery flow now understands a clearer gallery visibility model and supports anonymous preview in the admin view.

What changed:

- gallery visibility now uses `public`, `unpublished`, and `private` more consistently
- legacy `draft` and `unlisted` behaviors are normalized during migration
- anonymous preview mode was added for public gallery pages
- public media and gallery access checks were updated to match the new visibility model
- password and NSFW gating continue to work through the public flow

User impact:

- unpublished galleries can be direct-linked but stay out of public listings
- private galleries remain hidden from normal public browsing
- admins can preview the public gallery flow in a more visitor-like mode

#### Per-gallery branding is now supported

The release adds branding assets that can be attached to galleries and to the theme fallback area.

What changed:

- gallery banner uploads were added
- gallery logo uploads were added
- gallery separator uploads were added
- theme-level fallback branding assets were added
- new public asset routes serve the branding files
- admin forms were updated to upload, replace, and remove branding assets
- public gallery rendering now integrates the branding model

User impact:

- galleries can carry their own visual identity
- shared theme branding can provide fallback visuals when a gallery has no custom branding
- branding assets are validated and stored through dedicated upload handling

#### Thumbnail generation now has bounded quality controls

The admin editing flow includes new thumbnail-size and quality-bound support.

What changed:

- thumbnail bounds logic was added as a dedicated service
- migration support was added for thumbnail quality bounds
- the admin editing UI now exposes thumbnail-bound controls
- thumbnail handling is now more explicit about allowed sizing behavior

User impact:

- thumbnail generation is easier to tune
- admin users can better control the visual quality and size boundaries of generated thumbnails

#### Gallery path handling is more robust

The release improves how gallery paths are built and updated.

What changed:

- gallery URL regeneration now works with cleaner public-path logic
- nested galleries preserve a canonical public path
- client-side admin tree updates now refresh gallery links immediately after a move
- URL-safe segments are preserved when gallery names include spaces or accented characters
- filesystem move logic and public URL logic were both hardened

User impact:

- moved galleries keep their public links aligned without a refresh
- nested gallery URLs stay clean and stable
- admin users get immediate feedback after tree changes

#### Maintenance and dashboard performance were improved

Several expensive admin tasks were optimized or cached.

What changed:

- dashboard gallery rows now use more explicit SQL and pre-aggregated counts
- thumbnail maintenance summaries are cached
- update-check navigation data is cached
- app settings now support cache-aware deletion
- public gallery lookup and sidecar refresh flows were streamlined

User impact:

- the admin dashboard should load faster
- thumbnail maintenance and update checks should feel less expensive
- gallery refresh operations are more predictable

### Detailed Change Areas

#### Admin dashboard and gallery tree

Files:

- `app/controllers/admin_dashboard.php`
- `app/controllers/admin_galleries.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/styles.css`

Notable updates:

- redesigned admin dashboard sections and tabs
- gallery tree drag-and-drop with nesting support
- immediate public-link refresh after tree changes
- live placeholder hints for move direction and nesting depth
- lighter drag preview styling
- improved visibility and status labels
- better mobile and compact-layout support in the admin CSS

#### Public gallery and access logic

Files:

- `app/controllers/public_gallery.php`
- `app/controllers/public_media.php`
- `app/services/gallery_access.php`
- `app/services/public_paths.php`
- `app/helpers.php`

Notable updates:

- new anonymous preview behavior
- revised gallery visibility model
- public gallery rendering now understands updated visibility and branding logic
- media and branding routes were added for public gallery assets
- public path regeneration and lookup rules were improved

#### Branding

Files:

- `app/services/gallery_branding.php`
- `app/services/uploads.php`
- `app/controllers/admin_theme.php`
- `app/controllers/theme_assets.php`
- `app/controllers/public_media.php`
- `public/assets/gallery-modules/theme-form.js`
- `public/assets/styles.css`

Notable updates:

- gallery-level branding assets: banner, logo, separator
- theme fallback branding assets
- upload validation and MIME checks
- public serving routes for branding assets
- admin-side preview and editing controls

#### Thumbnail quality and size controls

Files:

- `app/services/thumbnail_bounds.php`
- `database/migrations/202605070003_thumbnail_quality_bounds.php`
- `app/controllers/admin_galleries.php`
- `public/assets/gallery-modules/theme-form.js`
- `public/assets/styles.css`

Notable updates:

- new backend logic for thumbnail bounds
- migration support for stored bounds settings
- admin UI controls for editing thumbnail limits

#### Sidecars, paths, and storage handling

Files:

- `app/services/gallery_paths.php`
- `app/services/gallery_sidecars.php`
- `app/services/gallery_mutations.php`
- `app/services/gallery_lookup.php`
- `app/services/thumbnails.php`
- `app/services/updates.php`

Notable updates:

- more resilient path handling for future destinations and nested moves
- sidecar regeneration now carries more metadata
- thumbnail and update maintenance use cached summaries
- gallery mutations better preserve filesystem and database consistency

### Migrations

Files:

- `database/migrations/202604270001_initial_schema.php`
- `database/migrations/202605070001_gallery_visibility_model.php`
- `database/migrations/202605070002_gallery_branding_assets.php`
- `database/migrations/202605070003_thumbnail_quality_bounds.php`

Migration summary:

- gallery visibility defaults were aligned with the new `unpublished` model
- legacy visibility states are converted during upgrade
- gallery branding columns are added to the schema
- thumbnail quality-bound storage is added

### Tests Added

Files:

- `tests/gallery_visibility_model_test.php`
- `tests/gallery_branding_model_test.php`

Coverage added:

- gallery visibility model behavior
- public/unpublished/private behavior
- branding asset validation rules
- MIME/extension handling for gallery branding uploads

### UI and Behavior Changes Users Will Notice

- admin dashboard layout and tabs are reorganized
- gallery tree reordering is more interactive and more readable
- gallery links update immediately after nesting changes
- drag previews are lighter and easier to distinguish
- drag hints now show how many levels a gallery will move
- public gallery preview supports an anonymous-view mode in the admin flow
- galleries can now have custom branding assets
- thumbnails can be governed by new size and quality bounds

### Important Release Notes

- This release changes gallery visibility semantics and requires migration testing.
- Anonymous preview should be verified against real public/private/password/NSFW cases before release.
- Per-gallery branding placement should be checked in a browser on both desktop and mobile widths.
- The admin tree drag-and-drop flow should be smoke-tested after the new link-refresh behavior.
- The new thumbnail bounds feature should be verified against a few representative galleries.

### Suggested Short Release Blurb

If you want a concise announcement version:

> This release adds anonymous preview, gallery branding, and a redesigned admin gallery tree with live nesting feedback. It also improves thumbnail bounds control, public-path handling, and dashboard performance.

### Files Changed

The branch touches 33 files and introduces several new backend services, migrations, admin UI updates, and public-gallery rendering changes.

## Version 0.57

This release is the largest public-facing and administrative step forward since 0.56. It adds richer public gallery presentation, NSFW access control, admin recovery email support, and a complete password reset workflow, while also tightening the generated theme asset pipeline and keeping release metadata in sync.

### Highlights

#### Public gallery presentation

Public gallery pages now show more of the gallery story directly on the card and image surfaces instead of hiding all context behind the detail page.

The latest branch work adds:

- Public photo metadata overlays on gallery cards
- Better display of image titles, descriptions, and tags in the card layout
- Cleaner handling of public-facing metadata for crawlers and social previews
- Gallery and image JSON-LD output that avoids exposing restricted 18+ content

This makes galleries feel more alive at first glance, while also improving how search engines and social platforms understand the content.

#### NSFW Guard

A new NSFW Guard system was added for both galleries and individual photos.

It allows admins to:

- Mark an entire gallery as 18+ content
- Mark individual photos as 18+ content
- Inherit the restriction down through child galleries

For visitors, the public site now:

- Shows a dedicated age confirmation gate when restricted content is encountered
- Remembers the confirmation for the current browser session
- Hides restricted previews, thumbnails, and embedded metadata until access is granted

This is not just a visual warning. The access rules now affect gallery rendering, image access, thumbnails, lightbox sources, and structured metadata.

#### Admin recovery email

Administrator accounts now support a recovery email address.

That email is used for:

- Username-or-email login
- Recovery workflows
- Password reset delivery
- Optional test-email checks from the account settings page

Existing admins are also prompted to add a recovery email in account settings so the new login and recovery flows are fully usable.

#### Password reset workflow

The branch now includes a full password reset flow for admin accounts.

Admins can:

- Request a reset link from the login screen
- Receive the reset email through the configured transport
- Open a one-time reset link
- Set a new password without needing the old one

The reset system also includes:

- A dedicated token table for one-time reset links
- Token expiry handling
- Automatic invalidation of older unused reset tokens for the same user
- Mail diagnostics in the admin log without exposing the full token value

#### Email delivery settings

Password reset delivery is now configurable from the admin account area.

The new settings cover:

- Enable or disable password reset delivery
- Sender email address
- Sender display name
- Token lifetime
- Mail transport selection
- SMTP host, port, encryption, username, and password

This keeps the password reset flow flexible for both shared hosting and self-managed environments.

#### Theme asset and preview sync

Generated theme assets are now tied more tightly to the implementation that produces them.

That means:

- Theme cache keys now include the theme asset controller revision
- The public site is less likely to serve stale generated CSS after a theme-rendering change
- UI radii and generated theme assets stay in sync more reliably

This is the kind of low-level change that matters most when users customize theme styling heavily.

#### Social and SEO preview support

Gallery metadata output was upgraded for modern sharing behavior.

The new behavior includes:

- Stronger Open Graph metadata
- Better Twitter card metadata
- Safer image selection for social previews
- Cache-busted preview URLs so regenerated thumbnails refresh more predictably on crawlers

#### Runtime and schema resilience

The branch also hardens several runtime paths so newer code can coexist more safely with older database state during upgrades.

Notable changes include:

- Safer admin session loading when the `users.email` column has not been migrated yet
- Schema checks for the new NSFW Guard and password reset features
- Branch-aware routing for the new admin forgot-password and reset-password pages
- New migrations for gallery NSFW flags, user email login, and password reset tokens

### What Changed

#### Public site rendering

- Added public photo metadata overlays on gallery cards
- Expanded gallery image cards so titles, descriptions, and tags can be shown inline
- Added age-gate rendering for NSFW galleries and photos
- Restricted thumbnails and media now respect NSFW access state
- Public JSON-LD output now skips restricted content
- Social preview metadata now prefers richer preview images and stronger card metadata

#### Admin authentication and recovery

- Added username-or-email login resolution
- Added admin recovery email storage and editing
- Added a recovery-email reminder notice inside the admin area
- Added a forgot-password page for admin accounts
- Added a reset-password page with one-time token validation
- Added delivery diagnostics for password reset requests and test emails

#### Admin account settings

- Added a dedicated password reset settings panel
- Added support for PHP mail and SMTP configuration
- Added validation for sender address, SMTP host, port, encryption, and reset token lifetime
- Added test-email sending from the account settings screen

#### Gallery access control

- Added gallery-level NSFW inheritance
- Added per-image NSFW flags
- Added session-based 18+ confirmation for anonymous visitors
- Tightened gallery access checks so restricted content is blocked before content, thumbnails, and lightbox sources are emitted
- Kept password-protected gallery behavior working alongside the new NSFW gating

#### Database and migrations

- Added a nullable `users.email` column and unique email index
- Added a `password_reset_tokens` table for selector/token storage
- Added `nsfw_enabled` columns for `galleries` and `images`
- Added supporting indexes for NSFW filtering and reset-token expiry cleanup

#### Theme and assets

- Adjusted generated theme cache keys to account for the theme asset controller revision
- Updated public styling to support the new gallery card metadata overlays and age-gate UI
- Refreshed generated asset references in the manifest

#### Technical notes

- `develop` currently contains 26 changed files relative to `main`
- The branch is centered on feature expansion rather than a small maintenance patch
- The release is suitable for a `v_0.57` tag once you are satisfied with runtime validation on your environment

### User Impact

#### For site visitors

- Gallery cards reveal more useful metadata immediately
- Restricted 18+ content is handled consistently across the public site
- Social sharing previews look richer and more accurate

#### For administrators

- Account recovery is now practical instead of manual
- Admin login is more forgiving because username or email can be used
- Password reset delivery can be configured and tested from the UI
- New schema-based content controls are available for mature content management

### Notes

- Password reset delivery is disabled by default in the example configuration and should only be enabled after the sender address and transport are verified.
- The new recovery flow depends on the `users.email` migration and the `password_reset_tokens` table.
- The NSFW Guard system depends on its new gallery and image columns, so those migrations need to be applied before the new visibility logic is fully active.

## Version 0.56

This changeset focuses on two user-facing improvements:

  - A new public page-width system for themes, including default, wide, custom, and full layouts
  - Faster admin workflow by adding contextual shortcuts from public gallery pages into create/upload screens

### Highlights

#### Theme layout controls

  The Theme editor now supports selecting the public page container width separately from colors, fonts, and background
  settings.

  Available layout modes:

  - Default for the existing standard-width layout
  - Wider for a larger container
  - Custom for a user-defined width between 1024px and 2048px
  - Full width for a near-viewport layout

  The live preview in the admin panel now reflects the selected layout mode, including custom width changes in real
  time.

#### Contextual gallery actions

  Public gallery admin controls now include direct action links:

  - Create a gallery inside the current gallery
  - Upload photos directly into the current gallery

  This reduces navigation steps when managing nested gallery content.

### What Changed

#### Theme system

  - Added persistent theme settings for:
      - theme_page_width
      - theme_page_width_custom
  - Added normalization and validation helpers for page width values
  - Prevented unrelated Theme saves from re-copying the same preset CSS and unintentionally resetting visual overrides
  - Kept page width as a structural preference instead of treating it like a color/font override

#### Public site rendering

  - Public pages now receive a body class that reflects the active width mode
  - Theme-generated CSS now defines the final container widths for:
      - default
      - wide
      - custom
      - full width
  - The public header, main content, and footer all follow the selected layout mode

#### Admin Theme UI

  - Added a new Page width selector to the Theme settings screen
  - Added slider and numeric input controls for custom width
  - Added live preview support for the width controls
  - Preview UI now visually shows the selected width mode in the admin panel

#### Gallery admin workflow

  - Public gallery action panel now links to:
      - Create gallery here
      - Upload photos here
  - New gallery form can preselect a parent gallery from the query string
  - Upload form can preselect a target gallery from the query string
  - Helper notices now confirm which gallery was preselected
  - Gallery select helpers now support a selected option for contextual navigation

#### Frontend polish

  - Adjusted admin preview layout so the live theme panel behaves more reliably inside the page
  - Updated styles to support the new width presets and custom-width control block
  - Manifest hashes were refreshed to reflect the code changes

### User Impact

#### For site visitors

  - Public galleries can now be displayed in narrower, wider, custom, or full-width layouts
  - Layout choice applies consistently across the main page structure

#### For administrators

  - Theme customization is more flexible
  - Live preview better matches the final public layout
  - Creating nested galleries and uploading into a specific gallery takes fewer clicks

### Notes

  - Custom width is clamped server-side and client-side to keep values safe and consistent
  - Page width changes persist independently from color/font overrides
  - Existing theme presets remain compatible

## Version 0.55

This update is a broad admin workflow rebuild focused on three areas: gallery ordering, log tooling, and updater
  hardening. It also adds safer maintenance actions and a larger set of client-side admin behaviors.

### Highlights

  - Gallery ordering is now handled directly from the admin table, including drag-to-reorder and drag-to-nest
    subgalleries.
  - Admin logs gained live filtering and better ordering controls.
  - Thumbnail maintenance now includes a controlled “delete all thumbnails” workflow with extra confirmation safeguards.
  - The updater was made sturdier, with better cleanup of obsolete managed files and cleaner rollback/reinstall flows.
  - The admin UI got a noticeable polish pass for ordering, logs, warnings, and destructive actions.

### Admin Dashboard

  - Gallery rows are now rendered in a deterministic tree order that respects sibling sort_order plus title fallback.
  - A new gallery-order toolbar was added to the All Galleries table.
  - The gallery table now includes a move handle column for drag-based nesting and reordering.
  - The old “ordering” info panel was updated to reflect the new in-table workflow.
  - The media tools card now includes:
      - Create all thumbnails
      - Delete all thumbnails
      - Download all galleries

### Gallery Reordering

  - Added full gallery-tree reordering support in the admin UI.
  - Dragging a gallery now moves its descendants as a unit.
  - Horizontal drag motion changes nesting depth:
      - Move right to nest under a parent
      - Move left to pull back out to a higher level
  - The full flattened tree is submitted to the server for validation and persistence.
  - Server-side checks now reject:
      - Invalid JSON
      - Duplicate gallery IDs
      - Missing galleries or stale tree state
      - Self-parenting
      - Invalid parent references
      - Subgalleries submitted before their parent
  - Parent changes are propagated to the folder structure on disk.
  - Reordering now logs the operation with counts for total galleries and moved folders.
  - The gallery edit screen now includes quick links back to the gallery list and to the public gallery view.

### Image Ordering

  - The image-order toolbar copy now explicitly mentions that filename sorting is available.
  - The image table now has a clickable Name column that sorts photos alphabetically.
  - Sorting by name is saved immediately, just like drag-based ordering.
  - The Name header updates its arrow direction and accessibility label based on the next action.
  - Image rows now carry explicit sortable name data for more reliable client-side ordering.

### Admin Logs

  - Logs now support live filtering without a full page reload.
  - Filter changes can be applied immediately with debounced search input.
  - The log results area updates dynamically with:
      - Row HTML
      - Result count
      - Empty-state copy
      - Current sort direction
  - The time-sort control can now toggle direction cleanly in the live view.
  - The UI now prevents duplicate log-status listeners from being attached repeatedly.
  - Log detail rows now have better structure and readability:
      - Summaries are clearly interactive
      - Metadata is shown in a compact definition-list layout
      - Long request values wrap safely
  - Log table scrolling and header link styling were improved for usability.

### Thumbnail Maintenance

  - Added a new admin action to delete all generated thumbnails.
  - The delete flow includes a browser prompt with a randomly chosen confirmation word.
  - The server still verifies the typed confirmation word, so the action is not dependent only on the browser prompt.
  - Thumbnail deletion is constrained to generated thumbnail cache directories under the gallery root.
  - Original images, database rows, and unrelated files are not touched.
  - A thumbnail inventory fingerprint was added so maintenance warning dismissal can be invalidated when the gallery
    content changes.

### Updater Hardening

  - Update install paths now return richer diagnostics, not just a copied-file count.
  - The updater now tracks removed obsolete managed files during installation.
  - A new clean reinstall flow was added to reinstall the stable branch over the current site.
  - The updater now removes stale generated ZIPs and temporary extraction folders from cache.
  - More file types and directories are now treated as protected from update cleanup, including:
      - .user.ini
      - php.ini
      - robots.txt
      - .well-known
  - Update and restore operations now provide better backup metadata and cleanup reporting.
  - The updater now distinguishes between normal managed paths and a stricter full-clean mode.

### Client-Side Admin Boot

  - New admin actions were wired into the app bootstrap:
      - admin_delete_thumbnails
      - admin_dismiss_thumbnail_notice
      - admin_reorder_galleries
      - admin_log_export
  - The main gallery JavaScript now loads the new admin behavior modules.
  - Cache-busting query strings were added to the admin module imports.

### UI and Styling

  - Added styling for:
      - Thumbnail maintenance notices
      - Gallery drag handles and placeholders
      - Gallery drag ghost previews
      - Log detail blocks
      - Dangerous maintenance buttons
      - Live log state text
  - Destructive admin actions are now visually separated with danger styling.
  - Gallery ordering mode disables text selection and changes the cursor for clearer drag feedback.
  - Image sorting and gallery reordering now have clearer keyboard focus states.

### Technical Notes

  - This change also updates the core manifest, reflecting the new file set and hashes.
  - The commit title matches the scope: “Update and log redone.”

## Version 0.54

- Added a new anonymous telemetry system with admin reporting, privacy controls, retention settings, and maintenance/
    rollup tooling.
  - Expanded the admin log view with richer filtering by status, category, severity, and search, plus contextual log
    details and request IDs.
  - Introduced a separate “Main page gallery grid” configuration so the home page can use its own layout independent of
    gallery pages.
  - Added support for resetting all per-gallery grid overrides, including cleanup of stale gallery.json sidecar data.
  - Updated the public home page and gallery rendering to use the new effective grid settings logic.
  - Added telemetry hooks to the public site so anonymous usage and performance data can be collected when enabled.
  - Improved the theme admin screen with a live visual preview, better organization, and additional controls for the
    homepage grid.
  - Split the front-end into modular assets under public/assets/gallery-modules/, replacing the previous monolithic
    public/assets/gallery.js.
  - Added new UI assets and behavior for lightbox, admin bulk actions, responsive thumbnails, favicon cropping, back-to-
    top, votes, and theme form interactions.
  - Added new maintenance and deployment helpers, including migration and telemetry maintenance scripts.
  - Introduced a number of new database migrations covering telemetry, gallery grid overrides, log observability, public
    URL slugs, background handling, voting, and related schema changes.
  - Updated the core manifest and application bootstrap/controller/service wiring to reflect the new modules and routes.
  - Added or refreshed security and server config files such as .htaccess, cache/public/gallery access rules, and
    install/reset entry points.

## Version 0.53

- Added public pagination for the home gallery list, gallery subgalleries, and photo grids.
  - Added new admin controls for pagination settings: enable/disable, columns per page, and rows per page.
  - Added clean pagination URLs for gallery pages, including route handling for /galleries/{page} and gallery photo/
    subgallery pagination paths.
  - Updated public gallery rendering to slice visible items by page while keeping lightbox navigation aware of the full
    ordered image set.
  - Added hidden lightbox source nodes so fullscreen navigation still works across paginated galleries.
  - Improved responsive thumbnail sizing so the browser selects better image candidates based on the actual rendered
    grid width.
  - Added new pagination styles and grid column classes to support configurable public layouts.
  - Minor bootstrap and service wiring updates to load the new pagination helpers.

## Version 0.52

Version 0.52 changes public photo captions so raw uploaded file names are hidden by default. Galleries now have an explicit file-name display setting, with matching controls in admin editing, inline logged-in editing, and bulk gallery operations.

### File name display privacy

- Added a per-gallery setting for showing uploaded file names. The default is off, so public gallery cards and lightbox metadata no longer show raw uploaded file names when a photo has no custom title.
- Preserved manually entered photo titles. If a photo has a custom title, it is still shown even when uploaded file names are hidden.
- Treated older filename-derived photo titles as file names for display purposes. This prevents existing records such as `IMG_4708` from staying visible after file names are disabled.
- Kept photo descriptions, tags, voting controls, map pins, and lightbox navigation behavior unchanged.

### Admin controls

- Added a direct Edit gallery checkbox named `Show file names`.
- Added the same control to the logged-in inline gallery editor on public gallery pages.
- Added bulk admin actions named `Show file names` and `Hide file names` across selected gallery branches.
- Added an `N` status column in the admin gallery list so galleries with visible file names are easy to identify.
- Added an `N` status column in the Edit gallery image table. A green arrow marks images in galleries where uploaded file names are shown.

### Database and release metadata

- Added a database migration for the new `galleries.show_filenames` column.
- Updated the fresh-install schema so new installations receive the same setting immediately.
- Added a focused gallery display service for file-name schema checks and public title display logic.
- Updated sidecar metadata writing so the file-name display preference is preserved with gallery metadata.

## Version 0.51

Version 0.51 makes update installs safer on long-running PHP processes by clearing cached opcode state after copied files are in place. This helps newly deployed code take effect immediately during beta and normal update installs.

### Update reliability

- Invalidated OPcache after application files are copied during beta installs.
- Invalidated OPcache after application files are copied during standard update installs.

## Version 0.50

Version 0.50 adds drag-based picture sorting inside gallery management. It lets admins reorder photos directly from the gallery view, and updates the related admin upload, image scanning, and front-end assets so the new ordering workflow stays consistent.

### Gallery ordering

- Added drag-and-drop picture sorting to the gallery admin interface.
- Extended the gallery script and styles to support the new ordering interaction.
- Updated admin upload and image scanning behavior to respect picture order changes.
- Refreshed the release manifest for the 0.50 file set.

## Version 0.49

Version 0.49 focuses on the admin zone and updater workflow. It adds a dedicated admin integrity screen, expands gallery administration, improves theme and thumbnail maintenance, and hardens public gallery and media routing. The updater also gains sturdier branch/version handling and safer restore logic.

### Admin zone rework

- Added a dedicated admin integrity controller and screen for manifest and application checks.
- Expanded the admin dashboard with the new integrity entry point and related admin actions.
- Reworked gallery administration into a dedicated controller with clearer gallery mutation handling.
- Tightened admin log, thumbnail, upload, and theme controllers around their specific responsibilities.
- Extended the bootstrap route map to include the new integrity admin route.

### Updater improvements

- Strengthened GitHub version detection and branch handling in the update service.
- Improved release ZIP download, copy, backup, and restore flow for safer update installs.
- Preserved protected local areas such as config, galleries, cache, and custom CSS during update operations.
- Added clearer update status and messaging in the admin update screen.

### Public rendering and media handling

- Expanded public gallery routing and media streaming helpers.
- Added gallery access, cover, background, sidecar, and public path service modules to keep public rendering logic separated and easier to maintain.
- Tightened thumbnail generation and public asset serving paths.
- Improved theme asset generation, including stylesheet and custom CSS handling.

### Installation and maintenance

- Updated installer and setup flow support for the current application structure.
- Added integrity helpers and manifest generation support for release validation.
- Expanded upload and download helpers for safer file handling.

## Version 0.48

Version 0.48 is a major internal architecture release. It restructures the PHP Gallery codebase by splitting the large service and controller files into focused modules, while preserving the existing public function names, route handlers, include contracts, gallery data model, filesystem-first behavior, theme settings, favicon storage, custom CSS handling, and public rendering behavior.

The goal of this release is maintainability, safer future development, and easier debugging. The public application should behave the same as before, but the implementation is now divided into clearer domains instead of concentrating most backend behavior in `app/services.php` and `app/controllers.php`.

### Architecture refactor

- Split the previous monolithic `app/services.php` into focused service modules under `app/services/`.
- Split the previous monolithic `app/controllers.php` into focused controller modules under `app/controllers/`.
- Kept `app/services.php` and `app/controllers.php` as compatibility loaders, so existing bootstrap logic and route dispatch can continue requiring the same files.
- Preserved original global function names and signatures to avoid breaking templates, controllers, migrations, public endpoints, admin forms, and existing internal calls.
- Regenerated `app/core-manifest.json` for the new file layout.
- Verified the refactor with PHP syntax checks and duplicate-function checks during packaging.

### Service modules introduced or expanded

The service layer is now organized into smaller files by responsibility:

- `app/services/logs.php` for admin log persistence, log status handling, and log maintenance helpers.
- `app/services/downloads.php` for gallery ZIP creation, ZIP cache helpers, archive entry collection, and download streaming helpers.
- `app/services/updates.php` for GitHub version checks, release ZIP downloads, beta install and restore helpers, protected-path handling, update copy logic, backup logic, and OPcache invalidation.
- `app/services/picture_game.php` for picture-game availability checks, vote pair selection, pair history, vote recording, and ranking helpers.
- `app/services/tags.php` for tag parsing, tag slugging, tag lookup, entity-tag synchronization, vote totals, current-viewer vote lookups, and tag-based public gallery listings.
- `app/services/exif.php` for EXIF extraction, GPS metadata handling, map eligibility checks, and gallery map data helpers.
- `app/services/gallery_paths.php` for gallery and image filesystem path resolution, root-boundary checks, and safe path handling.
- `app/services/gallery_sidecars.php` for `gallery.json` sidecar metadata, gallery discovery helpers, and empty gallery creation helpers.
- `app/services/gallery_lookup.php` for read-oriented gallery and image database lookup helpers.
- `app/services/public_paths.php` for clean public gallery paths, image slugs, sitemap entries, and public path regeneration helpers.
- `app/services/gallery_mutations.php` for gallery creation, gallery moves, imports, subtree deletion, ancestor creation, and parent synchronization.
- `app/services/image_scanning.php` for filesystem image reconciliation and database indexing.
- `app/services/uploads.php` for upload validation, safe filename handling, gallery image storage, cover uploads, and uploaded-image ID tracking.
- `app/services/thumbnails.php` for thumbnail paths, thumbnail URLs, `srcset` generation, thumbnail maintenance status, GD resizing, Imagick resizing, and thumbnail regeneration helpers.
- `app/services/gallery_covers.php` for gallery cover resolution, collage candidates, cover choices, and sidecar cover application.
- `app/services/gallery_access.php` for password gates, share tokens, visitor access checks, and public gallery listing rules.
- `app/services/favicon.php` for favicon storage paths, favicon asset URLs, uploaded favicon processing, square cropping, PNG resizing, and favicon removal.
- `app/services/custom_css.php` for active custom CSS paths, preset discovery, preset validation, and public custom CSS URL generation.
- `app/services/gallery_backgrounds.php` for global theme background paths, uploaded theme background storage, gallery background source resolution, and public background asset URLs.
- `app/services/theme.php` for theme settings, theme override settings, theme CSS defaults, CSS custom property parsing, font mode detection, and hex color sanitization.
- `app/services/app_settings.php` for DB-backed application settings such as site name, dev mode, and collapsed gallery state.
- `app/services/database_helpers.php` for reusable schema helper logic such as checking whether a database column exists.
- `app/services/download_signatures.php` for gallery ZIP signature calculation.

### Controller modules introduced or expanded

The controller layer is now organized into route and screen modules:

- `app/controllers/admin_logs.php` for admin log pages and log status actions.
- `app/controllers/downloads.php` for public gallery ZIP download routes.
- `app/controllers/updates.php` for the admin update workflow.
- `app/controllers/picture_game.php` for public picture-game rendering and vote submission.
- `app/controllers/tags.php` for tag pages, tag list rendering, direct image voting, and vote form rendering.
- `app/controllers/exif.php` for gallery map JSON output.
- `app/controllers/http_helpers.php` for shared controller response helpers.
- `app/controllers/public_gallery.php` for public gallery rendering routes.
- `app/controllers/public_media.php` for public media and image streaming routes.
- `app/controllers/admin_auth.php` for admin authentication flow.
- `app/controllers/admin_integrity.php` for admin integrity check screens and actions.
- `app/controllers/admin_galleries.php` for admin gallery management actions.
- `app/controllers/admin_uploads.php` for admin upload actions.
- `app/controllers/admin_thumbnails.php` for thumbnail maintenance actions.
- `app/controllers/admin_dashboard.php` for dashboard-related admin rendering.
- `app/controllers/setup.php` for setup and installation-related controller flow.
- `app/controllers/admin_theme.php` for the admin theme screen.
- `app/controllers/theme_assets.php` for generated theme CSS, favicon asset serving, and theme background asset serving.

### Theme, favicon, custom CSS, and background preservation

Version 0.48 includes the final theme-sensitive split, with special care taken to preserve existing runtime behavior:

- Preserved stored theme colors, radius settings, font mode settings, and theme overrides.
- Preserved the active custom CSS file and custom CSS preset discovery.
- Preserved uploaded favicon storage and favicon asset URLs.
- Preserved global theme background storage and gallery background fallback behavior.
- Preserved runtime paths for project-root assets such as `public/assets/styles.css`, `public/assets/custom.css`, `custom_css/`, `cache/favicon/`, and `cache/theme-background/`.
- Fixed module-relative filesystem path resolution during extraction so moved helpers continue resolving files from the project root, not from inside `app/`.
- Kept the loader order explicit so settings helpers, custom CSS helpers, theme helpers, favicon helpers, and background helpers are available before dependent code executes.

### Uploads, thumbnails, media, and gallery core

The larger non-theme split keeps the active gallery behavior intact while separating the implementation into clearer subsystems:

- Upload validation and storage logic now lives in a dedicated upload service.
- Thumbnail generation, thumbnail URL construction, and thumbnail maintenance logic now live in a dedicated thumbnail service.
- Gallery cover logic is separated from general gallery lookup and mutation logic.
- Gallery access control is separated from gallery discovery and rendering logic.
- Filesystem scanning and database reconciliation are separated from upload handling.
- Public media streaming routes are separated from public gallery rendering routes.
- Gallery path calculation, sidecar metadata loading, database lookup helpers, and clean public paths are now separate concerns.

### Admin and maintenance improvements from the refactor

- Admin log handling is now isolated from unrelated controller code.
- Integrity actions are now isolated into their own admin controller.
- Update workflow logic is now split between update services and update controllers.
- Dashboard, upload, gallery-management, thumbnail-maintenance, authentication, setup, and theme admin code now have clearer ownership.
- Future changes to one admin area are less likely to accidentally affect unrelated admin screens.

### Compatibility and behavior notes

- The gallery remains filesystem-first. Gallery folders and image files continue to be the source of truth.
- The database remains the index, metadata, permissions, tags, votes, share links, and settings layer.
- Existing clean public URLs are preserved.
- Existing gallery folders, uploaded images, cached thumbnails, custom CSS files, favicon files, theme background files, and configuration files are not replaced by this refactor.
- Existing admin forms and public routes continue using the same function names and route handlers.
- The refactor is intentionally structural. It does not introduce a new theme model, a new gallery model, or a new upload model.

### Developer impact

This release makes the codebase easier to work on:

- Smaller service files are easier to read and review.
- Controller code is grouped by route family instead of being concentrated in one large file.
- Theme-related behavior is isolated from upload, thumbnail, update, and public rendering code.
- Filesystem path helpers are easier to audit.
- Future features can be added into a relevant module instead of expanding the old monolithic files.
- Regression risk is reduced because each subsystem now has a clearer boundary.

Detailed release notes for PHP Gallery CMS. Versions are listed newest first.

## Version 0.47

Version 0.47 adds the new online gallery setup flow in `setup-gallery.php`. The repository now includes a one-file bootstrap installer that can download the gallery archive, unpack it into the deployment directory, and create the bootstrap lock used to prevent repeat installs.

The setup flow is intentionally minimal and is designed for first deployment on shared hosting:

- Validates the runtime environment before starting
- Downloads the published gallery archive from GitHub
- Extracts the archive safely into the current project directory
- Preserves `config.php` and `setup-gallery.php` while copying the rest of the project
- Writes `cache/bootstrap-installed.lock` after a successful bootstrap

Additional notes:
- The bootstrap installer now has its own lock handling so it cannot be run again once setup is complete
- The release manifest includes the new `setup-gallery.php` file hash



## Version 0.46

Version 0.46 focuses on the first-run installation experience. The installer has been redesigned into a simpler guided flow that avoids exposing technical configuration that the application can safely derive on its own.

The installation process is now split into a clear two-step flow:

Step 1:
- Gallery name
- Database server
- Optional database port
- Database name
- Database username
- Database password

The installer now expects an existing database instead of attempting to create one. This aligns with how most shared hosting environments operate.

The database connection is validated immediately. The user can only continue once the connection is confirmed to work.

Step 2:
- Admin username
- Admin password

The installer clearly distinguishes between database credentials and gallery admin credentials. The admin account is used for logging into the gallery itself and is not related to the database user.

The installer no longer exposes internal configuration such as Base URL, galleries path, or cache paths. These values are now derived automatically using safe defaults:

/galleries
/cache/zips

The detected domain is used automatically and only confirmed, rather than manually configured.

Additional improvements:
- Removal of database creation logic
- Reduced number of installer fields
- Immediate validation of database connectivity
- Admin redirects now land on clean URLs after destructive or expensive actions, so gallery deletes, migrations, and thumbnail regeneration do not leave repeatable action parameters in the address bar.
- Clearer explanation of each step
- Cleaner separation between setup phases
- Automatic redirect after installation with visible fallback link

The installer now follows a simplified flow:
Name the gallery, connect to database, create admin account, start using the application.

This significantly reduces the cognitive load during installation and makes the system usable without requiring technical knowledge of filesystem paths or URL configuration.

## Version 0.45 – Diagnostics, Stability & UX Refinement

### ?? Dev Mode (Admin-only Diagnostics Overlay)
A new internal diagnostics system has been introduced to support performance tuning and preload optimization.

- Added **Dev Mode toggle** in Admin settings (stored in `app_settings`)
- Introduced **real-time diagnostics overlay** inside gallery viewer and fullscreen
- Overlay is **visible only to logged-in admins**
- Designed as a **compact, non-intrusive HUD**, inspired by "stats for nerds"

#### Overlay capabilities:
- Preload system:
 - Current preload radius
 - Active preload queue
 - Preload hits / misses
- Image lifecycle tracking:
 - Loading / ready / error states
 - Thumbnail vs full-resolution usage
- Cache insights:
 - Decoded image count
 - Cache size estimation
 - Eviction tracking
- Memory monitoring:
 - Estimated decoded image memory footprint
 - Browser heap usage (when available via `performance.memory`)
- Rendering performance:
 - Frame timing (recent frame durations)
 - Lightweight graph visualization (canvas-based)
- Network hints:
 - Connection type (if available)
- Viewer context:
 - Current image index
 - Preload window boundaries

This system is intentionally lightweight and runs only when enabled.

---

### ?? Admin UI Adjustments

- **Dev Mode control moved**:
 - Relocated to a less prominent position in Admin panel
 - Now placed lower in the settings flow to avoid distracting standard users
 - Maintains full functionality, just reduced visual priority

---

### ?? Checkbox Styling Improvements

Global checkbox redesign applied across Admin and Upload UI:

- Reduced overall size for better visual balance
- Removed overly large "blocky" appearance
- Improved alignment with surrounding UI elements
- More subtle, modern styling
- Consistent behavior across:
 - Admin settings
 - Upload interface
 - Bulk actions

---

### ?? Minor UX Polishing

- Improved spacing in Admin panels
- Reduced visual noise in configuration sections
- Better hierarchy between primary and secondary settings

---

### ? Internal

- Added dev-mode flag handling in bootstrap layer
- Extended gallery runtime with instrumentation hooks
- Introduced lightweight telemetry collector in `gallery.js`
- No impact on public users or performance when disabled

---

### ? Notes

- Dev Mode is intended strictly for diagnostics and tuning
- Not optimized for production usage visibility
- Some metrics depend on browser support (e.g., memory API)


## Version 0.44

### Major Changes

- Added a system integrity manifest and admin integrity dashboard so core files can be checked for modifications, missing files, and unknown release-surface files.
- Added deployment support for refreshing the integrity manifest automatically before a release upload.
- Added a floating back-to-top button for long gallery and tag listings, with responsive placement for desktop and mobile layouts.
- Reworked gallery uploads so files can be sent in smaller batches and thumbnail generation tracks each uploaded image more reliably.
- Added bulk gallery deletion from the admin gallery table.
- Refined lightbox interaction and focus styling so the image stage behaves more naturally in normal view and the mouse-focused outline boxes are gone.

### Fixes

- Prepared the application metadata for the 0.44 release cycle.

## Version 0.43

### Major Changes

- Reworked gallery and full-site ZIP downloads so generated archives can now include the gallery folder structure and nested subfolders instead of flattening everything into a single-level file list.
- Added ZIP cache expiry handling with a 7-day lifetime for generated downloads. Expired cache files are removed automatically and rebuilt on demand for both per-gallery and all-galleries ZIP exports.
- Updated ZIP signature handling so cached downloads are invalidated more reliably when gallery structure, image metadata, visibility, or update timestamps change.
- Improved the lightbox interaction model so the preview image now acts as the fullscreen toggle, making the primary image area behave more naturally on desktop and mobile.
- Removed the separate "Open original" link from the lightbox toolbar and streamlined the fullscreen controls to match the new stage toggle behavior.
- Reduced lightbox transition timing to make image swaps feel faster and more responsive during navigation and decode swaps.
- Tightened the fullscreen styling for the lightbox so the image stage fills the available area more cleanly and the fullscreen background stays consistently dark.

### Fixes

- Prepared the application metadata for the 0.43 release cycle.
- Kept release and update version detection aligned with the new 0.43 branch metadata.

## Version 0.42

### Major Changes

- Added theme-managed favicon setup with admin upload, square crop preview, generated PNG icon sizes, and public favicon links.

### Fixes

- Prepared the application metadata for the 0.42 release cycle.

## Version 0.41

### Major Changes

- Added slug-based public URLs for files so image pages can use stable, readable paths.
- Optimized routing and public-path handling so gallery and file URLs resolve with less overhead.
- Improved cache handling for public pages and navigation so repeated requests reuse more of the generated state.
- Added database migrations to backfill public URL slugs and clean up older public path records.

### Fixes

- Prepared the application metadata for the 0.41 release cycle.

## Version 0.40

### Major Changes

- Tightened public gallery-card thumbnail selection so previews prefer the 800px variant and only fall back to 300px on very small viewports.
- Fixed responsive gallery-card thumbnail srcsets so they no longer advertise missing sizes that could resolve to full-size media.
- Normalized uploaded gallery cover assets to bounded preview images instead of streaming the original upload directly.

### Fixes

- Prepared the application metadata for the 0.40 release cycle.

## Version 0.39

### Major Changes

- Added a global theme background image upload stored in private cache storage
 instead of gallery media folders.
- Added a background opacity slider in the Theme admin panel, including a live
 percentage display while adjusting the value.
- Added theme-level header title color and gallery title color controls so dark
 backgrounds can keep text readable.
- Added a fallback background source at the theme level and per-gallery
 background source selection in gallery admin.
- Added public background rendering as a base color layer with an optional
 background image layer on top.
- Moved gallery breadcrumbs and action buttons into the hero panel so the
 public gallery header is more compact and aligned.
- Updated the header and hero surfaces to share the rounded-corner theme
 setting while keeping the hero styling translucent over the background.
- Hardened the Leaflet map overlay path so normal public maps and fullscreen
 split maps keep working after dynamic DOM changes and resize/rebuild cycles.
- Added a dedicated public route for serving the stored global theme background
 asset.
- Added a bulk Theme-panel action to reset every gallery background override
 back to the theme background.
- Added a compact `B` indicator to the admin gallery table for galleries that
 have an explicit background override.

### Fixes

- Fixed normal gallery map overlays so they remain visible in public view.
- Fixed the inline-style guard so legitimate Leaflet runtime styles no longer
 trigger the tamper warning path.
- Fixed Leaflet tile sizing and viewport initialization during overlay
 creation.
- Fixed stale map pane errors caused by initializing the map before the
 overlay had finished sizing.
- Fixed the `background_source` gallery column schema so clearing the per-
 gallery setting can store `NULL` and fall back to the theme background.
- Fixed `custom.css` tracking so uploaded custom styles are ignored by Git.

### Notes

- This release continues the theme-backed background model introduced in the
 previous cycle, but now includes a bulk reset flow for gallery overrides and
 a table-level indicator so admins can see which galleries diverge from the
 theme.

## Version 0.38

### Major Changes

- Added a global theme background image upload stored in private cache storage
 instead of gallery media folders.
- Added a background opacity slider in the Theme admin panel, including a live
 percentage display while adjusting the value.
- Added theme-level header title color and gallery title color controls so dark
 backgrounds can keep text readable.
- Added a fallback background source at the theme level and per-gallery
 background source selection in gallery admin.
- Added public background rendering as a base color layer with an optional
 background image layer on top.
- Moved gallery breadcrumbs and action buttons into the hero panel so the
 public gallery header is more compact and aligned.
- Updated the header and hero surfaces to share the rounded-corner theme
 setting while keeping the hero styling translucent over the background.
- Hardened the Leaflet map overlay path so normal public maps and fullscreen
 split maps keep working after dynamic DOM changes and resize/rebuild cycles.
- Added a dedicated public route for serving the stored global theme background
 asset.

### Fixes

- Fixed normal gallery map overlays so they remain visible in public view.
- Fixed the inline-style guard so legitimate Leaflet runtime styles no longer
 trigger the tamper warning path.
- Fixed Leaflet tile sizing and viewport initialization during overlay
 creation.
- Fixed stale map pane errors caused by initializing the map before the
 overlay had finished sizing.
- Fixed the `background_source` gallery column schema so clearing the per-
 gallery setting can store `NULL` and fall back to the theme background.
- Fixed `custom.css` tracking so uploaded custom styles are ignored by Git.

## Version 0.37

### Major Changes

- Added a standalone admin reset entrypoint at `reset.php` so the site can be
 restored to the current stable branch head even when the normal admin update
 page is no longer usable after a broken beta deploy.
- Added a new gallery thumbnail asset path column so uploaded gallery cover
 images can be stored separately from imported gallery photos and served
 through a dedicated public route.
- Kept gallery-card cover rendering responsive by introducing thumbnail
 `srcset`/`sizes` hints and an intermediate 600px size, which lets the browser
 choose a sharper preview for wide cards without forcing the full 800px asset
 everywhere.
- Reduced gallery-page and lightbox overhead by batching per-image tag and vote
 lookups, memoizing request-scoped gallery helpers, and preloading adjacent
 lightbox images for smoother forward and backward viewing.
- Added reverse tag indexes so tag-filter pages and contained-tag lookups scale
 better on larger libraries.

## Version 0.36

### Major Changes

- Prepared the application metadata for the 0.36 release cycle.
- Removed browser-side and app-side reuse from the update check path so the
 admin update page always queries GitHub fresh.
- Added cache-busting request parameters and no-cache request headers to the
 GitHub version and archive fetches used by the updater.
- Kept the fullscreen split map improvements from the previous release:
 persistent map display during fullscreen browsing, restored map pins, and
 the desktop-only split layout for the map panel.
- Kept the non-image cache policy in place so HTML, CSS, JavaScript, JSON, and
 theme CSS do not linger from older gallery states.

## Version 0.35

### Major Changes

- Kept the fullscreen lightbox map split open when browsing to the next or
 previous image in fullscreen, so the map now persists until it is turned off
 or fullscreen mode ends.
- Restored the map pins in the fullscreen split view by reusing the same marker
 icon path as the normal public map overlay and keeping the Leaflet marker
 panes above the tile panes.
- Added a strict non-cache policy for non-image responses so browsers stop
 reusing stale HTML, CSS, JavaScript, JSON, and theme CSS from older gallery
 states.
- Versioned the main public stylesheet URL so updated UI and map code cannot be
 masked by a previously cached `styles.css`.

## Version 0.34

### Major Changes

- Prepared the application metadata for the 0.34 release cycle.

## Version 0.33

### Major Changes

- Prepared the application metadata for the 0.33 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".
- Switched stable rollback to restore the current GitHub branch head directly.

## Version 0.32

### Major Changes

- Prepared the application metadata for the 0.32 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".

## Version 0.31

### Major Changes

- Prepared the application metadata for the 0.31 release cycle.
- Hardened the GitHub update check so the admin update button can detect the
 newest version from both `PATCH_NOTES.md` and `app/bootstrap.php`.
- Made remote version parsing tolerate `v0.31` and `v_0.31` style headings, so
 release notes and tags cannot hide a valid newer version.
- Added explicit update-source reporting on the admin update page to show whether
 the detected GitHub version came from patch notes or the remote bootstrap file.

### Notes

- Upload this release, then push the same files to the GitHub branch used by the
 updater. Older installed copies will then see 0.31 as the newest available
 version even if one of the remote version sources is temporarily stale.

## Version 0.30

### Major Changes

- Made the lightbox fullscreen controls usable on mobile devices as a visible
 overlay action, while keeping desktop keyboard behavior unchanged.
- Simplified the mobile fullscreen path so it now relies on the app's own
 overlay state instead of depending on the browser fullscreen API.
- Added a lightweight debug flag for tracing the lightbox fullscreen toggle
 path during local testing.

## Version 0.29

### Major Changes

- Removed the public tamper-warning overlay so beta installs and normal gallery
 browsing no longer get blocked by the inline-style guard.
- Continued the manual beta updater work so the stable/beta distinction stays
 explicit while rollback remains available from the admin update screen.
- Kept the fullscreen and mobile viewer work aligned with the existing overlay
 model rather than introducing a separate viewer path.

## Version 0.28

### Major Changes

- Expanded the updater to support manual beta installs from a specific Git
 commit hash, with rollback to the last stable backup when beta is active.
- Continued the fullscreen and mobile gallery work with swipe-friendly viewer
 behavior and a CSS fallback path for devices that do not expose native
 fullscreen the same way.
- Kept the release and update UI aligned with the live version state so the
 admin navigation and update screen remain responsive to new releases.

## Version 0.27

### Major Changes

- Improved the public fullscreen overlay so the lightbox HUD, navigation, and
 centered image staging behave more consistently across normal and fullscreen
 states.
- Fixed update-badge detection so new releases are reflected immediately in the
 admin navigation rather than waiting on stale cached state.
- Continued the 0.26 admin polish by keeping migration prompts conditional and
 preserving the compact dashboard feature indicators.

## Version 0.26

### Major Changes

- Refined the public lightbox and fullscreen overlay so navigation and HUD
 controls behave more predictably in both normal and fullscreen states.
- Tightened the admin migration detection and dashboard presentation so pending
 migrations are shown only when needed and use the striped update treatment.
- Continued the gallery voting and picture game cleanup work with stricter
 admin-side state normalization and clearer per-gallery feature handling.

## Version 0.25

### Major Changes

- Added optional per-gallery voting controls with admin-side enable/disable
 support, public UI gating, and preserved vote history when disabled.
- Added admin dashboard bulk actions and compact status columns for gallery
 voting, GPS maps, and picture game settings.
- Added admin-side self-healing for gallery voting/game flag mismatches on
 dashboard load so game-enabled galleries always keep voting enabled.

## Version 0.24

### Major Changes

- Hardened public SEO routing without changing the working gallery navigation:
 - public gallery cards now link to clean `/gallery/{slug}/` URLs as the first
 baby step toward cleaner public navigation
 - other gallery navigation, redirects, forms, and admin links remain on the
 stable query-string route
 - clean `/gallery/{slug}/` URLs are supported and used as canonical URLs
 - nested `/gallery/folder/path/` URLs remain compatibility routes, but they
 are not used as generated public links
 - `/robots.txt` and `/sitemap.xml` now resolve correctly in subfolder installs
 - `base_url = ''` installs now emit root-relative app links instead of
 fragile page-relative links
- Improved crawler metadata:
 - gallery pages emit title, description, canonical, Open Graph, Twitter card,
 and JSON-LD metadata
 - gallery page headings keep a single `<h1>` that matches the resolved gallery
 title
 - image `alt` text falls back from caption metadata to filename to a
 gallery-based fallback
 - `sitemap.xml` lists public, non-protected galleries with absolute URLs
 - `gallery.json` tags can be comma-separated text or a JSON array
 - saved gallery sidecars now include gallery tags

## Version 0.23

### Major Changes

- Added filesystem-first gallery management groundwork:
 - changing a gallery parent now moves the real folder subtree on disk
 - changing the folder name on a gallery edit page renames the real folder
 - all moved descendant gallery `folder_path` values are updated together
 - failed folder moves are logged and leave an admin-visible error
- Added admin gallery creation and upload flows:
 - `Create empty gallery` creates a real empty folder and a gallery row
 - `Upload photos` can upload multiple images into an existing gallery
 - uploads can also create a new gallery folder before storing images
 - upload progress is shown in the browser, followed by existing thumbnail
 batch progress when optimized thumbnails are requested

## Version 0.22

### Major Changes

- The dashboard `Check for new gallery folders` button now also scans all
 already-imported galleries for new or changed direct image files before
 showing the discovery screen.
- Added an admin log entry and on-screen summary for that refresh scan, including
 how many existing galleries were scanned and how many image records changed.
- Added a visible wait indicator for the dashboard refresh scan so large gallery
 checks no longer leave the admin page looking frozen while the request runs.
- Added an admin gallery status filter for drafts, public galleries, and private
 galleries. The bulk `Select displayed galleries` checkbox now only selects
 rows that remain visible after filtering and collapsed tree branches.

## Version 0.21

### Major Changes

- Prepared the application for the next release cycle and kept the updater badge logic aligned with the current installed release.

## Version 0.20

### Major Changes

- Fixed the pending-update indicator on the admin update page so the fresh
 GitHub check updates the same cached state used by the header and dashboard.
- Pending update links and buttons now show `Update(1)` and use the fixed
 warning background, including the primary update action button.
- The updater now checks both allowed GitHub branches and uses the highest
 advertised version, so a stale `main` branch cannot hide a newer `master`
 release.

## Version 0.19

### Major Changes

- The pending-update cache now invalidates when the application version changes,
 so the `Update(1)` badge stays accurate after a release.

## Version 0.18

### Major Changes

- Opened public gallery titles now use a slightly smaller display size while
 still taking the full panel width, so long names fit more comfortably without
 wrapping early on desktop layouts.

## Version 0.17

### Major Changes

- The admin Updates button now switches to a fixed warning style and shows
 `Update(1)` when a newer GitHub version is available.

### Notes

- The pending-update badge uses a cached GitHub check and does not use theme
 colors, so custom CSS and sliders cannot make it look like a normal button.

## Version 0.16

### Major Changes

- Theme controls now read defaults from the active CSS skin, and saved slider
 values override custom CSS through the generated theme stylesheet.
- Added a `Reset to CSS` button on the Theme screen to clear saved color, radius,
 and font overrides while keeping the selected custom CSS skin.
- Added a dedicated Theme color control for the open public gallery panel.

### Notes

- Existing installs with saved theme overrides may need one click on `Reset to
 CSS` to return the sliders to the selected CSS skin defaults.

## Version 0.15

### Major Changes

- Clarified the browser installation flow in the README so setup starts from the
 site root and redirects automatically to the installer when `config.php` is
 missing.

### Notes

- The installer still supports opening `install.php` directly, but that is not
 required for a normal first-time setup.

## Version 0.14

### Major Changes

- Added an admin-only application updater:
 - the Updates page checks GitHub `PATCH_NOTES.md` for a newer version
 - admins can install newer branch archives with one button when PHP `ZipArchive` and outbound HTTPS are available
 - overwritten application files are backed up under `cache/updates/backups`
 - local `config.php`, galleries, cache files, and active custom CSS are left untouched
- Share-link display tokens are encrypted at rest while link validation continues to use token hashes.

### Notes

- The updater does not delete obsolete files from older releases; remove those manually if a future release note asks for it.
- Keep a normal hosting backup before using one-button updates on production sites.

## Version 0.13

### Major Changes

- Added password-protected public galleries:
 - protected galleries can be listed publicly without thumbnails or set as unlisted/direct-link-only
 - protected access is inherited by subgalleries
 - visitors can unlock a protected branch with a gallery password for 10 minutes
 - admins can generate, regenerate, expire, or revoke share links
 - share-link-only galleries are an explicit admin access mode and generate a usable link when saved
 - generated share links use the canonical query route with the gallery id and token so the token cannot resolve to the wrong gallery
 - active share links remain visible in the admin edit form and can be revoked later, with the display token encrypted at rest
 - share links use `page=share&id=...&token=...` so they work without rewrite rules
- Added a follow-up migration for existing v0.13 installs so the persistent share-link token column is applied even when the first v0.13 migration already ran.
- Made configured `http://` base URLs upgrade to `https://` automatically on same-host HTTPS requests, including common reverse-proxy headers, so CSS and JavaScript are not blocked as mixed content after enabling HTTPS.
- Made same-host `base_url` paths self-correct when the configured path does not match the current front-controller path, which helps shared-hosting deployments where `/subdom/name` is an internal folder but the public site is served from the domain root.
- Added progress feedback to the gallery import flow when `Create optimized thumbnails during import` is checked.
- Prevented browser/mobile auto-translation wrappers from triggering the inline-style compromise warning, and marked the gallery document as non-translatable to preserve Czech gallery titles such as `Den 01`.
- Centralized protected-gallery access checks across public gallery pages, thumbnails, original media, downloads, maps, tags, votes, and the picture game.
- Added admin edit controls and dashboard access labels for protected/listed/unlisted galleries.
- Updated the installer and initial schema for the v0.13 protected-gallery fields.

### Notes

- Run database migrations after uploading this version.
- Regenerating a share link immediately invalidates the old link.
- Unlisted protected galleries and their subgalleries are reachable by direct link only.

## Version 0.12

### Major Changes

- Added optional EXIF/GPS map support for gallery branches:
 - image scans extract safe EXIF fields when the PHP EXIF extension is available
 - GPS coordinates are stored separately from the source file and refreshed on rescan
 - admins can enable EXIF GPS maps on a gallery branch, recursively including subgalleries
 - public image cards show a map pin only when the branch allows GPS maps and the photo has GPS coordinates
 - the lightbox shows a map button for GPS-enabled photos
 - gallery pages can open a combined map of all GPS-enabled public photos in the current gallery branch
- Added a migration for EXIF/GPS columns and the recursive gallery map flag.
- Added Leaflet/OpenStreetMap-based map overlays without requiring a paid Google Maps API key.
- Added a JSON gallery-map endpoint for the public gallery page and lightbox controls.

### Notes

- Run database migrations after uploading this version.
- Rescan galleries after migration so existing images receive EXIF/GPS metadata.
- OpenStreetMap public tiles are suitable for light usage and testing. For heavy public traffic, configure a dedicated tile provider later.

## Version 0.11

### Major Changes

- Simplified the browser installer migration runner:
 - `install.php` no longer wraps each migration file in an explicit database transaction
 - migration statements now run directly, which avoids redundant transaction handling during schema setup

### Documentation

- Updated `README.md` and `ARCHITECTURE.md` to describe the installer migration flow accurately

## Version 0.10

### Major Changes

- Added the optional picture comparison game for opt-in gallery branches:
 - pair history prevents the same viewer from seeing the same image pair again in the same gallery game
 - visitors can choose the left or right image with clicks or arrow keys
 - the chosen image receives a normal upvote
 - the game shows global top-picture statistics for the current gallery
 - admins can enable or disable the game across gallery trees
- Added a database-backed admin log:
 - admins can record operational events and failures without server log access
 - the log page supports workflow states, filtering, and bulk status updates
- Hardened migration-aware admin behavior:
 - admin features detect missing schema more safely
 - the dashboard shows a clear migration prompt when the schema is stale
 - admins can run migrations from the dashboard when needed
 - migration attempts and rejected admin actions are logged
- Kept the earlier public-page admin editing, lightbox vote display, custom CSS skins, and site-name configuration in the same release branch

### Documentation

- Updated the public UI documentation for admin log workflow, migration prompts, and picture-game admin flow

## Version 0.9

### Major Changes

- Added public-page admin editing:
 - logged-in admins can edit gallery names and descriptions directly from
 public gallery and subgallery cards
 - logged-in admins can edit photo titles and descriptions directly from public
 image cards
 - inline controls can publish, hide, or remove CMS records without deleting
 the underlying files from disk
 - admins can see draft/private images and subgalleries while browsing public
 gallery pages
- Improved the public lightbox metadata and voting experience:
 - image descriptions are visible in the overlay
 - missing descriptions show a clear `No description.` fallback
 - score is shown as a dedicated badge under the picture
 - up/down vote controls and the current vote indicator are visible under the
 picture metadata
 - keyboard up/down voting updates the overlay vote state
- Made the public site name configurable:
 - the default `Gallery CMS` label can be changed from Admin -> Theme
 - the configured name is used in the header and browser title
 - the home page no longer renders a default `Galleries` hero block when no
 gallery is selected
- Added selectable custom CSS skins:
 - the Theme screen lists `.css` files from `custom_css/`
 - selecting a skin copies it to `public/assets/custom.css`
 - uploaded CSS is still supported and can be reset
 - added a new `modern.css` skin with matching active CSS output
- Added an optional picture comparison game for gallery branches:
 - new galleries are opted out by default
 - admins can enable or disable the game from gallery edit pages
 - admins can bulk-enable or bulk-disable selected galleries and their
 subgalleries from the dashboard
 - picture-game controls stay hidden until the required migration is applied
 - stale databases show an admin-only `Run database migration` prompt instead
 of throwing a fatal error
 - public gallery pages show a `Play picture game` button when enough eligible
 public images exist
- Added side-by-side image voting:
 - two pictures are shown at the same visual height
 - visitors choose the picture they prefer by clicking it
 - left and right arrow keys can select the left or right picture
 - the selected picture receives a normal upvote
 - the non-selected picture receives no vote and is not downvoted
- Added per-viewer pair history:
 - image pairs are normalized so A/B and B/A are treated as the same pair
 - a pair is recorded as soon as it is displayed
 - the same viewer does not see the same pair again in that gallery game
 - when all pairs are depleted, the game shows a completion message
- Added global game statistics:
 - the game page shows the top three pictures for the current gallery game
 - stats are global, not per-user
 - top pictures show game wins and normal score

### Data Model

- Added `galleries.picture_game_enabled`
- Added `picture_game_votes` to store pair display history and selected winners
- Picture-game winners also write into the existing `image_votes` table so game
 choices contribute to normal image scores
- Added schema-readiness checks around picture-game admin controls so upgraded
 code can load before the new migration has been applied

### Documentation

- Added the root `PATCH_NOTES.md` file with backwards release history
- Updated README with the picture game workflow, admin opt-in behavior, voting
 rules, pair depletion behavior, statistics, selectable CSS skins, configurable
 site naming, and admin-run migrations
- Updated architecture notes with the new route, pair-history table, and admin
 workflow additions

## Version 0.8

### Major Changes

- Hardened the first-run installation flow:
 - unconfigured browser requests now redirect to `install.php`
 - `install.php` refuses to run after `config.php` exists
 - `install.php` also refuses to run after `cache/installed.lock` exists
 - successful browser installs write `cache/installed.lock`
 - the setup route self-locks when an administrator already exists
- Added admin account management:
 - logged-in admins can update their username
 - logged-in admins can change their password
 - current password verification is required before account changes
 - username uniqueness is validated
 - password confirmation and minimum length are enforced
 - sessions are regenerated after successful account updates
- Improved public thumbnail quality:
 - public gallery cards now use the `800` thumbnail variant
 - public image previews now use the `800` thumbnail variant
 - gallery cover collages now use the `800` thumbnail variant
 - the `300` thumbnail variant is kept for compact admin table previews
- Refined thumbnail administration:
 - dashboard "Create all thumbnails" now uses the AJAX batch workflow
 - thumbnail progress is shown consistently during long-running jobs
 - created and skipped thumbnail counts remain visible during processing
- Added documented custom CSS examples:
 - `custom_css/css_template.css` provides a commented starter template
 - `custom_css/custom.css` provides a compact admin-oriented example
 - the admin theme page can reset uploaded custom CSS

### Admin Workflow

- Added an `Account` navigation link for logged-in admins
- Added `page=admin_account` for authenticated account updates
- Made the admin gallery table denser for large gallery trees:
 - smaller table text
 - tighter cell padding
 - smaller checkboxes
 - smaller tree toggles
 - compact Edit and Thumbs row actions
- Reworked the dashboard "Create all thumbnails" control so it selects all
 galleries and runs the same thumbnail action used by the bulk form
- Kept gallery row and gallery edit thumbnail buttons available as normal form
 submissions when JavaScript is unavailable

### Security

- Prevented accidental installer reuse after setup by treating `config.php` as a
 hard installer lock
- Kept `cache/installed.lock` as a second installer lock signal
- Prevented the setup endpoint from replacing an existing admin account after an
 administrator has already been created
- Preserved the existing CSRF, session, and media visibility protections from
 previous releases

### Theme And Assets

- Added a custom CSS reset action to the Theme admin screen
- Continued loading uploaded custom CSS from `public/assets/custom.css`
- Added cache busting for `public/assets/gallery.js` using the file modified time
- Added `/galleries/` to `.gitignore` so local gallery media is not accidentally
 committed

### Documentation

- Updated `README.md` with the automatic installer lock behavior
- Updated deployment notes to explain that deleting or blocking `install.php` is
 now optional defense in depth
- Updated `ARCHITECTURE.md` to describe the first-run installer lock model
- Updated thumbnail documentation so public `800` thumbnails and admin `300`
 thumbnails are described accurately
- Documented the `custom_css/` example folder and its relationship to uploaded
 `public/assets/custom.css`

## Version 0.7

### Major Changes

- Added a generated thumbnail pipeline:
 - thumbnails are created inside each gallery folder under `thumbs/`
 - generated thumbnails are progressive JPEG files
 - supported sizes are `300` and `800`
 - stale thumbnails are rebuilt only when the source image is newer
 - up-to-date thumbnails are counted as skipped
- Added a protected thumbnail route:
 - `page=thumb&id=...&size=...` streams generated thumbnails
 - thumbnail access uses the same gallery and image visibility checks as media
 - missing thumbnails fall back through the normal media route where applicable
- Added AJAX thumbnail jobs:
 - thumbnail forms progressively enhance to batch requests
 - progress includes total images, processed images, created files, skipped files,
 and completion state
 - import, dashboard, gallery, and image workflows can create thumbnails
- Hardened public and admin requests:
 - CSRF protection was added to voting and admin actions
 - anonymous vote rate limiting was added per image
 - session cookies now use `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS
 - global security headers were added
 - media MIME checks use `finfo`
 - media responses include stricter content headers
- Overhauled the admin gallery tree:
 - gallery rows can be collapsed and expanded
 - collapse state is saved in `app_settings`
 - `Collapse all` and `Expand all` controls were added
 - gallery and image select-all checkboxes were added

### Public Gallery Experience

- Public gallery cards now render thumbnail-backed images instead of originals
- Gallery image previews use generated thumbnails
- The lightbox was expanded with:
 - image counter
 - link to the original protected media route
 - keyboard help text
 - integrated voting controls
 - keyboard voting with up and down arrows
- Vote buttons were changed to compact icon-style arrows
- Inline-style tamper detection remains strict so visual changes go through theme
 settings or custom CSS

### Media Pipeline

- Media and thumbnails are served through controlled application routes
- Public media continues to respect gallery and image visibility
- Thumbnail and media responses include caching headers
- ZIP signatures and image scans were updated to work with direct gallery images
 and generated thumbnail folders
- Thumbnail folders are ignored by discovery and scans

### Admin Workflow

- Import can scan images and optionally create thumbnails in one flow
- Bulk gallery actions can create thumbnails
- Gallery edit pages can create thumbnails for all images or selected images
- Admin previews use thumbnails instead of full-size source images
- Gallery tree state persists across admin page reloads

### Documentation

- `README.md` gained detailed thumbnail workflow notes
- `README.md` now lists GD as required for thumbnail creation
- `ARCHITECTURE.md` documents thumbnail routing, AJAX batches, media visibility,
 and admin tree state
- Deployment scripts and notes were updated for the expanded media pipeline

## Version 0.6

### Major Changes

- Added public tag filtering through `page=tag&slug=...`
- Made gallery tags and image tags clickable on public pages
- Included image tags when filtering galleries by tag
- Added inherited `Containing tags` displays:
 - parent galleries aggregate tags from descendant galleries
 - parent galleries also aggregate tags from descendant images
 - top-level gallery cards can expose useful tags even when the top-level folder
 only contains subgalleries
- Added inline-style tamper detection:
 - public JavaScript checks for inline `style` attributes
 - a full-page warning is shown when inline styling is detected
 - theme customization is intentionally routed through theme settings or custom
 CSS

### Tag Workflow

- Tags became navigation controls on public pages
- Public tag pages list galleries connected to a tag through gallery tags or
 image tags
- Gallery cards were restructured so card links and tag links do not create
 invalid nested clickable markup
- Existing tag suggestions remain available in admin tag inputs

### Gallery Hierarchy And UI

- Replaced inline indentation styles in the admin gallery tree with fixed
 `tree-depth-*` classes
- Improved subgallery nesting display without relying on inline CSS
- Continued support for parent galleries and subgallery discovery from earlier
 releases

### Documentation And Code Clarity

- Expanded `README.md` with:
 - tag filtering behavior
 - inherited tag behavior
 - inline-style restrictions
 - naming conventions
 - UI conventions
 - CSS conventions
 - route conventions
 - form conventions
 - documentation expectations
- Expanded `ARCHITECTURE.md` with:
 - tag routes
 - inherited tag aggregation
 - inline-style warning behavior
 - route and request flow notes
- Added docblocks and explanatory comments across PHP, JavaScript, and CSS

## Version 0.5

### Major Changes

- Expanded project documentation substantially in `README.md`
- Documented the application as a plain PHP gallery CMS for shared hosting
- Clarified the supported environment:
 - PHP 8+
 - MySQL or MariaDB
 - PDO MySQL
 - ZipArchive
 - image metadata support through `getimagesize`
 - Apache `.htaccess` support as recommended but not mandatory

### Setup Documentation

- Added clearer manual setup steps:
 - copying `config.example.php`
 - editing database and path settings
 - creating the database
 - running migrations
 - creating the first admin user
- Documented the browser setup route for shared hosting without shell access
- Added local PHP built-in server instructions for root and `public/` web roots

### Usage Documentation

- Documented the gallery folder workflow
- Documented explicit gallery discovery from the admin area
- Documented admin scan and edit steps
- Documented query-string routes for environments without pretty URLs
- Documented ZIP download behavior and cache signatures
- Documented public voting behavior
- Documented FTP deployment and post-upload setup
- Added security notes for protected directories, media routing, escaping, SQL,
 CSRF, and password hashing

## Version 0.4

### Major Changes

- Added the broader plain-PHP application core:
 - routing
 - PDO database access
 - sessions
 - CSRF protection
 - migration runner
 - controller and service layers
- Added a standalone browser installer capable of:
 - creating the database
 - creating or updating the database user
 - writing `config.php`
 - creating writable folders
 - running migrations
 - creating the first admin account
- Added filesystem-backed gallery management:
 - gallery discovery from `galleries_root`
 - imports from filesystem folders
 - image scans
 - nested subgallery support
 - `gallery.json` sidecar metadata
 - ZIP download caching

### Admin Features

- Added an admin dashboard with gallery import and management actions
- Added bulk gallery and image actions
- Added editable gallery metadata:
 - title
 - description
 - slug
 - visibility
 - sort order
 - parent gallery
 - cover image
- Added editable image metadata:
 - title
 - description
 - visibility
 - sort order
- Added tags for galleries and images
- Added tag suggestions in admin forms
- Added theme settings:
 - accent colors
 - page and panel backgrounds
 - corner radius
 - font mode
 - custom CSS upload

### Public Features

- Added public gallery cards
- Added breadcrumb navigation
- Added subgallery display
- Added gallery cover images and cover collages
- Added lightbox browsing with keyboard navigation
- Added public image voting
- Added ZIP downloads for single public galleries
- Added admin download of all imported galleries

### Deployment And Documentation

- Added Apache rewrite and protection files for root, public, cache, and gallery
 directories
- Added FTP and local deployment scripts
- Added deployment exclusions for local-only files
- Expanded `README.md` with release highlights, setup, workflow, deployment, and
 security guidance
- Added `ARCHITECTURE.md` covering request flow, web-root layouts, migrations,
 filesystem rules, and protected directories

## Version 0.3

### Major Changes

- Added first-class nested subgallery behavior:
 - homepage lists only top-level public galleries
 - gallery pages can show direct child subgalleries
 - breadcrumbs show the path through parent galleries
 - parent relationships are synchronized from filesystem paths
- Added gallery title pictures:
 - galleries can choose an explicit cover image
 - galleries can automatically use the first direct image as a cover
 - parent galleries without direct images can show a collage from child gallery
 covers
- Changed scans to import direct images for each gallery instead of recursively
 importing every descendant image into the parent gallery

### Admin Workflow

- Added parent gallery selection on gallery edit pages
- Added title picture selection on gallery edit pages
- Added bulk gallery visibility actions
- Added bulk image visibility actions
- Added bulk action to set an image as the gallery title picture
- Added image previews to the admin gallery edit table
- Added per-gallery scan/import form on gallery edit pages
- Improved dashboard table with parent gallery and folder hierarchy information

### Filesystem And Import Behavior

- Discovery now detects folders that contain descendant images, allowing empty
 parent folders to become gallery records
- Parent galleries are imported before deeper child galleries
- Parent IDs are synchronized after imports
- ZIP creation now includes only direct images owned by each gallery
- Gallery ZIP and all-gallery ZIP signatures ignore descendant images that belong
 to child galleries
- `gallery.json` sidecars can persist cover image paths

### Public UI

- Gallery cards gained image covers and child-cover collages
- Gallery detail pages gained subgallery sections
- Breadcrumb navigation was added to gallery pages
- Public image grids show only direct images from the current gallery

## Version 0.2

### Major Changes

- Added the browser installer as a standalone setup path:
 - database creation
 - database user creation or update
 - `config.php` writing
 - migration execution
 - writable folder creation
 - first admin account creation
- Added database port support:
 - `config.example.php` includes a `database.port` value
 - the PDO DSN appends the configured port when present
- Improved MySQL compatibility:
 - installer supports server default authentication
 - installer supports `caching_sha2_password`
 - installer supports `mysql_native_password`
 - README explains how to handle missing `mysql_native_password`

### Deployment

- Added local deploy folder mode to the PowerShell deployment script
- Kept FTP upload mode available
- Added explicit deployment mode support through `deploy.bat`
- Improved deploy exclusions:
 - `.git`
 - `config.php`
 - cache folders
 - logs
 - temporary files
 - optional gallery media
- Preserved required `.htaccess` files for protected cache and gallery folders

### Documentation

- Added browser installer setup instructions
- Added local Laragon and shared-hosting oriented setup notes
- Added deployment mode examples:
 - `deploy.bat -Mode local`
 - `deploy.bat -Mode ftp`
- Clarified post-upload setup steps

## Version 0.1

### Initial Release

- Added the initial PHP Gallery CMS application structure:
 - root `index.php`
 - `public/index.php`
 - `app/` PHP application files
 - `database/migrations/`
 - `public/assets/`
 - `scripts/`
 - cache and gallery protection files
- Added core routing through `page=...` query-string routes
- Added Apache rewrite support through `.htaccess`
- Added database configuration through `config.example.php`
- Added migration support and initial schema
- Added CLI helpers:
 - `scripts/migrate.php`
 - `scripts/create_admin.php`
 - `scripts/deploy.ps1`

### Gallery Features

- Added filesystem-backed gallery discovery
- Added gallery import and image scanning
- Added gallery metadata storage in the database
- Added image metadata storage in the database
- Added public gallery listings
- Added public gallery detail pages
- Added protected media streaming through the application
- Added public image lightbox browsing
- Added public image voting
- Added ZIP download support with cache records

### Admin Features

- Added admin login and logout
- Added admin dashboard
- Added gallery edit pages
- Added image edit pages
- Added gallery visibility controls
- Added image visibility controls
- Added sort order fields
- Added manual setup route protected by `setup_key`
- Added CSRF helpers for admin forms

### Security And Deployment

- Added password hashing with `password_hash`
- Added prepared-statement based database access with PDO
- Added output escaping helpers
- Added path normalization and path-inside-root checks for gallery files
- Added protection guidance for `config.php`, application folders, cache folders,
 and gallery folders
- Added initial README with setup, workflow, routing, voting, ZIP, deployment, and
 security notes
