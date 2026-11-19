<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/domain-schema-primer.md
Module Type: Schema observation reference
Purpose: Describe lazy metadata batching without changing readiness policy.
Responsibilities: Record domain scope, failure fallback and evidence limits.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Optional domain schema snapshots

The existing gallery startup bulk schema snapshot remains unchanged. Additional
optional priming runs only when the existing music catalog, music repository
or public attachment-usage readiness owner is actually called. Each owner uses
its existing table/column requirement map twice: its keys request snapshots,
and the complete unchanged map drives all ordinary readiness checks. There are
no duplicate table lists, new capabilities or eager optional domains. Existing
caller capability gates and OFF laziness remain unchanged.

Successful snapshots let the normal schema inspector answer repeated table and
column existence checks without individual metadata queries. Existing
snapshots are reused and only missing snapshots are requested. The existing
24-table per-call limit is unchanged; these maps remain within it. Exact index
or richer metadata checks keep their existing execution paths. No authorization
result, settings value or native query result is memoized.

`schema_inspection_prime_optional_table_snapshots` owns one request-local
failure latch. A failed bulk attempt, unavailable executor path or exception
disables only further optional snapshot attempts until the existing schema
reset. It never means a table or column is absent. Every readiness owner still
runs all its existing per-object checks; their normal available/missing/unknown
semantics and fail-closed policy remain authoritative. The original startup
primer is independent of this latch. Schema reset and the existing test
executor reset clear the latch together with schema facts.

This fallback is permanently supported by the schema owner because supported
libraries, test seams and database environments can lack bulk metadata access.
No readiness may depend on this acceleration succeeding. Retirement requires
proof that every supported caller/environment guarantees bulk metadata; usage
prevalence and target-host support remain unknown. A failed attempt cannot
cause retry fan-out across additional optional groups in the same request.

The motivating Admin Test observation recorded 78 post-controller existence
query attempts. Music catalog/repository tables and attachment-usage state/items
made up most of those attempts, alongside already observed settings and other
small domains. Their total recorded SQL time was about 26 ms. This change aims
to replace repeated domain metadata round trips with bounded bulk reads; actual
latency savings remain unmeasured and do not establish 50 ms of headroom.
The existing schema provenance component records remaining existence-query
misses; bulk work is outside its executor and must also be counted in central
SQL evidence. Ordinary anonymous/cohort measurements remain necessary before
claiming production performance improvement.

Existing schema inspection fixtures cover optional failure latching, independent
startup behavior, reset/executor-reset, cached-snapshot success and preserved
available/missing/unknown fallback. The presentation policy and music title
fixtures exercise the actual edited domain owners in all three states through
the existing executor seam. The existing music catalog database fixture remains
the real-engine integration owner. These tests were authored, not run locally;
exact-candidate hosted checks and actual-engine results remain required.

No TeX manual impact: this internal metadata acceleration changes no public
controls, readiness policy, authorization or operating procedure. No schema,
migration, configuration or saved preference changes are introduced.
