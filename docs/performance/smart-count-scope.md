# Synchronous Smart Gallery grouped-count reuse

The public Smart Gallery page reads raw grouped source counts before its image
count. That immediate grouped result now supplies the count without another
image-result scan. The existing localized source summary and its displayed
total retain their existing behavior; missing source localization or breadcrumb
context must not remove raw matches from the image count.

The source-summary service returns a private `count_proof` containing raw model
groups and a SHA-256 scope digest. The digest covers resolved rules (including
compiler capability markers), the complete unfiltered accessible-gallery ID
list, the public/Admin audience and effective NSFW permission. JSON encoding
failure yields no usable proof. No authorization data is stored in a cache,
session, database or browser. The controller passes the proof directly to the
count owner and removes it before preparing the source-summary view model.

The count owner resolves current semantics before considering the proof. An
exact matching digest permits summing raw counts. A positive source filter must
still belong to the freshly resolved allowed-ID list; its raw group supplies
the count, with a missing group meaning zero. Changed rules, IDs, audience or
NSFW scope decline reuse. Absent or unusable proof keeps the existing model
COUNT with the same filtered allowed-ID scope. Other callers remain unchanged.

This proof is synchronous call-local read data, not a general memo. It must not
be retained or passed across a content, rule or authorization mutation. Existing
Smart request-cache invalidation covers relationships but not every image/tag
mutation, so this implementation deliberately adds no grouped-count cache.
The approved public page consumes the proof immediately between read-only
steps. Concurrent database writes may still change subsequent image-page reads,
as before; no transaction or cross-request snapshot guarantee is introduced.

The expected improvement is removal of one duplicate matching-image scan per
ordinary public Smart Gallery page. Actual latency savings, including whether
they exceed 20 ms on a target host, remain unmeasured. The existing Smart rule
fixture covers equal and changed scopes, allowed/unauthorized source filters,
missing grouped rows, raw groups independent of localized display context,
encoding failure and the retained fallback source contract. Hosted qualification
and real database execution remain separate evidence; no local tests were run.

No TeX manual impact: public metric meanings, controls and operating procedures
are unchanged. This reference documents internal read reuse and its narrow
lifetime contract.
