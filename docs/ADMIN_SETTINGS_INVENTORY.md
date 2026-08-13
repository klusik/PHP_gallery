# Admin Settings Inventory

This inventory defines canonical ownership for site-wide PHP Gallery settings. The centralized `admin_settings` page is a discovery and safe-editing layer over these owners. It is not a second persistence implementation.

## Source audit

The Settings work audited the requested sources and their effective owners:

- `app/controllers/admin_theme.php`: Theme, public appearance, pagination/grid defaults, hero tags, tag-page presentation, lightbox default, thumbnail renderer, branding, language UI and custom CSS.
- `app/controllers/admin_dashboard.php`: URL rewrite, public search, EXIF/GPS global default, SEO request guard and developer diagnostics. The requested standalone `admin_exif_gps_settings.php`, `admin_public_search_settings.php`, and `admin_seo_guard_settings.php` do not exist in this tree; their route handlers are in this controller.
- `app/controllers/admin_uploads.php`: normal upload preferences and browser-assisted upload settings. Rendering is in `app/views/admin_upload_settings.php`.
- `app/controllers/admin_tags.php`: tag metadata and normalization workflow. Tag-page presentation itself is Theme-owned.
- `app/controllers/admin_auth.php`: account, password-reset mail settings, Google account linking and per-user OpenAI configuration. The requested `admin_account.php` does not exist in this tree; `cms_admin_account()` is here.
- `app/controllers/admin_telemetry.php`: privacy/telemetry controls and retention UI, persisted by `app/services/telemetry_settings.php`.
- `app/controllers/site_maintenance.php`: scheduled maintenance configuration and destructive/runtime actions. The requested `admin_site_maintenance_settings.php` does not exist as a separate file; the route handler is here.
- `app/services/app_settings.php`: generic `app_settings` storage plus focused site-name, URL-rewrite and diagnostics helpers.
- `app/services/theme.php`: Theme defaults and normalization.
- `app/services/pagination.php`: global/home/tag grid normalization and inheritance.
- `app/services/gallery_description_layout.php`: global/tag/per-gallery card-layout normalization and fallback.
- `app/services/public_thumbnail_rendering.php`: `responsive` / `progressive` renderer contract and safe fallback.
- `app/services/gallery_lightbox_mode.php`: `single` / `picture_strip` / `3d_carousel` contract and per-gallery inheritance.
- `app/views/admin_chrome.php`, `app/views/admin_dashboard.php`, `app/views/admin_dashboard_sections.php`: navigation and specialized settings surfaces.
- `app/bootstrap.php`: route registration.

`app/services/admin_settings_registry.php` is now the machine-readable ownership registry for settings surfaced by the central hub. It records stable IDs, canonical keys, ownership, current/default resolvers, validation metadata, migration status, sensitivity and specialized routes.

## Settings search

The central Settings page renders a local Spotlight-style search directly below its title. Its index is generated from every visible registry entry, using the translated setting label, description, section, stable ID and sensitivity classification. Results update while the administrator types; no request, external API or separate search index is involved. Matching is case-insensitive, accent-insensitive and token-based, with label-prefix matches ranked first and a maximum of twelve visible results.

Selecting a result activates the owning Settings section, scrolls to and highlights the exact editable control or summary card, and preserves the existing specialized-page link where applicable. Arrow keys move through results, Enter opens the active result, Escape closes the result list, and the clear control resets the query. Without JavaScript, the normal section tabs, stable URLs and complete Settings content remain available.

The registry also contains an exhaustive discovery-only catalog for global controls that remain on specialist screens. It indexes individual Theme colors, typography, corners, width, GPS-pin presentation, hero-tag behavior, shortcuts, card/grid options, branding, backgrounds, favicons, language import/export, and custom CSS; every browser-upload bound; telemetry collection and retention controls; thumbnail generation and cleanup tools; scheduled-maintenance timing and execution; navigation data; logs, updates, integrity, diagnostics, reports, feature flags, and database operations; plus account, password-reset mail, Google linking, and per-user OpenAI controls. Discovery-only entries appear in search but do not create hundreds of duplicate cards in ordinary Settings sections. Selecting one opens its canonical owner.

