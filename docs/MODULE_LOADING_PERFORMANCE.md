# Module loading measurements

Measured on 2026-10-23 against immutable develop base
`768af8e2904dfd07314095ea5219b3064440d82f` after integration #285.
BASIC comparison base is main `471de257cc1598c92dec6f2d09f53671f99eadb8`.
The plan has 98 modules, 4,102 direct references and 450 unique files.
The other 3,652 references are overlap between module lists, not repeated
execution. No runtime dependency, root, generated plan or ratchet was changed.

The before implementation is that base loader plus the same phase tracing.
The after implementation checks canonical order linearly, reuses physical
checks only inside one load preflight, tracks completion with counters and a
DFS prefix, and returns before canonical execution for an empty load.
Every request still validates the entire composed plan. Every later load
rechecks selected physical paths. There is no persistent validation cache.
The private tracing callback is explicitly reviewed in the compiler's authored
dynamic-site registry. It accepts only local loader-operation closures and adds
no module edge; other expressions still fail the existing drift check.

## Method and scope

Windows, PHP 8.3.30, private MySQL 8.4.3, migrated disposable fixture and
real Admin login. Each of five real HTTP routes has one first observation
and seven warm observations in each opcode mode. Response content contracts
pass; the existing nine CLI routes also pass in every retained capture.
Tables show medians of the seven warm samples in milliseconds, before → after.
Execution contains includes; do not add the two columns together.
Request totals include application, database, scheduler, tracing and shutdown
work. Samples are sequential before/after runs, not randomized or interleaved.
Background scheduling and machine load vary; total latency changes are not
causal estimates of loader savings. No production speed-up is established.

The first OPcache-on observation follows a successful reset in the isolated
server. Cached-script/hit/miss counters confirm cold application code and
subsequent warm hits. OS page cache, realpath state and database buffers are
not flushed. OPcache-off first observations therefore have no cold-disk claim.
The before/after phase instrumentation is identical; its overhead is included.
PHP allocator peak excludes process RSS and shared OPcache memory.

## Warm requests

### OPcache off

| Route | Plan read | Full validation | Dependency/path plan | Execute | Includes | Request total | PHP files | Peak MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| robots | 2.62 → 2.67 | 1.40 → 0.90 | 3.75 → 3.88 | 29.99 → 31.09 | 29.63 → 30.74 | 57.83 → 58.82 | 93 | 10 / 10 |
| home | 2.68 → 2.67 | 1.40 → 0.89 | 20.78 → 20.72 | 80.19 → 79.26 | 79.03 → 78.33 | 177.32 → 187.48 | 192 | 18 / 18 |
| gallery | 2.64 → 2.60 | 1.44 → 0.89 | 13.02 → 12.70 | 101.85 → 101.20 | 100.29 → 99.89 | 205.08 → 211.43 | 266 | 20 / 20 |
| admin | 2.77 → 2.62 | 1.44 → 0.92 | 13.25 → 12.36 | 99.94 → 100.38 | 98.46 → 99.16 | 272.08 → 264.90 | 222 | 22 / 22 |
| admin_telemetry | 2.62 → 2.63 | 1.47 → 0.90 | 6.79 → 6.84 | 49.61 → 50.14 | 48.99 → 49.58 | 111.70 → 105.29 | 137 | 14 / 14 |

### OPcache on

| Route | Plan read | Full validation | Dependency/path plan | Execute | Includes | Request total | PHP files | Peak MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| robots | 0.03 → 0.03 | 1.19 → 0.68 | 3.87 → 3.71 | 2.32 → 2.19 | 2.12 → 2.01 | 43.42 → 36.59 | 93 | 4 / 4 |
| home | 0.11 → 0.11 | 1.17 → 0.69 | 20.47 → 20.69 | 5.97 → 5.92 | 5.41 → 5.39 | 94.69 → 92.67 | 192 | 4 / 4 |
| gallery | 0.03 → 0.03 | 1.17 → 0.67 | 13.12 → 13.01 | 8.40 → 8.39 | 7.61 → 7.64 | 108.69 → 108.02 | 266 | 4 / 4 |
| admin | 0.04 → 0.03 | 1.24 → 0.68 | 12.85 → 12.08 | 7.68 → 7.01 | 6.95 → 6.40 | 174.80 → 167.73 | 222 | 4 / 4 |
| admin_telemetry | 0.03 → 0.03 | 1.18 → 0.69 | 6.61 → 6.38 | 3.96 → 3.96 | 3.63 → 3.64 | 59.47 → 60.05 | 137 | 4 / 4 |

