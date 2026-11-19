<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/route-path-context.md
Module Type: Rejected Performance Experiment Reference
Purpose: Preserve negative evidence for a removed public route-path memo.
Responsibilities: Record source cohorts, paired timings, compatibility failure and retained regressions.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Rejected immediately preceding public image route memo

The single-last-input memo in `image_public_route_path()` was rejected and
removed. It is not part of the resulting implementation. The helper again
computes the route from current image/gallery fields on every call, using its
original normalization and fallback behavior. Earlier batch thumbnail URL
construction and independent schema observations are retained unchanged.

The experimental source compared baseline `8fbef5e1` with candidate `52e7`
(source prefixes from the maintainer's exact-source paired run). The run restored
the original feature checkout. Each cohort contains thirty observations per
arm; all 120 responses preserved identical markup and image order.

| Session cohort | Arm | n | Median TTFB ms | Nearest-rank p95 ms |
| --- | --- | ---: | ---: | ---: |
| Fresh | Baseline 8fbef5e1 | 30 | 481.155 | 512.709 |
| Fresh | Experimental 52e7 | 30 | 485.275 | 534.281 |
| Same synthetic session | Baseline 8fbef5e1 | 30 | 470.917 | 587.335 |
| Same synthetic session | Experimental 52e7 | 30 | 470.299 | 491.211 |

Fresh-session median and p95 worsened. Same-session median was effectively
unchanged; its lower observed p95 does not establish a material median benefit
or sufficient headroom. The extra normalization-context lookup and memo state
are therefore not retained. This is negative evidence, like the rejected P2
filename-pattern memo; it must not be described as a shipped optimization or a
qualified improvement. Original run records remain immutable with the enclosing
performance investigation.

Hosted focused qualification also found a PHP 8.5 compatibility failure in the
experiment: `setlocale(LC_CTYPE, 0)` supplied an integer where PHP 8.5 requires
`array|string|null`. Two URL fixtures failed; PHP 8.1 passed. No cache getter fix
is shipped because the entire cache experiment is rejected. The retained locale
regression uses the portable string query form `setlocale(LC_CTYPE, '0')`.
Removal and this test-only portability change require fresh exact-SHA hosted
qualification; the earlier failed candidate is not successful evidence.

The removed design had compared six projected raw strings: image `url_slug`,
image `filename` (missing/null default `image`), and gallery `url_path`, `slug`,
`folder_path`, `title`. Its single structural slot also fenced LC_CTYPE,
Normalizer availability and mbstring substitution context. It contained no
settings, current user, feature enabled state, authorization decision or URL
host/mount/rewrite/preview/version state. These are historical experiment facts,
not a description of an active cache or a recommendation to reintroduce it.

Useful behavior regressions remain in `public_media_url_rewrite_test.php`:
repeated same input, same-ID changes to all six route fields under active
fallback precedence, intervening different images, and missing/null/empty
filename distinctions. They assert actual paths and remain valid without a
memo. The locale case saves/restores LC_CTYPE and compares an actual supported
alternate locale with the independent existing `slugify()` owner. No alternate
installed locale emits a real SKIP, which cannot qualify a mandatory focused
child as PASS. Host/mount/rewrite/preview/revision matrices remain strict.
`gallery_public_paths_test.php` retains Czech/Unicode/entity normalization
coverage, and the existing runtime environment fixture covers installed
Unicode/ASCII capabilities. No new Normalizer-availability injection is claimed.

No TeX manual impact: this rejected internal experiment changes no public URL
contract, product control or developer invocation/workflow. No thresholds,
assertions, source budgets or required hosted checks are relaxed.