Per-gallery and per-image values are intentionally not global registry entries. Their controls depend on the selected gallery or photograph and remain searchable only within those contextual editors. Secret values are never copied into the registry; only a safe setting name and destination are indexed.

## Classification legend

- **Edit**: safe central editing is enabled and the save delegates to the existing canonical service setter.
- **Summary**: current value/source is visible centrally, but mutation stays on the specialized page.
- **Specialized**: status/link only because the workflow is sensitive, destructive, file-backed, user-specific, migration-oriented or otherwise contextual.
- **Revision**: changing the setting through its current canonical Theme workflow bumps `theme_public_content_revision` when the rendered public HTML contract changes.
- **Migration**: `No` means the central hub itself adds no schema requirement. A conditional entry may still depend on an already-existing optional schema before it is editable.

## General

| Canonical key | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `site_name` | `app_settings.php`; Theme | string, trimmed and clamped to 120 chars | `Gallery CMS`; empty central submission falls back to default | no revision; no migration | Edit | Theme Appearance | normal |
| `public_language` | `translations.php`; Theme Language | maintained language code: `en`, `cs`, `de`, `sv` | `en`; unsupported values rejected by canonical language validation; missing keys fall back to English | no revision; no migration | Edit | Theme Language | normal |
| `public_language_selector_enabled` | `translations.php`; shared Theme/Settings viewer-language panel | boolean `0/1` | `1`; missing values preserve the enabled historical behavior | disables public switcher and ignores viewer query/cookie/session overrides; no migration | Edit | Theme Language | normal |
| `public_language_selector_languages` | `translations.php`; shared Theme/Settings viewer-language panel | ordered non-empty JSON subset of `en`, `cs`, `de`, `sv` | all four; malformed/empty persisted values fall back to all, while empty submissions are rejected | limits visitor choices only; Admin/default/pack tools keep all maintained languages; no migration | Edit | Theme Language | normal |
| `url_rewrite_enabled` | `app_settings.php`; Dashboard Maintenance | boolean `0/1` | `1`; generated URLs use non-rewrite fallbacks where compatibility requires | no revision; no migration | Edit | Dashboard Maintenance | operational |
| `public_home_search_enabled` | `public_search.php`; Dashboard Maintenance | boolean `0/1` | `0`; feature flag can force effective disabled | no revision; no migration | Edit only while feature is available | Dashboard Maintenance | normal |

## Public appearance and tag presentation

