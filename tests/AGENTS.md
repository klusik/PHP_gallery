# Regression and fixture instructions

Read [root AGENTS](../AGENTS.md), the relevant [TESTING.md](../TESTING.md) section,
and [checked authoring examples](../docs/AGENT_AUTHORING.md) before editing.
Tests are plain CLI PHP scripts, not PHPUnit; keep new fixtures executable as
`php tests/<name>_test.php`. Hosted `scripts/audit.php` owns execution and registry
selection. Root CI-first rules override example commands; do not run local test
loops by default. Cover behavior with focused existing owners where practical;
require a browser/database only when the behavior needs one.

## Fixture ownership and evidence

- Load the actual source owner before its first assertion. Keep shared source
  reads above all consumers, and import/fully qualify native exceptions inside
  PHP namespaces, including negative branches.
- Give every independent child its own explicit minimal bootstrap, including
  Core path-policy dependencies for passive services. Parent-loaded functions
  do not establish child dependencies. Assert the child's real exit status,
  stderr and persisted evidence; synchronize exact success markers with coverage.
- Read a whole split module with `module_source()` from `support/module_source.php`.
  Never require a part outside its module. Preserve path-depth regression coverage
  in `module_split_path_resolution_test.php` when moving filesystem expressions.
- Use named constants for timers/sleeps, complete native headers, truthful named
  declaration docs/types/shapes and purpose docs for every anonymous class too.
  These remain required in fixtures.
- Preserve strict literal expectations when runtime/owner matrices or browser
  import URLs change. For covered Admin import relationships use the existing
  relational contract, as explained in the authoring reference.

## Bounded workers, caches and interruption

Define counters relative to their identity: a new manual cycle starts fresh,
regional continuations advance their own durable round, and lifetime confirmed
removal receipts survive both. Keep the current incomplete-check marker and
counters visible across status, messages, diagnostics and browser progress.
Extend the existing fixture for both fresh-cycle and continuation behavior.

Size crash admission for the worker's real observation semantics, including
metadata and dot entries. Reuse the neighboring fixture's setup and assert the
intended physical mutation before interruption; retain production caps/suite
limits. Assert short-opportunity caps, durable continuation progress and
admission exhausting a deadline without assuming a first-attempt slice.

For ZIP expiry, require positive producer-format proof, creation/payload age and
the shared construction/serving lease. ctime detects replacement, not birth or
renewed TTL after permission changes. Keep current manual-operation counters
separate from retained regional observations and lifetime removal receipts.

## Browser and isolated rendering fixtures

Hold a follow-up passive read while asserting a mutation response, then release
it separately to test refresh without racing snapshots. For live filtering,
exercise old responses during debounce and old JSON bodies finishing after newer
results; commits and history must belong to the captured current request URL.

Send browser-harness controls (such as viewport changes) through the captured
native fetch, outside application mocks. Keep product method/credential assertions
strict. Isolated render fixtures explicitly require the owning view module before
calling its named typed presentation helper.

The required `browser-tests` job sets `PHP_GALLERY_BROWSER_REQUIRED=1` and uses
central `--suite=browser-map`. Missing, unstartable or silently skipped Chromium
must fail. Database/runtime jobs disable browsers at job scope. Local optional
discovery and the disposable DB browser journey remain supported; an isolated
fixture PASS does not qualify a live installation. Preserve the manual matrices
in TESTING.md and report unperformed coverage.

## Parallel resource isolation

The PHP registry owns `quick_tests` and explicit `serial_tests` reasons. Put
unsafe shared-resource, contention or nested-process fixtures behind those
exclusive barriers; never encode filenames in the scheduler. Each child keeps
separate captured output and a timeout from its own start; reporting order stays
deterministic. The bounded pool defaults to four, allows
`PHP_GALLERY_AUDIT_WORKERS=1` through `8`, and blocks invalid values.

Hosted PHP shards must form a complete disjoint partition on the same candidate.
Keep every shard's barriers and private DB/HTTP/workspace resources. Independent
GitHub runner jobs differ from processes sharing one runner. Matrix/evidence
changes also require [.github/AGENTS.md](../.github/AGENTS.md).
