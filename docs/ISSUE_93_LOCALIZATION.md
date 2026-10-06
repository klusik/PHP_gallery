# Issue 93: effective language and authored metadata

Investigation: 2026-10-06. Source: https://github.com/klusik/PHP_gallery/issues/93.

## Cause and request contract

`translation_bootstrap_request()` already resolves the request language, and
`translation_active_language()` is the authority used by UI `t()` calls. Several
presentation paths passed raw database rows directly to their views, bypassing
the authored-content service. Changing the resolver alone would not fix them.

The content service now defaults to the same effective language. Explicit
language arguments remain supported. Admin session preference precedes the
persisted Admin cookie, with the historical shared cookie as fallback. Public
requests use permitted query selection, public session override, public cookie,
then site-wide `public_language`; `lang=default` clears the visitor override.
The cacheable browser-i18n asset's `lang` is an asset key, not a preference write.
Admin and public preferences remain separate. There is no persisted per-account
language column: personal language persistence is browser-local, not a users-row
setting. Logging in does not make public requests use the Admin language.

## Presentation audit

| Surface | Result |
| --- | --- |
| Public home physical gallery titles/descriptions | Fixed missing batch overlay after pagination. |
| Public gallery and physical subgallery titles/descriptions | Existing shared overlay retained. |
| Image titles/captions, lightbox and lazy metadata | Existing shared overlay and explicit request-language forwarding retained. |
| Breadcrumbs | Fixed raw ancestor and current-gallery labels. |
| Page titles, SEO description, Open Graph, JSON-LD | Existing resolved gallery/image fields retained; fixed social-preview photo captions when a configured cover is outside the selected photo page. |
| Favorite header navigation | Fixed missing overlay after listing policy. |
| Admin gallery workspace and parent labels | Fixed missing overlay after source tree ordering; compact reads include verified source-language metadata. |
| Gallery editor heading | Fixed presentation copy; editable source title/description remain canonical. |
| Image editor | Source fields and separately labeled translation fields remain canonical by design. |
| Picture Game | Fixed gallery heading, photo titles/captions and physical source-gallery labels after eligibility selection. |
| GPS maps / flight map gallery title | Fixed gallery/marker overlays and language-sensitive marker cache identity. |
| Smart Gallery definitions | No localized variant exists for their own authored title/description. Virtual IDs never enter physical translation lookups. Existing translated physical provenance and images remain supported. |
| Public search / progressive and deferred results | Existing active-language queries and batch overlays retained. |

## Intentional source-oriented contexts

Editor source fields, source-language selectors, translation drafts, sidecars,
migration/Trash persistence and exports preserve all canonical source data and
translation rows. Operational gallery pickers/title completion, duplicate-name
checks, filesystem ordering, filenames/paths, audit logs and full administrative
inventory reports use source identity. They do not offer a separate localized
variant to edit or overwrite. EXIF facts, tags, AI indexing metadata and flight
waypoint identifiers have no authored per-language storage.

Gallery fallback remains independent per field. A missing photo translation
falls back to source; a present photo translation retains its intentionally blank
fields. Disabled authored-content capability renders source text while keeping
UI translation active. Reapplying an overlay or changing language restores the
preserved source instead of treating a previous translation as source.

## Regression evidence

`content_request_language_test.php` exercises UI/content agreement, public query,
session and cookie precedence, persisted Admin and legacy cookies, independent
Admin/public scopes, source reset, missing/blank fallback, explicit overrides,
capability OFF, repeated overlays and colliding virtual/physical IDs.
`content_language_workflow_integration_test.php` verifies real rendered home,
nested galleries, Admin fragments/editors, SEO, out-of-page Open Graph/Twitter
preview captions, photo metadata, favorites and map cache language changes
against the owned disposable HTTP/MySQL fixture.
The central audit is the verification entrypoint.