| Canonical key | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `theme_page_width` | `theme.php`; Theme Appearance | enum `default`, `wide`, `full`, `custom` | `default`; invalid becomes `default` | no revision; no migration | Summary | Theme Appearance | normal |
| `theme_page_width_custom` | `theme.php`; Theme Appearance | integer 1024..2048 px | 1440; malformed/out-of-range normalized | no revision; no migration | Summary | Theme Appearance | normal |
| `theme_gallery_description_layout` | `gallery_description_layout.php`; Theme Layout | enum `vertical`, `horizontal` | `vertical`; invalid normalized to fallback | Revision on change; no migration | Summary | Theme Layout | normal |
| `theme_gallery_count_badge_enabled` | Theme/Layout service; Theme Layout | boolean | enabled by default | no central mutation; no migration | Summary through Theme ownership | Theme Layout | normal |
| `pagination_enabled` | `pagination.php`; Theme Layout | boolean | disabled unless configured | no revision; no migration | Summary | Theme Layout | normal |
| `pagination_columns` | `pagination.php`; Theme Layout | integer 1..12 | 3; invalid uses default | no revision; no migration | Summary | Theme Layout | normal |
| `pagination_rows` | `pagination.php`; Theme Layout | integer 1..50 | 3; invalid uses default | no revision; no migration | Summary | Theme Layout | normal |
| `home_gallery_grid_columns` | `pagination.php`; Theme Layout | integer 1..12 | inherits global column default when absent | no revision; no migration | Summary | Theme Layout | normal |
| `home_gallery_grid_rows` | `pagination.php`; Theme Layout | integer 1..50 | inherits global row default when absent | no revision; no migration | Summary | Theme Layout | normal |
| `tag_page_gallery_grid_columns` | `pagination.php`; Theme Appearance > Gallery tags | integer 1..12 | missing/invalid uses global pagination columns | no revision; no migration | Summary with inherited/configured source | Theme Gallery tags | normal |
| `tag_page_gallery_grid_rows` | `pagination.php`; Theme Appearance > Gallery tags | integer 1..50 | missing/invalid uses global pagination rows | no revision; no migration | Summary with inherited/configured source | Theme Gallery tags | normal |
| `tag_page_gallery_description_layout` | `gallery_description_layout.php`; Theme Appearance > Gallery tags | `vertical` / `horizontal` | missing uses `theme_gallery_description_layout` | no migration; separate from hero-tag settings | Summary with inherited/configured source | Theme Gallery tags | normal |
| `theme_hero_tag_visible_limit` | `theme.php`; Theme Appearance > Gallery tags | integer 1..200 | 20 | Revision on change; no migration | Summary | Theme Gallery tags | normal |
| `theme_hero_tag_display_all` | `theme.php`; Theme Appearance > Gallery tags | boolean | `0` | Revision on change; no migration | Specialized Theme summary | Theme Gallery tags | normal |
| `theme_hero_tag_scrollbar_enabled` | `theme.php`; Theme Appearance > Gallery tags | boolean | `1` | Revision on change; no migration | Specialized Theme summary | Theme Gallery tags | normal |
| `theme_hero_tag_scrollbar_rows` | `theme.php`; Theme Appearance > Gallery tags | integer 1..12 | 5 | Revision on change; no migration | Specialized Theme summary | Theme Gallery tags | normal |
| `theme_hero_tag_sort_mode` | `theme.php`; Theme Appearance > Gallery tags | enum `usage`, `alphabetical` | `usage` | Revision on change; no migration | Summary | Theme Gallery tags | normal |
| `theme_accent`, `theme_accent_dark`, `theme_paper`, `theme_panel`, `theme_gallery_panel`, `theme_header_text`, `theme_hero_text` | `theme.php`; Theme Appearance | validated CSS colors | built-in/custom-CSS-derived Theme defaults | Theme CSS output changes; no migration | Specialized | Theme Appearance | advanced styling |
| `theme_radius` | `theme.php`; Theme Appearance | integer 0..32 | CSS-derived default, normally 16 | Theme CSS output changes; no migration | Specialized | Theme Appearance | advanced styling |
| `theme_font` | `theme.php`; Theme Appearance | `serif` / `sans` | CSS-derived Theme default | Theme CSS output changes; no migration | Specialized | Theme Appearance | advanced styling |

Tag names, slugs, descriptions and usage metadata are not global `app_settings` values. They remain owned by the Tags subsystem and database tables. The central Content section links to `admin_tags` without duplicating that mutation workflow.

## Media and browsing

| Canonical key | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `public_thumbnail_rendering_mode` | `public_thumbnail_rendering.php`; Theme Layout | enum `responsive`, `progressive` | `responsive`; every unsupported value normalizes to `responsive` | Revision on change through the shared renderer service; no migration | Edit | Theme Layout | normal |
| `theme_lightbox_browsing_mode` | `gallery_lightbox_mode.php`; Theme Layout | enum `single`, `picture_strip`, `3d_carousel` | `single`; feature flag can force `single` | Revision on change; global key needs no migration; per-gallery override column is separate | Summary | Theme Layout | normal |
| `exif_gps_maps_default_enabled` | `exif.php`; Dashboard Maintenance | boolean | enabled by default | no revision; central edit only when the existing EXIF/GPS override schema is ready | Edit conditionally | Dashboard Maintenance | location-display preference |
| `theme_gps_pin_enabled` | `theme.php`; Theme Appearance | boolean | `1` | generated Theme UI/CSS behavior; no migration | Specialized | Theme Appearance | normal |
| `theme_gps_pin_background_enabled` | `theme.php`; Theme Appearance | boolean | `1` | no migration | Specialized | Theme Appearance | normal |
| `theme_gps_pin_size` | `theme.php`; Theme Appearance | integer 14..48 | 26 | no migration | Specialized | Theme Appearance | normal |
| `theme_gps_pin_background_size` | `theme.php`; Theme Appearance | integer 0..48 | 22 | no migration | Specialized | Theme Appearance | normal |
| `theme_background_opacity` | `theme.php`; Theme Media/Appearance | integer 0..100 | 65 | affects generated Theme CSS; no migration | Specialized | Theme Media | advanced styling |
| `theme_background_source` | `theme.php`; Theme Media | enum `upload`, `existing`, `collage`, empty | empty/validated | file-backed behavior; no migration | Specialized | Theme Media | file-backed |
| `theme_background_optimized_max_side` | `theme.php`; Theme Media | bounded image dimension via Theme normalizer | 1920 | changing can regenerate optimized background derivative | Specialized | Theme Media | file-backed |
| `theme_branding_separator_width` | `theme.php`; Theme Media | 0 or 160..3840 px | 0 | file presentation; no migration | Specialized | Theme Media | file-backed |
| `theme_branding_separator_height` | `theme.php`; Theme Media | integer 8..512 px | 72 | file presentation; no migration | Specialized | Theme Media | file-backed |
| `theme_branding_separator_stretch` | `theme.php`; Theme Media | boolean | false | file presentation; no migration | Specialized | Theme Media | file-backed |