File counts are identical before/after in every measured observation.
Peak columns list before / after. Warm opcode-enabled peaks are 4 MiB;
the opcode-cold observations use 6 MiB. Allocator granularity can conceal
small transient allocations in completion/preflight maps.

## Opcode-cold requests

One observation per route/variant, milliseconds; this is not a statistical
latency estimate. Each pair is before → after.

| Route | Plan read | Full validation | Dependency/path plan | Execute | Includes | Request total | PHP files | Peak MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| robots | 2.90 → 3.07 | 1.21 → 0.71 | 8.38 → 8.42 | 53.90 → 54.60 | 53.57 → 54.29 | 95.32 → 94.92 | 93 | 6 / 6 |
| home | 2.92 → 3.20 | 1.17 → 0.71 | 21.24 → 22.45 | 141.77 → 151.22 | 140.75 → 150.21 | 265.85 → 297.00 | 192 | 6 / 6 |
| gallery | 3.04 → 2.89 | 1.21 → 0.70 | 26.47 → 26.79 | 184.29 → 186.10 | 182.95 → 184.74 | 320.20 → 311.18 | 266 | 6 / 6 |
| admin | 2.90 → 3.12 | 1.16 → 0.71 | 23.19 → 24.65 | 177.49 → 184.09 | 176.35 → 182.84 | 366.26 → 366.97 | 222 | 6 / 6 |
| admin_telemetry | 3.27 → 2.98 | 1.21 → 0.71 | 14.74 → 14.68 | 91.06 → 92.56 | 90.50 → 91.97 | 186.58 → 165.50 | 137 | 6 / 6 |

## Repeated calls and decision

| Route | load calls per request | Canonical execute passes before → after |
| --- | ---: | ---: |
| robots | 7 | 7 → 5 |
| home | 12,15 | 12,15 → 8 |
| gallery | 12 | 12 → 8 |
| admin | 11 | 11 → 7 |
| admin_telemetry | 7 | 7 → 5 |

Load-call variation on Home comes from visitor-scheduler work. Empty/repeated
calls retain module state but now avoid rescanning the 450-file global order.
Full constructor validation falls by roughly 35–45 percent in these runs,
saving about half a millisecond per request. Canonical completion accounting
also falls modestly; dependency/path checks remain a larger cost on Windows.
The experiment does not justify persistent validation caching, a new metadata
format, or weakening path/graph checks. The number of overlapping references
alone is not a useful cache criterion.

The local Galerie.test Apache runtime was independently probed over loopback:
`PHP 8.3.30`, `apache2handler`, OPcache extension absent and inactive.
The temporary status probe was removed. No server configuration was changed.
The isolated OPcache-on results demonstrate a larger effect on plan parsing
and includes than the loader optimization, but do not predict live-host gains.
Server timestamp validation or explicit deployment reset/restart must keep
updated source current; see [PHP OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php).

## Evidence and qualification boundary

Raw observations are retained locally in `cache/test-audit/loader-*.json`;
summary, baseline source bytes and the Apache status are under
`cache/test-audit/module-loader-costs/`. Older worktree-base observations
remain separate under `module-loader-previous-base/` and are excluded here.
The raw reports include loader SHA-256 identities and original CLI results.
The final handoff binds the source commit, hosted BASIC run and these hashes.

Runtime kernel regressions cover shared paths, lexical and symlink escapes,
cycles/unselected nodes, missing files, canonical order, include-local scope,
empty/repeated loads, later-load filesystem changes and partial DFS-prefix
completion. They remain in the central registry. Hosted BASIC validates syntax
and declaration docs only; it does not execute these regressions. Full hosted
preparation, manifests/inventory and integration qualification await the
separately approved integration. No local regression/audit suite was run for
this measurement authorization; no FULL or production qualification is claimed.
