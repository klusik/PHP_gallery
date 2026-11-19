<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/schema-query-provenance.md
Module Type: Diagnostic reference
Purpose: Define bounded schema query identity observations in Smart Test runs.
Responsibilities: Record privacy, timing scope and unchanged inspection semantics.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Admin Test Smart schema-query provenance

During an active Admin Test run, the Smart Gallery controller initializes a
private observation immediately before finding the gallery. After rendering
the footer, it attaches the sanitized `schema_query_provenance` component and
removes the private flag. Ordinary requests never initialize this observation;
the inspection owner checks only whether the private observation is an array
and starts no diagnostic clock or collection while disabled.

The existing schema existence-query executor records actual attempts through
its normal PDO/model path or existing test executor seam. Each entry contains
only a bounded object kind, validated table and column/index identifier,
attempt/success/failure counters, and elapsed sum/maximum in milliseconds.
Table attempts omit the unused object identifier. Missing objects returned
normally count as successful reads; thrown inspection queries count as failed.
Recording runs in `finally`, and recording failures cannot replace the original
result or exception. Existing unknown/fail-closed behavior remains unchanged.
No SQL, bound parameters, exception text, database names, credentials or
request values are retained. These observations never authorize an operation.

The diagnostic owner retains at most 256 distinct object identities per
observation. Additional identities increment `dropped_attempts`; already
retained identities continue accumulating. The bound limits memory rather than
execution: no inspection is skipped or changed. The observation uses no new
settings, persistence, schema objects or production dependencies.

This window starts after request bootstrap and its existing bulk prime. It
therefore does not describe all schema work or all SQL in a request. Object
cache/snapshot hits are absent, including checks on already-primed tables.
Bulk snapshots, exact index/column metadata methods and other execution paths
that bypass the existence-query executor are also absent. Ordinary index
existence attempts using this executor are included. Earlier bootstrap and
later shutdown operations are outside the normal successful-render window.
Early-return/error pages do not publish a completed component; their private
request-local observation expires with that request. It is not a whole-request
counter and must not be compared directly with total SQL counts.

The existing schema inspection fixture exercises known, missing and unknown
results, disabled collection, cache-hit exclusion, identifier revalidation,
supported types, private-error exclusion and overflow accounting. No tests or
database execution were run locally for this implementation. Hosted
qualification remains required. The component identifies candidates for later
measurement; it does not demonstrate latency savings or justify eagerly
loading unrelated capability schemas.

No TeX manual impact: this diagnostic-only component changes no public controls,
inspection policies, authorization or administrator operating procedure.