Uploaded theme backgrounds and branding assets are file-backed resources rather than scalar settings. They are status/link-only in Advanced Settings. Gallery-specific lightbox, description-layout, branding and EXIF/GPS overrides remain per-gallery and are intentionally outside the central global form.

## Uploads and automation

| Canonical key | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `admin_upload_client_format_mode` | `uploads.php`; Upload settings General | enum `server_supported`, `phone_jpeg` | `server_supported` | changes browser picker policy; no migration | Summary | Upload settings General | normal |
| `admin_upload_auto_rename_enabled` | `uploads.php`; Upload settings General | boolean | `1` | affects post-scan rename behavior | Summary | Upload settings General | normal |
| `browser_upload_enabled` | `browser_uploads.php`; Upload settings Browser | boolean | enabled | canonical browser-upload normalizer | Summary | Upload settings Browser | operational |
| `browser_upload_default_worker_count` | `browser_uploads.php`; Upload settings Browser | integer, bounded by max/hard cap | service default 8 | clamped by canonical normalizer | Summary | Upload settings Browser | operational |
| `browser_upload_max_worker_count` | `browser_uploads.php`; Upload settings Browser | integer, bounded by hard cap | hard cap | clamped | Specialized summary | Upload settings Browser | operational |
| `browser_upload_hard_worker_cap` | `browser_uploads.php`; Upload settings Browser | integer 1..32 | 32 | clamped | Specialized summary | Upload settings Browser | operational |
| `browser_upload_batch_size_policy` | `browser_uploads.php`; Upload settings Browser | currently canonical `limit_ratio` policy | `limit_ratio`; unsupported resets to it | no migration | Specialized summary | Upload settings Browser | operational |
| `browser_upload_zip_size_threshold_ratio` | `browser_uploads.php`; Upload settings Browser | bounded ratio defined by service | service default; malformed/out-of-range clamped | no migration | Specialized summary | Upload settings Browser | operational |
| `browser_upload_max_items_per_batch` | `browser_uploads.php`; Upload settings Browser | integer 1..64 | service default 8 | clamped | Summary | Upload settings Browser | operational |
| `browser_upload_max_zip_batch_bytes` | `browser_uploads.php`; Upload settings Browser | bounded byte size | service default, bounded by hard maximum | clamped | Specialized summary | Upload settings Browser | operational |
| `browser_thumbnail_rebuild_source_chunk_bytes` | `browser_thumbnail_rebuild.php`; Upload settings Browser | bounded byte size | 512 MiB service default | controls browser-side thumbnail rebuild source ZIP chunking | Summary | Upload settings Browser | operational |

Gallery-scoped upload automation API keys are not exposed as values. The central Advanced section links to `admin_api_manager`; keys remain secret and are managed by the existing API manager/side-panel workflows.

## Privacy, diagnostics and maintenance

