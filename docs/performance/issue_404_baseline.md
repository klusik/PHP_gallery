<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/issue_404_baseline.md
Module Type: Performance evidence
Purpose: Preserve source-qualified baseline observations for issue 404.
Responsibilities: Distinguish measured cohorts, paired evidence and limitations.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Gallery request optimization baseline

Issue [#404](https://github.com/klusik/PHP_gallery/issues/404) owns the measured
implementation and its progress evidence. The immutable original source is
`c912cf79eaad67ebabced822449e2242f3b404fe`. Preserve this commit and the original
raw measurements when comparing subsequent milestones.

## Cohorts and method

The original local environment is Apache 2.4.66 Win64, PHP 8.3.30
`apache2handler`, MySQL 8.4.3, 512 MiB PHP memory and 4096-byte output buffering.
Web OPcache is absent. The existing installation has 76 galleries and 1,774
images. The Smart Friedrichshafen result has 647 images from seven source
galleries and a first page of 120 images. These observations describe the audit
environment, not installation requirements or production configuration.

Each sequential cohort has 30 completed HTML requests. Report ordinary median
and nearest-rank p95. With 30 samples p99 equals the observed maximum and is not
a reliable population-tail estimate. Source-paired runs alternate five blocks
of six requests per arm and warm up after switching, with the same mount,
configuration and existing data. Retain status, timing, bytes, timestamps,
source identity, arm order and every raw observation. Never persist cookie
values or publish complete diagnostic exports.

| Local cohort | Median TTFB ms | p95 ms |
| --- | ---: | ---: |
| Static text | 1.368 | 1.597 |
| Home | 222.837 | 251.407 |
| Seven-photo physical gallery, measured later with P1 | 223.593 | 248.181 |
| Parent with eleven children and no direct photos | 318.526 | 354.683 |
| Friedrichshafen physical tree | 317.826 | 335.291 |
| Physical gallery with 53 photos | 257.586 | 279.405 |
| Smart, anonymous | 963.323 | 1,036.459 |
| Smart, existing synthetic anonymous session | 968.404 | 1,011.300 |

All requests returned HTTP 200. The physical seven-photo route is unaffected by
the Smart-only P1 change. The original baseline and the later paired control
have different observation times; use the paired control for a causal estimate.

## P1: page thumbnail preload

Smart HTML now calls the existing `thumbnail_bundles_preload($images)` after
authorized pagination/localization and before preparing individual cards. Its
existing bounded source identity and metadata owners remain authoritative. The
lightbox path already uses the same loader. This does not introduce a new cache
layer, authorization decision, SQL owner, route, setting or runtime module.

| Alternating paired arm | n | Median TTFB ms | p95 ms |
| --- | ---: | ---: | ---: |
| Original | 30 | 907.360 | 928.715 |
| P1 preload | 30 | 866.118 | 915.140 |

The paired median improvement is 4.55%. Two diagnostic renders per arm show
foreground SQL 362 to 125 and thumbnail ownership/metadata queries 240 to 3.
The batch still takes approximately 272–288 ms, mostly outside measured SQL
execution. Schema probes remain at 90, included files at 220 and peak memory at
58 MiB. This result does not meet the sub-500 ms target.

The 245 `img/source` tags are identical on all ten checked pages, SHA-256
`612f7e8160c3ee9b1d5b4d76228305ac562835ea87b26c52a7597d629fe1765d`.
The measured prototype controller SHA-256 is
`2e36ed05e9dc91d2976f7595dec0f68bdf129b4693c35590203fb67d20e7680f`.
Existing thumbnail identity, format consistency and public markup/renderer
fixtures own legacy/collision/refusal and both rendering strategies. The first
source commit does not itself establish hosted runtime qualification.

## Separate production observations

The authorized anonymous Ramudareus/Infiltrator sample contains thirty
sequential HTML GETs with two seconds between completed requests: median
811.696 ms, p95 1,638.818 ms, maximum 1,815.033 ms, all HTTP 200. CDN BYPASS and
two Set-Cookie headers were present throughout. Production is read-only.

The earlier authenticated Test export has browser TTFB 1,924.0/4,528.4 ms on
PHP 8.5.12 FPM. Its source SHA is unknown. Its 78 telemetry UPSERTs take
130.131/331.011 ms before response detachment. These distinct cohorts cannot
establish an application speedup or provider-wide capacity limit. Local
telemetry persistence instead adds about 40.6 ms to the measured shutdown tail
without a measurable TTFB change under Apache.

## Retained and rejected evidence

Original raw records, summaries, patches, environment observations and complete
private Test exports remain in the maintainer's local audit artifacts. Issue
comments record new commit-bound measurements and CI links. New runs use unique
identities and never replace the original records.

The P2 lazy filename-pattern memo was reverted: paired median 864.173 to
861.173 ms, about 0.35%, did not justify shipping the extra path. Preserve this
negative result when selecting later work.

No TeX manual impact: P1 only applies the existing documented thumbnail loader
to another authorized page consumer; renderer choices, access policy, user
operations, deployment and runtime ownership remain unchanged. This evidence
document describes the experiment rather than introducing a manual procedure.
