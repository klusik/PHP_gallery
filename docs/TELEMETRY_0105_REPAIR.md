# Telemetry repair included in Version 0.105.1

Author: Rudolf Klusal

Source baseline: `php-gallery-deploy(20260920-092837).zip`.
This document is the permanent technical reference for the Version 0.105.1
telemetry repair. It changes no production configuration and requires no new
database migration.

## Independent retention and archival

`telemetry_run_maintenance()` owns a database-scoped, nonblocking named lock,
independent scheduling, bounded deletion batches and a durable daily checkpoint.
`site_maintenance_run()` invokes the scheduled wrapper before the thumbnail
maintenance lock and its time-window/phase checks. Thumbnail failures or a busy
thumbnail worker therefore do not prevent a separately admitted telemetry slice.
This is not a new daemon: the existing scheduled maintenance entrypoint still
has to be invoked regularly. Public gallery requests do not run a retention scan.
Master telemetry OFF remains non-destructive.

Defaults in `app/configuration_defaults.php`:

| Setting | Default | Meaning |
| --- | ---: | --- |
| `telemetry.maintenance_time_budget_seconds` | 3 | Cooperative slice budget |
| `telemetry.maintenance_delete_batch_size` | 2000 | Maximum rows per DELETE |
| `telemetry.maintenance_delete_batches` | 4 | Maximum DELETE batches per target |
| `telemetry.maintenance_rollup_days` | 7 | Maximum stable dates archived per slice |
| `telemetry.maintenance_retry_seconds` | 300 | Retry/catch-up interval |
| `telemetry.maintenance_interval_seconds` | 3600 | Normal completed-slice interval |

Deadlines are checked between statements. They are not a database-side query
cancellation guarantee. Retention ages remain the existing administrator settings.
The configured age is displayed separately from the observed overdue-row count.
Deleting rows does not promise immediate filesystem shrinkage of InnoDB files.

Raw events and other independent retention targets are cleaned before archival.
Hourly data is deleted only when older than both its retention limit and the
persisted exclusive archival checkpoint. A day is upserted before the checkpoint
advances. After partial deletion, a resumed worker must not re-upsert that day
from its incomplete hourly input. A checkpoint read failure or malformed stored
date refuses replay and hourly deletion instead of pretending this is the first
run. Retention-setting read failures similarly do not become destructive defaults.

The archival boundary matches the collector's 86400-second late-event horizon,
including local daylight-saving transitions. Recently completed but still
changeable dates are refreshed separately. The current partial day is not rolled
into a completed daily total. Long-window reports have not been switched to daily
storage merely because archival now works; consistency remains observable first.

`telemetry_job_runs` now receives a started record and a completed/failed terminal
record for actual admitted slices. A leftover started record is reconciled as
interrupted only after exclusive lock admission. Job completion means that the
bounded slice finished, not necessarily that its historical backlog is empty.
The result's `has_more` flag distinguishes those cases. Operational failures expose
bounded codes rather than SQL or database exception messages.

## Measurement and reporting

- Activation-origin SQL contains actual whitespace, not literal backslash-n.
  Query failure is distinguishable from an empty result. Both daily consistency
  query families are admitted by the profiler rather than returning unavailable
  without executing.
- Page-load collection runs in a task after the load event finishes, including
  a collector loaded after the document is complete. Missing/nonfinite/zero
  measurements are not accepted as zero-millisecond loads. Historical page-load
  aggregate buckets containing invalid zeros are excluded, not rewritten.
- Complete Overview and standalone traffic exports use canonical event counts
  for page/photo activity. Legacy session counters remain available as diagnostic
  evidence. Session duration, bounce and cohort-based entry/exit counts retain
  their existing semantics and are explicitly described as such.