| Canonical key | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `telemetry_enabled` | `telemetry_settings.php`; Telemetry | boolean | `0`; schema-unavailable state remains disabled in the hub | telemetry uses its own `telemetry_settings` table and requires its existing migration | Summary | Telemetry | privacy |
| `telemetry_public_usage_enabled` | `telemetry_settings.php`; Telemetry | boolean | `0`; effective collection also requires master enabled | existing telemetry schema | Summary | Telemetry | privacy |
| `telemetry_performance_enabled` | `telemetry_settings.php`; Telemetry | boolean | `0` | existing telemetry schema | Specialized summary | Telemetry | privacy |
| `telemetry_cache_enabled` | `telemetry_settings.php`; Telemetry | boolean | `1` | existing telemetry schema | Specialized summary | Telemetry | privacy |
| `telemetry_database_enabled` | `telemetry_settings.php`; Telemetry | boolean | `1` | existing telemetry schema | Specialized summary | Telemetry | privacy |
| `telemetry_respect_dnt` | `telemetry_settings.php`; Telemetry | boolean | `1` | existing telemetry schema | Specialized summary | Telemetry | privacy |
| `telemetry_admin_excluded` | `telemetry_settings.php`; Telemetry | boolean | `1` | existing telemetry schema | Specialized summary | Telemetry | privacy |
| `telemetry_max_photo_view_seconds` | `telemetry_settings.php`; Telemetry | effective reader clamps 10..3600 | 900 | existing telemetry schema | Specialized | Telemetry | privacy |
| `telemetry_raw_retention_days` | `telemetry_settings.php`; Telemetry | effective reader clamps 1..90 | 7 | retention maintenance side effect occurs in telemetry maintenance | Specialized | Telemetry | privacy |
| `telemetry_hourly_retention_days` | `telemetry_settings.php`; Telemetry | UI 7..730, service-owned effective normalization | 90 | retention maintenance | Specialized | Telemetry | privacy |
| `telemetry_daily_retention_days` | `telemetry_settings.php`; Telemetry | UI 30..3650, service-owned effective normalization | 730 | retention maintenance | Specialized | Telemetry | privacy |
| `seo_request_guard_enabled` | `seo_request_guard.php`; Dashboard Maintenance | boolean | `1` | affects public request rejection | Summary | Dashboard Maintenance | security |
| `seo_request_guard_logging_enabled` | `seo_request_guard.php`; Dashboard Maintenance | boolean | `1` | controls sampled Admin log writes | Summary | Dashboard Maintenance | security |
| `dev_mode_enabled` | `app_settings.php`; Dashboard Maintenance | boolean | `0` | enables Admin-only diagnostics/instrumentation | Edit | Dashboard Maintenance | diagnostic |
| `site_maintenance_enabled` | `site_maintenance.php`; Dashboard Maintenance | boolean | `1` | controls schedule eligibility | Summary | Dashboard Maintenance | operational |
| `site_maintenance_utc_time` | `site_maintenance.php`; Dashboard Maintenance | normalized `HH:MM` UTC | `00:00` | schedule only | Specialized | Dashboard Maintenance | operational |
| `site_maintenance_batch_size` | `site_maintenance.php`; Dashboard Maintenance | integer 1..50 | 20 | bounds work per internal batch | Specialized | Dashboard Maintenance | operational |
| `site_maintenance_time_budget_seconds` | `site_maintenance.php`; Dashboard Maintenance | integer 3..120 | 20 | bounds one maintenance call | Specialized | Dashboard Maintenance | operational |
| `site_maintenance_request_trigger_enabled` | `site_maintenance.php`; Dashboard Maintenance | boolean | `1` | allows normal requests to schedule due maintenance | Specialized | Dashboard Maintenance | operational |
| `site_maintenance_window_minutes` | `site_maintenance.php`; Dashboard Maintenance | integer 15..1440 | 180 | bounds schedule window | Specialized | Dashboard Maintenance | operational |
| `site_maintenance_token` | `site_maintenance.php`; Dashboard Maintenance | 64 hex chars generated from 32 random bytes | generated/rotated by canonical service | secret, token rotation invalidates old web-cron URL | Specialized, redacted | Dashboard Maintenance | secret |

