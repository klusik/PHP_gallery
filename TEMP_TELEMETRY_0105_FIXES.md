# Telemetry 0.105 repair checkpoint

Author: Rudolf Klusal

Baseline: `php-gallery-deploy(20260920-092837).zip`.
No release/version bump requested. Production data is read-only input; no live
hosting configuration, database rows or filesystem permissions were changed here.

## Implemented

- [x] P0: independent bounded telemetry retention before thumbnail maintenance;
  separate database lock, scheduling, oldest-first cleanup and durable job evidence.
- [x] P0: idempotent completed-day archival checkpoint, interruption recovery,
  delayed-event refresh; refuse failed/malformed checkpoint reads instead of replaying
  partially purged hourly input; strict destructive retention setting reads.
- [x] P0/P1: activation-origin SQL and explicit unavailable state; profiler allowlist
  for daily rollup checks; positive post-load navigation timing and invalid-sample
  exclusion without rewriting old raw events.
- [x] P1: shared gallery/page schema priming, shared cover/background readiness and
  thumbnail metadata columns; query fingerprints ranked by count and failures;
  observed DB coverage, correct new affected-row semantics and DDL table parsing.
- [x] P1: canonical page/photo counts in Complete Overview; preserve and label
  legacy session semantics, observed overdue rows versus configured retention.
- [x] P2: separate unclassified traffic, privacy-safe server media bucket classification,
  phase-specific decoded-cache diagnostics, actual resolved-hit accounting and
  stale-navigation suppression, historical anomalous-session share annotation.
- [x] EN/CS/DE/SV catalogs and compatibility collector updated together.
- [x] New recovery, query, semantic, navigation-timing and cache fixtures registered.
- [x] Central full audit executed; no failed checks. Overall BLOCKED due to
  unavailable integration prerequisites. Core manifest regenerated and checked.

## Remaining scope and external acceptance

- [ ] Quantify the production query-count reduction using fresh volume fingerprints.
  The high `images` SELECT count is not proven to have one cause. No speculative
  image-access/security caching was introduced, and its full optimization remains open.
- [ ] Run live MariaDB maintenance/SQL integration and real browser acceptance on an
  isolated installation with the required extensions, then monitor production slices.
- [ ] Existing live ZIP-cache permissions require hosting-side investigation; this
  patch does not alter or claim to repair them.

Historical anomalous photo-open sessions are annotated, not arbitrarily deleted.
Retention intentionally removes data according to configured ages only when its
bounded worker is invoked. A passed slice with `has_more` is not a completed backlog.
See `docs/TELEMETRY_0105_REPAIR.md` for architecture, deployment and acceptance details.

## Local verification context

PHP 8.4.23 and Node 22.16.0. No production DB or real browser run is implied.
The supplied ZIP was used to construct a local comparison-only Git baseline so
changed-source gates can run; this is not upstream repository/tag history.
No commit, tag, push or release publication was made to the user's repository.

## Final central audit

`php scripts/audit.php --profile=full`, started 2026-09-20 10:20:23 UTC.
Overall **BLOCKED**, not an unconditional PASS. No executed regression failed.

| Suite | Result |
| --- | --- |
| PHP regression | 220 PASS, 0 FAIL, 11 SKIP, 6 BLOCKED |
| Node regression, including ZIP64 and new collector/cache fixtures | 24 PASS |
| WinApp regression | 36 PASS |
| PHP syntax | 862 files PASS |
| JavaScript syntax | 107 files PASS |
| MVC boundaries | PASS, 0 strict violations; 14 advisory review candidates |
| Changed declaration documentation | PASS, 0 findings |
| Changed runtime policy documentation | PASS, 0 findings |
| Admin mutation contracts | PASS |
| Runtime hardening | PASS |
| Chromium integration | 5 SKIP, executable unavailable |

The six blocked PHP fixtures require SQLite, ZIP or GD. Live MySQL/HTTP
and several other environment-dependent fixtures were skipped. Production PHP
8.5.9/MariaDB 10.11.18 was not executed here. The existing whole-source inventory
still reports historical documentation/policy debt; inventory PASS does not mean
that all unrelated legacy declarations were repaired.

`php scripts/generate_manifest.php` and `--check`: **current, 711 managed files**.
The original files retain their source ZIP line endings. Whitespace verification
passes with Git's `cr-at-eol` interpretation for the existing CRLF convention.
The two browser collector files are byte-identical. Baseline hashes, archive
membership, extracted file hashes and refreshed manifest entries are checked
while packaging. No source deletion is required by this patch.

## Acceptance still required

Apply first on an isolated same-schema installation, run the full central audit
with the missing extensions and browser, and exercise a real maintenance slice.
Then run regular bounded cron slices on production and inspect overdue rows,
completed/failed jobs and fresh SQL fingerprint volume. Do not interpret this
source handoff as evidence that the live retention backlog has already cleared.