- The four traffic segments are all, non-bot-classified desktop/tablet/phone,
  bot-classified, and unclassified. Neither recognized desktop nor a bot substring
  is proof of a person's identity. Server media events classify a transient UA
  into bounded device/browser/OS values without storing that UA. Old unknown
  media is not retroactively reassigned to people or bots.
- Decoded-cache observations distinguish current preview and current full-source
  lookups. Background preloads without navigation ownership are not added to
  visible lookup ratios. Hits are emitted only for a resolved usable cached image;
  failed preloads that fall back to a fresh load emit one miss instead. Late
  resolutions from superseded navigation do not produce a new hit/miss attribution.
  Phase detail uses the retained raw-event window, not the longer aggregate window.
- Historical high-open sessions are retained and explicitly described, including
  their share of all opens in the selected session cohort. No heuristic deletes
  the 7833-open session or claims to reconstruct its real human activity.

## SQL observations and scope

Common public gallery/page requests prime one bounded shared schema snapshot.
Cover/background readiness and thumbnail-column discovery reuse the canonical
cache and its existing migration invalidation. This does not weaken missing/unknown
schema handling, media authorization, or image visibility checks. Public media
requests retain their existing post-session-unlock priming path.

DB telemetry exposes separate slow, high-volume and failed fingerprint tables,
plus the first/last observed hourly buckets. Those database totals are installation
operations, not bot-filtered visitor statistics. Newly collected affected-row
counts exclude metadata statements and failed executions; returned-row counts
are labelled unmeasured. Historical misclassified affected-row counters are not
rewritten. Optional `IF [NOT] EXISTS` keywords no longer become a table named `if`.

The large SELECT volume on `images` in the supplied report is not enough to name
or safely remove every expensive caller. No speculative authorization-sensitive
image lookup cache was added. Compare fresh high-volume fingerprints and fixed
workload measurements before treating this part of performance work as complete.
Production ZIP-cache filesystem permissions are also outside this source patch.

## Applying and checking the patch

Back up the installation database and source first. Apply every supplied source
file at its repository-relative path, including the new model/service modules,
loader changes and refreshed `app/core-manifest.json`. The affected archive is a
source-review/commit patch, not a complete updater release package. Keep `tests/`
and this work log out of an FTP production deployment using the existing deploy
helpers. Existing dynamic asset revisions include lightbox dependencies; the
collector compatibility copy is byte-identical to `usage.js`.

With telemetry enabled, invoke the existing maintenance cron about every five
minutes, or execute one bounded CLI slice from the application root:

```sh
php scripts/telemetry_maintenance.php
```

The CLI exits nonzero for a failed maintenance result. `has_more: true` means
another invocation is required; do not equate a successful slice with an empty
backlog. Admin's manual maintenance action is now POST plus CSRF, not a mutable
GET link. No new server token, credentials or manual SQL DELETE is required.

Inspect new job records and overdue counts after successive slices. Verify raw
backlog decreases, the stable-day checkpoint advances, and daily consistency
matches for completed dates once catch-up reaches them. Test one maintenance
invocation while thumbnail work is busy or outside its own scheduled window.

For measurement checks, open a photo, navigate away before a delayed decode
finishes, return to a cached photo, and inspect phase-specific outcomes. Verify
positive finalized page-load samples and actual activation-origin rows. Export all
four segments at comparable times; old unclassified media belongs in the unknown
export. Compare standalone and complete-overview canonical page totals at the
same cutoff, allowing for new events during separate exports.

## Verification boundary

Use the central runner for the complete source audit:

```sh
php scripts/audit.php --profile=full
```

New deterministic fixtures cover retention interruption/replay, strict checkpoint
and retention reads, late beacons, lock/throttle/off paths, SQL construction and
profiling execution, canonical counts, privacy buckets, navigation event timing,
and asynchronous cache accounting. SQL construction mocks are not a live MariaDB
integration test. The exact central audit result and local environment limitations
are recorded in `TEMP_TELEMETRY_0105_FIXES.md` for this handoff.