Maintenance run state, last-result and completion marker settings are runtime state, not administrator preferences. They are intentionally omitted from central editing.

## Account, credentials and advanced resources

| Canonical key / resource | Owner / current Admin location | Type and accepted values | Default and invalid/missing fallback | Side effects / migration | Central | Specialized link | Sensitivity |
|---|---|---|---|---|---|---|---|
| `password_reset_enabled` | `admin_auth.php`; Account | boolean | disabled unless configured | mail behavior; no new Settings migration | Specialized | Account | security |
| `password_reset_transport` | `admin_auth.php`; Account | `php_mail` / SMTP per existing normalizer | existing account fallback | mail behavior | Specialized | Account | security |
| `password_reset_from_email`, `password_reset_from_name` | `admin_auth.php`; Account | validated/normalized mail identity strings | existing account fallback | mail behavior | Specialized | Account | personal/security |
| `password_reset_token_lifetime_minutes` | `admin_auth.php`; Account | integer 15..1440 | existing account default | token lifetime | Specialized | Account | security |
| `password_reset_smtp_host`, `password_reset_smtp_port`, `password_reset_smtp_encryption`, `password_reset_smtp_username` | `admin_auth.php`; Account | SMTP configuration | existing account fallback | outbound mail | Specialized | Account | security |
| `password_reset_smtp_password` | `admin_auth.php`; Account | secret string | never rendered centrally; status only | outbound mail | Specialized, redacted | Account | secret |
| Google linked account/OAuth state | auth services; Account | user/account-specific | specialized service fallback | optional auth schema | Specialized status/link | Account | secret/identity |
| OpenAI user settings/API key | OpenAI service; Account | user-specific model/consent/key settings | disabled/unavailable when feature/schema/key missing | optional user settings schema | Specialized status/link, no key value | Account | secret |
| Upload API keys | upload automation services; API manager | gallery-scoped secrets | dedicated API-key lifecycle | existing upload API schema | Specialized status/link | API manager | secret |
| `custom_css_preset` and uploaded raw CSS | `custom_css.php` / Theme | preset marker plus file-backed CSS | built-in stylesheet when no custom CSS | changes public CSS; file-backed | Specialized status/link | Theme Custom CSS | advanced |
| Theme branding/background files | Theme media services | uploaded image assets | built-in/no asset fallback | filesystem write/derivative generation | Specialized status/link | Theme Media | file-backed |
| Language pack JSON editor | translations/Theme | JSON string catalog | default catalog/fallback language | filesystem write/import/export | Specialized | Theme Language | advanced |
| Database repair, cleanup, migrations, optimize/analyze | database maintenance/migrations | explicit operation, not scalar setting | no automatic central action | potentially destructive/DDL; existing migrations | Specialized | Storage > DB maintenance | destructive |

## Inheritance and override rules

1. Tag landing-page grid settings inherit global pagination dimensions when their dedicated keys are missing.
2. Tag landing-page card layout inherits `theme_gallery_description_layout` when `tag_page_gallery_description_layout` is missing.
3. Hero-tag settings are a separate Theme concern and never substitute for tag landing-page settings.
4. Gallery description layout, lightbox mode and EXIF/GPS display can have per-gallery overrides. The central page displays the global fallback only and never rewrites per-gallery values.
5. `public_thumbnail_rendering_mode` accepts only `responsive` and `progressive`; invalid/missing state falls back to `responsive`.
6. `theme_lightbox_browsing_mode` accepts only `single`, `picture_strip` and `3d_carousel`; invalid state falls back to `single` and the feature flag can force `single`.
7. The viewer-language selector is independent from Admin language and the site-wide public default. Disabling the feature suppresses personal overrides; filtering its languages never removes maintained catalogs from administrative tools.
8. Sensitive resources are represented only as status such as `Configured`, `Not configured` or `Specialized page only`.

## Future registration rule

A new global setting should be added to the registry only after its canonical owner and normalization path are known. Prefer summary-only registration first. Central editing is allowed only when the registry save callback can delegate to the same service setter used by the specialized page, including the same feature/schema guards and side effects. Do not register per-gallery/per-image values, raw secrets, destructive actions or file editors as centrally editable controls.
