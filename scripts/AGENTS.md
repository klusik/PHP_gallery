# CLI tooling, audit and packaging instructions

Read [root AGENTS](../AGENTS.md) and [checked authoring examples](../docs/AGENT_AUTHORING.md).
For scanner/audit/CI composition or evidence changes also read
[.github instructions](../.github/AGENTS.md) and the
[CI technical contract](../docs/CI_COVERAGE_REUSE_CONTRACT.md).
Do not run local audits, generators or duplicate lint by default; the root
exceptional diagnosis/recovery rule controls every command below.

## Source, generated artifacts and inventory

`scripts/audit.php` is the sole test orchestrator, including syntax, contracts,
Node fixtures, PHP regressions, WinApp and compact PASS/FAIL/SKIP/BLOCKED reporting.
`scripts/audit_registry.php` owns profiles/suites and exceptional invocations;
`scripts/audit_php_registry.php` owns PHP selection/serial reasons. `tests/run.php`
is a compatibility wrapper, never an agent verification entrypoint. Do not create
a parallel test registry in YAML.

Finish source/tests/permanent docs and stage new/deleted membership before the
source commit: inventory reads Git's index. Include development/documentation
paths in source-review inventory even when excluded from production. Production
file-set equality alone does not prove complete inventory freshness.

Hosted `prepare_candidate.php` prepares reviewed runtime modules, production
inventory and final complete-file manifest hashes in dependency order, followed
by read-only `--check`. Identical inputs must not rewrite artifacts/timestamps.
Edit authored runtime roots/dependencies, not generated modules.php. Inspect the
full closure and duplicate/transitive/file-count ratchets as required by
[app instructions](../app/AGENTS.md). Local preparation is maintainer recovery
only when explicitly requested or hosted preparation is unavailable.

`scripts/package_production.php` is the canonical PHP folder/ZIP builder. It
refuses stale generated integrity before staging and verifies actual selected
bytes. It does not repair generated state. `scripts/release_files.php` is a
policy-inspection bridge; membership alone is not content integrity. Production
packages exclude tests, media and installation state. Historical shell deploy,
interactive packaging and FTP credential/upload paths are removed; do not restore
them. Default release artifact restrictions are in
[docs/AGENTS.md](../docs/AGENTS.md) and [RELEASE.md](../RELEASE.md).

Any updater-managed change needs a fresh `app/core-manifest.json` before a release
commit, archive or production handoff. An explicitly requested affected-files ZIP
containing managed files must include that fresh manifest. Never hash truncated
Contents API data.

## Source-contract scope

Reuse existing declaration/header/policy scanners; do not duplicate their rules.
The named-declaration scanners and historical presence check share the anonymous
function exemption; native typing, class documentation and parser coverage remain
independent. Preserve checked examples and negative cases in AGENT_AUTHORING.md.

Always use descriptive named constants for operational numbers in tools, including
timeouts, decoder depths, size/count limits, retry intervals and defaults. Attach
purpose, `Type`, `Units`, `Scope`, `Consumers` and `Rationale` to each owner; state
the actual value and why it fits the protected operation. For format-native named
YAML/JSON settings, document the same facts in attached comments or the permanent
configuration reference. Explain grammar offsets and test boundary values locally
instead of inventing configuration for structural arithmetic. Review every new
numeric policy in the batch, including its test bridge and delegated children.
Tooling is excluded from the changed-runtime gate, but whole-tree policy inventory
still counts undocumented sites. Fix the source; preserve ratchet caps/baselines.

BASIC currently checks syntax and changed declaration docs, not native attribution
headers or FULL inventory budgets. A green BASIC cannot waive headers. Adding a
cheap changed-header gate is a separate evaluator/CI change: reuse `header_issues()`
and the existing inventory/selection semantics, retain diagnostics and explicitly
qualify/install any affected CI authority before relying on it.

`quick` is diagnostic feedback: its curated `php-fast` subset excludes complete
PHP, whole-tree inventory budgets, WinApp, slow Node and Chromium. `full` retains
all deterministic owners; `release` adds release checks where selected by the
release lifecycle. None of these profile interfaces grants agent execution or
release approval. Use installed workflow ownership, not profile names, to claim
coverage. Missing required runtime/base/parser coverage is BLOCKED.

Preserve include-probe ceilings: early runtime one file/16 MiB; application
bootstrap 40 files/16 MiB (24-file baseline). Route probes cover nine routes with
an owned disposable fixture (otherwise explicit SKIP); route ceilings are
160-300 files and 32-48 MiB. Wall time is observational. Detailed runtime and
parallel ownership remains in TESTING.md and the CI technical contract.
