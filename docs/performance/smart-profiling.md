<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/smart-profiling.md
Module Type: Diagnostic reference
Purpose: Define the measured scope of Smart render components.
Responsibilities: Separate selected nested spans from complete request evidence.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Smart render profiling

An active Admin Test run starts the existing public render profiler on entry to
the Smart HTML controller, sets its resolved gallery identifier and records one
`public_render_profile` component after footer rendering. The profiler retains
its existing administrator/development-mode gate; the Test run does not enable
development mode or weaken authentication. An unavailable profiler produces its
existing disabled snapshot. No public request parameter enables instrumentation.

`smart_gallery_card_models` measures card preparation.
`thumbnail_metadata_urls` measures verified metadata URL assembly inside the
existing per-bundle span. Timers are inclusive: URL assembly is contained in
bundle preparation, so parent and child elapsed times must not be summed.
The snapshot window includes the diagnostic footer and excludes subsequent
response/shutdown work. Existing Test-run marks separately bracket preload,
source/count/page/localization and before-footer boundaries.

The preload mark and `smart_gallery_card_models` are disjoint controller
phases. The global `thumbnail_bundle` and `thumbnail_metadata_urls` totals can
belong to preload; their presence in the same snapshot does not place them
inside the card-model span. Compare the actual phase boundaries before
attributing repeated work.

The temporary `thumbnail_schema_readiness` span measured only about seven
milliseconds across 482 metadata-capability aggregations. It was removed after
rejecting that cache proposal: the extra call edge pulled the optional profiler
into unrelated metadata/setup/maintenance closures. Readiness keeps its original
direct inspection and the historical diagnostic evidence remains in issue #404.
Card spans stay with their existing controller/profiler owner.

The card children `smart_gallery_card_voting_policy`,
`smart_gallery_card_gps_policy`, `smart_gallery_card_lightbox_attributes` and
`smart_gallery_card_thumbnail` separate repeated source-display policy from
per-image attributes and thumbnail markup. They preserve the existing
short-circuit gates. These children are included in `smart_gallery_card_models`
and must not be added to its total. Timers measure elapsed time in the enabled
diagnostic context; actual anonymous HTTP comparisons remain the acceptance
cohort.

Further card children cover `smart_gallery_card_detail_url`,
`smart_gallery_card_bundle_lookup`, `smart_gallery_card_title`,
`smart_gallery_card_sizes`, `smart_gallery_card_media_url`,
`smart_gallery_card_vote_markup` and `smart_gallery_card_tag_markup`. They
measure the actual reached calls, including argument preparation inside their
callbacks, without moving policy or rendering work into another phase.

Profiler query/filesystem counters cover selected instrumented wrappers only;
zero does not mean the entire request performed no SQL or filesystem work.
The central Test-run SQL observer supplies complete statement observations in
its own defined execution window. Logical completion, response detach, last
shutdown callback and actual worker release remain separate boundaries.

No TeX manual impact for this component addition: existing Test-run controls,
download procedure and authorization are unchanged. The shared developer
comparison workflow is documented separately in all four manual sources.
