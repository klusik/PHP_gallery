<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/thumbnail-context.md
Module Type: Performance reference
Purpose: Describe bounded reuse during thumbnail metadata URL assembly.
Responsibilities: Record ownership, invariants and unqualified measurement scope.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Thumbnail URL assembly context

`thumbnail_metadata_bundle_data()` validates source ownership and durable
renderable rows before collecting the permitted format/width pairs.
`thumbnail_serving_urls()` preserves the legacy/static URL owner. When public
paths are available, `image_public_thumbnail_urls()` resolves the image route,
rewrite policy and source/derivative cache revision once for that synchronous
batch. Complete thumbnail suffixes are built before applying preview markers
and the version query parameter through the shared asset URL decorator.

The prepared values do not survive the call. There is no process-wide cache,
ID-only lookup, HTML cache, filesystem discovery or new database query. Later
calls observe changed image rows, gallery routes, settings and preview context.
Source identity collisions, unknown schema refusals and format selection remain
with their existing owners before URL assembly. A cache revision grants no media
authorization; serving-route checks remain authoritative.

`public_media_url_rewrite_test.php` compares batch URLs with the scalar owner
across query/clean routing, ordinary/signed/anonymous preview contexts, multiple
formats/widths and a changed derivative revision. Existing source-identity
fixtures retain collision and unknown-ownership refusal coverage.

Performance benefit is unqualified until the parent task records paired
measurements on the same local route and unchanged fixture. Static review and
added assertions are not executed test evidence. Hosted qualification belongs
to the central audit; no local test or generator was executed for this change.

No TeX manual impact: this is call-local reuse of existing URL observations;
supported URLs, authorization, administration and deployment remain unchanged.
