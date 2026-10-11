# Issue #185: CI coverage and change-aware gating baseline

Snapshot: **2026-10-11**. Source branch: `develop`, commit `5509c865ede3f54df87024ac8692fa5e3166ea1a`. This is an evidence and design artifact, **not an active change to hosted test selection**.

## Maintainer cadence replacement (2026-10-12)

All earlier proposals in this historical baseline are superseded where they
require FULL on feature pushes or after develop has already advanced. The current
source contract is [CI_COVERAGE_REUSE_CONTRACT.md](CI_COVERAGE_REUSE_CONTRACT.md):
feature/squash BASIC, one FULL on prepared A before the Actions button performs
develop FF, then BASIC for the actual release A..B delta. Four manual builds,
notes/manifest preparation, assets and existing reviewed completion remain.
The button rejects unsquashed source and stale develop. Post-release pure FF
retains B qualification without another matrix. Source changes require hosted
qualification and protected installation; no timing reduction or live integration
is inferred from this design. Historical measurements below retain their original
scope and are not measurements of the new cadence.
## Sources and ownership

- Source: `.github/workflows/gallery-workflows.yml`, `candidate-preparation.yml`, `scripts/audit.php`, `scripts/audit_registry.php`, `scripts/audit_php_registry.php` and `scripts/gallery_workflow_run.php` at the snapshot SHA.
- Live GitHub repository rulesets (read-only): `24808873` for `develop` and `24808772` for `main`. No ruleset was edited.
- Historical hosted run evidence: [38133732170](https://github.com/klusik/PHP_gallery/actions/runs/38133732170), [38134513077](https://github.com/klusik/PHP_gallery/actions/runs/38134513077), [38135383096](https://github.com/klusik/PHP_gallery/actions/runs/38135383096) and the distinct prior [37957193887](https://github.com/klusik/PHP_gallery/actions/runs/37957193887).
- Existing owners: #94 handles browser diagnostics/flakes; #95 handles general-test duplication in PHP/database jobs; #142 and #143-#148 handle workflow and required-check inventory/cleanup; #101 handles release safeguards; #131 owns the measurement discipline. This document does not supersede those issues.

## Verified inventory (source snapshot)

| Item | Observed inventory | Qualification distinction |
| --- | ---: | --- |
| PHP test files matching `tests/*_test.php` | 366 | File count, not a proven executed-test or assertion count |
| Node files matching `tests/*_test.mjs` | 79 | Registered explicitly in `audit_registry.php` |
| Registered Node browser-enabled fixtures | 34 | Browser capability flag, not identical to the live database browser journey |
| Curated quick PHP test entries | 48 | Quick is focused feedback, never the release/full handoff |
| PHP serial-test exception entries | 26 | Shared-resource isolation, not 26 duplicate runs |
| WinApp Python test modules | 8 | Module count, not Python test-case count |
| Tracked workflow YAML files under `.github/workflows/` on `develop` | 5 | Triggers and owners require separate classification under #142 |

The five tracked workflow files are `candidate-preparation.yml`, `gallery-workflows.yml`, `release-qualification.yml`, `release-promotion.yml` and `release-retirement.yml`. Historical issue #143 lists a different fifth file (`release-reconciliation.yml`), so that earlier list is not an authoritative current snapshot.

### Hosted execution / coverage ownership

| Executed job or profile | Purpose requiring retention | Candidate for reduction or relocation | Owner |
| --- | --- | --- | --- |
| Candidate preparation plus read-only candidate preflight | Exact source SHA, validated/generated artifact identity, source/manifest contracts | Do not remove or bypass; avoid repeated unrelated heavy work inside it | #106 / #101 |
| MySQL 8.4, PHP 8.3, real browser | Real DB/HTTP/migration/auth/concurrency and first-party live Chromium | Keep unique MySQL/HTTP/browser assertions; examine duplicated independent Node/WinApp/source work | #95 / #94 |
| MariaDB 10.11, PHP 8.3 | Real MariaDB migration/HTTP/concurrency/runtime behavior | Keep engine-specific coverage; examine broad `full` repetition | #95 |
| MariaDB 11.4, PHP 8.5 | Real newer MariaDB/PHP combination | Keep distinct compatibility coverage; examine broad `full` repetition | #95 |
| PHP 8.1 Unicode source; PHP 8.5 ASCII source | Old/new PHP behavior, missing intl normalization and source compatibility | Preserve PHP-specific checks; move engine-independent replays only with named new owner | #95 |
| Required Chromium fixtures | Independent Node browser suites, fail when required browser is unavailable | Preserve as separate ownership from live authenticated DB browser | #94 |
| Positive production packaging on Linux, Windows, macOS | Cross-platform deployment outputs and membership | Keep all OS variants; positive packaging alone cannot replace negative deployment tests | #95 / #142 |
| Aggregate `Complete required CI matrix` and exact candidate gate | Fail-closed protected-branch status and unsuperseded SHA | Keep exact required-check identity and mandatory semantics | #142 / #101 |
| Conditional `release` audit and release documentation | Complete immutable release qualification and four PDF editions | Keep release-owned, not feature-commit PDF work | #101 |

The current source invokes `--profile=full` in the two PHP source jobs and, via `gallery_workflow_ci.php` and `gallery_workflow_run.php`, in all three real-database jobs. This is **five broad full-audit invocations**, not proof that every individual test is redundantly executed or that engine-specific variants can be deleted. All full profiles include general PHP/Node/WinApp/source suites; the required fixture can produce context-dependent SKIPs.

`audit_registry.php` also contains a duplicate array-key declaration for `breadcrumb_workflow_integration_test.php`. PHP overwrites the earlier identical key in the array; this is source duplication, **not evidence of two test executions**.

### Live branch rules that must remain valid

- `develop` ruleset `24808873` is active and requires exact check `Complete required CI matrix` from GitHub Actions integration ID `15368`, strict required-status checks, linear history, no non-fast-forward and no deletion.
- `main` ruleset `24808772` is active and requires `Release qualification` from integration ID `15368`, PR review and thread resolution, no non-fast-forward and no deletion.
- A passing source preflight, a green renamed job, a skipped mandatory runner, or an older green SHA is not equivalent to the mandatory qualified candidate.
- No branch rules, workflow triggers, required check identifiers, release gates or job matrices change in the first foundation commit.

## Historical latency evidence (not a controlled A/B experiment)

Numbers below come from each completed workflow's `updated_at - run_started_at` and the sum of executed jobs' `completed_at - started_at` in the GitHub Actions API. Summed runner time includes parallel jobs and is not elapsed workflow time or provider billable usage.

| Hosted run | Outcome | Workflow elapsed | Summed executed job time | Scope caveat |
| --- | --- | ---: | ---: | --- |
| [38133732170](https://github.com/klusik/PHP_gallery/actions/runs/38133732170) | FAIL, DB/browser journey | 384 s | 1,327 s (22.12 runner-min) | Different revision of #178 |
| [38134513077](https://github.com/klusik/PHP_gallery/actions/runs/38134513077) | FAIL, DB/browser journey | 440 s | 1,292 s (21.53 runner-min) | Different revision of #178 |
| [38135383096](https://github.com/klusik/PHP_gallery/actions/runs/38135383096) | PASS | 425 s | 1,289 s (21.48 runner-min) | Corrected fixture revision of #178 |
| [37957193887](https://github.com/klusik/PHP_gallery/actions/runs/37957193887) | PASS | 520 s | 1,140 s (19.00 runner-min) | Different branch/revision and runner scheduling |

For run 38135383096 the MySQL 8.4 job lasted 246 s, the MariaDB 11.4 job 218 s and the MariaDB 10.11 job 161 s; the PHP 8.1/8.5 source jobs lasted 178/181 s. The central audit steps inside those jobs were 199/187/130 s for the three DB jobs and 158/156 s for the two source jobs. Setup, waiting and checkout must not be conflated with time executing tests.

These samples have *different* source SHAs and limited cache/runner data. They do not establish a same-SHA flake rate or a measured optimization. Provider-reported billable milliseconds and cold/warm cache identity were **not independently sampled for this snapshot**. See #131 for older explicitly scoped API billing evidence.

## First implementation foundation: advisory classifier only

`PhpGallery\Audit\classify_source_change_scope()` in `scripts/audit_lib.php` is a **pure, non-enforcing helper**, covered by `tests/audit_change_scope_test.php` under the existing full/release PHP regression discovery. It has no CLI, workflow trigger, skip logic, new registry, or power to produce green status.

Current deliberately narrow rule:

1. The caller must have independently verified the **authored source SHA and a trustworthy branch-specific comparison baseline**.
2. A non-empty diff with **only `M` entries** for the four maintained manual TeX sources yields advisory `manual-source`.
3. Missing/untrusted comparison base, empty diff, non-modification statuses including rename/add/delete, mixed source, test/CI/security paths, unknown paths or any malformed path yield `full`.
4. Even `manual-source` is **not proof of prose-only content, valid TeX, localization consistency, or complete qualification**. No CI gate currently consumes this advisory scope.

The regressions intentionally cover positive four-edition changes and negative cases involving Git history, PHP, security, workflows, tests, migrations, generated state, path normalization, renames, additions and deletions. This foundation is safe precisely because **all hosted jobs continue to execute as before**.

## Design for later change-aware qualification (not yet enabled)

### Baseline provenance, prior to classifier use

- Distinguish `source_sha` (maintainer-authored source) from `candidate_sha` (post-preparation, potentially generated commit). Bind each run and status to exact immutable identities.
- Compare the authored feature/fix change to its appropriate **fork point with `develop`**, using verified ancestry and complete rename-aware Git history. Do **not** assume the existing `origin/main` source-policy merge-base is the authored-change baseline; `develop` can include unrelated unreleased features.
- Any missing head/base/provenance, shallow or ambiguous history, unexpected generated change, unknown status or unexpected event must require `full`. A path-only advisory result cannot authorize a skip on its own.
- The release profile remains `release` and exact-Q regardless of classification.

### Planned gates by risk, with no active changed test map

| Source risk | Future preflight | Expected qualification after review |
| --- | --- | --- |
| Verified maintained manual-source-only | UTF-8 and TeX structural/source/localization checks | Restricted docs-source profile only if required-check contract is safely adapted |
| Ordinary known PHP/JS/CSS | Source/policy/lint plus centrally registered domain tests | Relevant PHP/Node/browser tests plus explicitly retained compatibility ownership |
| Authentication, media authorization, migrations, updater, deploy, release, workflow and test tooling | Mandatory source preflight | Broader or full environment matrix depending on reviewed risk |
| Mixed, rename/delete, unknown, stale/missing baseline or generated-state ambiguity | Fail closed | Full required matrix |
| Release preparation/promotion | Full immutable source and artifact policy | Existing four-edition, exact-SHA release qualification |

Test selection stays owned by `scripts/audit.php` and its registries. No YAML path-to-suite list, new runner, retry-to-green mechanism, mandatory local audit or blanket timeout increase is approved. The conditional final required check needs a separately reviewed status contract; a skipped mandatory job may not masquerade as PASS.

## Decisions and next evidence to collect

| Area | Preliminary disposition | Required proof before changing runtime behavior |
| --- | --- | --- |
| Auth, access, migrations, DB concurrency, real browser | KEEP | Explicit variants and failure-mode ownership |
| Independent source/Node/WinApp work repeated in five `full` environments | MOVE/CONSOLIDATE **candidate** | Per-test execution ownership and destination mandatory job, #95 |
| Positive cross-OS production packaging | KEEP | Must retain Linux/macOS/Windows and negative packaging coverage |
| Oversized live browser journey | SPLIT **candidate** | Scenario/fixture isolation, diagnostic evidence, same-SHA repetitions, #94 |
| Existing workflow definitions | KEEP pending #142 inventory | #143/#144 settings and triggers plus reviewed #145 decisions |
| Change-aware ordinary feedback and skipped matrix | DESIGN ONLY | Trusted baseline, deterministic negative fixtures and intact protected check semantics |

Next increments: finish per-suite ownership and timing inventory; post read-only findings to #95/#94/#142 where their work is implicated; validate the exact-feature SHA via hosted candidate CI; then independently review any **actual** reduced-gate proposal. No performance gains or flaky-test fixes are claimed by this initial commit.
## Phase 1: exact authored-source provenance and safe generated-state separation (2026-10-11)

The second implementation increment adds `parse_git_name_status_z()` and `audit_source_change_impact()` to the **existing** `scripts/audit_source_evidence.php` owner. The earlier `classify_source_change_scope()` remains unchanged. `tests/audit_change_evidence_test.php` is discovered by the normal full/release PHP test suite and exercises both the NUL-delimited parser and a private real Git fixture, without touching the working repository.

### Inputs, trust and output

- Caller supplies `source_sha` (the immutable, authored source candidate) and `candidate_sha` (the prepared HEAD being evaluated). The resolver does **not** guess these from a commit subject or choose an untrusted new HEAD.
- The exact checkout must match `candidate_sha`, be clean and report the expected Git repository root. Worktrees, missing history, invalid IDs, dirty/untracked source, unavailable Git or changing local refs fail closed to advisory `full`.
- The resolver reads a single unambiguous merge-base of `source_sha` and `refs/remotes/origin/develop` and records both the resolved `develop_sha` and immutable comparison SHA. This separates the feature's authored change from unrelated new `develop` work. The earlier `origin/main` policy comparison remains untouched and is **not** substituted as a feature fork point.
- Git changes are obtained with literal argv, bounded subprocesses and `--name-status -z --find-renames --no-ext-diff --no-textconv` to preserve tabs/newlines in names and retain add/delete/rename information; malformed and oversized output is rejected. It does not run file code, fetch remotes or create artifacts.
- If `candidate_sha !== source_sha`, the resolver requires **one immediate source parent**, the exact existing candidate-generator author/email/subject and modifications only to `app/runtime/modules.php`, `app/production-files.json` and `app/core-manifest.json`. Generated paths are recorded separately, never confused with human-authored changes. Unexpected added/deleted/renamed generator outputs fail closed.
- After reading the diffs, the resolver rechecks HEAD, `develop` ref and worktree identity. The existing hosted candidate publisher remains the authority for remote branch-HEAD checks and status provenance.
- The result is a structured, **advisory-only** `scope`/`reason` with source, candidate, develop and base SHAs, authored changes and generated output paths. The only narrow scope remains `manual-source`; ordinary PHP/JS/CSS changes still return `full`. An on-disk bot-style author is **not cryptographic proof** of who created a commit and cannot replace trusted GitHub Actions identity or source-policy/manifest verification.

### Regression evidence and non-goals

The new regression test checks NUL records, malformed output, renames/copies and paths with whitespace, and constructs disposable clean Git histories: a TeX-only branch, a correct candidate with generated output, a moved `develop` branch, missing refs, wrong/stale IDs, a dirty tree, unauthorized generator metadata and files, a true rename and a mixed runtime change. The test never writes to `develop`, `main` or the actual gallery checkout.

**Not yet enabled:** no workflow or required check has been modified; no CI matrix row has been skipped, no new profile/runner created and no speedup claimed. Active use requires a review of the job-to-suite ownership contract (#95), required statuses (#142), exact SHA handoff (#101/#106), and failure diagnostics (#94). The release `release` profile and all four PDFs remain unchanged. A valid `manual-source` classification alone still does **not** validate TeX compilation, structure, localization or authorization to omit required jobs.

### Additional fail-closed edge cases (same workstream)

The resolver now rejects a shallow or unreadable repository explicitly via `git rev-parse --is-shallow-repository`, before using merge-base evidence. The reviewed candidate commit must match **both** the Git author and Git committer metadata, in addition to its immediate parent, subject and generated-output allowlist. These metadata strings remain forgeable, so active CI must still bind trusted GitHub Actions statuses to the exact SHA.

**History accumulation limitation, tested conservatively:** after one successful bot-generated candidate, a later manually authored documentation edit on the same feature branch still has earlier generator changes in its `merge-base(develop,source)..source` path diff. This implementation deliberately returns `full` rather than silently deleting those historical generated paths from its impact set. A future fast gate must either verify prior generated commits through trusted hosted provenance or define an equally sound immutable change baseline; this is not authorized or implemented yet. This behavior prefers false-positive full validation to false-negative skipped coverage.


## Phase 2a: read-only historical candidate boundaries (2026-10-11)

This increment extends the existing `audit_source_change_impact()` provenance resolver without adding a runner, workflow, network client, required check, or test selector. It retains the *aggregate* base-to-source Git diff as `changes` and also records per-commit `authored_changes` and `historical_candidates` for diagnostic review.

- Walk at most 64 commits from the immutable `merge-base(origin/develop,source)` to the requested authored source SHA, in ascending ancestry order. Each commit must have **exactly one expected parent**, so merges, incomplete history, unexpectedly rewritten ancestry and ambiguous output conservatively return `full`.
- Parse each commit's rename-aware, NUL-delimited diff with the existing bounded parser. Read exact bot author, committer and generator subject. A structurally recognizable generated revision must change *only* maintained generated files with `M` status. Other generator-shaped changes or suspicious subject/actor mismatches return `full`.
- Record such candidates with `provenance: requires-hosted-proof`, immutable parent and candidate SHAs, and actual generated paths. This string explicitly means **NOT VERIFIED**. Git metadata, filenames and candidate ancestry do not authenticate GitHub Actions; they remain forgeable by an ordinary committer.
- Most importantly, **any** historical candidate still forces `scope=full`, `reason=unverified-historical-candidate`. Neither `authored_changes` nor `historical_candidates` authorizes a skip; the original aggregate diff remains intact. This prevents a counterfeit generated commit from hiding an authored manifest/CI/security change.

The isolated real-Git fixture now covers one human docs commit, a current generated candidate, subsequent docs-only authored work, two interleaved generator-like commits, modified `develop` and the original negative cases. Regressions validate the list's exact candidate/source identities without considering metadata alone a valid attestation.

### Required hosted provenance contract before any active optimization

Use the existing [candidate-preparation workflow](../.github/workflows/candidate-preparation.yml) rather than a new registry. The recorded GitHub API sample for candidate `4f8225b5cc3a69a9833991496bbb3e4e1d8e880e` binds:

1. Exact candidate-SHA check-runs named `Candidate qualification` and `Complete required CI matrix`, with completed/success from installed GitHub Actions app ID `15368`, not caller-supplied Git author metadata.
2. Both `external_id` values set to `candidate:38140343512:1:4f8225b5cc3a69a9833991496bbb3e4e1d8e880e`. The candidate run `38140343512`, attempt `1`, is a completed/success `push` of the **parent source SHA** `706f97a65adff4dedb3763acc8e4f4045cf1da3a`, executing `.github/workflows/candidate-preparation.yml` on the expected source branch.
3. The run's immutable jobs include successful `Prepare source candidate at event SHA`, native `Full CI for the published prepared SHA / Complete required CI matrix` and `Reject superseded qualification`. The generated commit itself is unsigned and is **not** verified simply because these names match.
4. Before using this for historic filtering, independently fetch **all pages** of exact candidate-SHA checks, identify conflicts/older attempts, correlate the source workflow run and reviewed workflow definition, refuse stale/invalid/missing statuses, and maintain unchanged-branch-head checking for the new candidate. An old PASS cannot qualify a later authored SHA. If the provider API or any association is unavailable, retain `full`.
5. A GitHub Actions app ID alone is not a blanket attestation of trusted workflow contents: repository workflows with permissions can publish same-app checks. Pin the allowed qualification semantics to an independently reviewed workflow version and ensure malicious workflow or generated-file edits never disappear from authored impact.

This increment deliberately **does not** implement authenticated GitHub API retrieval or accept externally provided proof to reduce coverage. The ordinary full/release matrix and existing immutable-source checks remain authoritative. #95 still owns per-test environment coverage decisions and #142 owns ruleset/required-check changes.


## Phase 2b: bounded GitHub Actions evidence correlation (read-only, 2026-10-11)

The existing scripts/audit_source_evidence.php owner now contains
audit_complete_hosted_pages() and audit_correlate_historical_candidate().
They are **pure correlation validators**. No API token, HTTP client, runner,
new profile, workflow change or active skipping was added. The live Git
resolver still reports unverified-historical-candidate and scope=full.
The successful correlation result also **always returns full** with
workflow-authority-not-independently-attested. A mocked GitHub API response
is a regression fixture, **not** an authenticated production attestation.

The input collector, if later approved, must retain the exact GET endpoint
and requested page/per_page=100 for each response, collecting *all* pages
of /commits/{candidate}/check-runs and /actions/runs/{id}/attempts/{attempt}/jobs
and rechecking the selected branch/develop refs around the fetch. The current
PHP functions accept a numbered list of captured API pages, not unverified
partial arrays. They reject 501+ entries, more than five pages, missing or
out-of-order pages, short pages, inconsistent total_count and repeated record
IDs. This proves internal snapshot consistency, **not** that a remote collection
cannot mutate between HTTP requests.

Correlation checks a single candidate's authenticated-looking source/run/attempt
link, expected branch, push event, workflow ID/path, repository, successful
completed run and referenced reusable workflow SHA. Both exact-candidate
check-runs must be unambiguous, completed/success, published by GitHub Actions
app 15368, share the expected check suite and exact
candidate:{run}:{attempt}:{sha} external_id. It rejects duplicate, missing,
pending, skipped, failed, cancelled, stale-SHA, stale-attempt or wrong-app
required checks.

The existing native required job/step matrix is checked explicitly: preparation,
read-only preflight, PHP 8.1 and 8.5 compatibility, all three real DB engines,
required Chromium fixtures, all three OS package builders, aggregate
Complete required CI matrix and terminal Reject superseded qualification.
Every required job and required gate/coverage step must succeed for the same
source run and attempt. Only the feature-irrelevant release-audit job may be
skipped; missing, extra or conflicting jobs fail correlation. Snapshot ref
observations must show unchanged current branch and develop HEADs.
Historical candidates remain separate from the *new* candidate's exact-SHA
qualification; no previous check can green a newer source SHA.

### Unresolved trust boundary

The matched GitHub Actions app identity, run name and workflow ID **do not**
prove that the workflow revision was independently reviewed. A source-branch
workflow can change its own scripts, use checks:write from GITHUB_TOKEN and
publish a same-app success. A forged Git bot author is equally insufficient.
The code intentionally does not accept a caller-provided trusted=true boolean.
Before considering any active exclusion of historical generated files, require
an independently established allowlist or immutable workflow attestation with
reviewed permissions, full paginated authenticated API retrieval, branch/ref
rechecks and a safe transition path for workflow updates. This review belongs
with #142/#101. Even then, release and high-risk workflow/security changes
remain full. Synthetic PHP regression cases cover exact correlation and adverse
pagination, missing/stale/conflicting check-runs, skipped jobs/steps, changed
workflow/source/refs, API absence and two-page completeness.

### Read-only execution ownership candidates for #95

These are **per-execution** proposals, not deletions or operational changes.
The snapshot is the candidate run 38140343512, where five full profiles were
executed. Timing observations vary by runner and are not controlled A/B savings.

| Suite/owner now | Relevant environmental input and defect class | Current executor | Proposed execution | Future mandatory owner and PASS proof | Move risk / measured evidence |
| --- | --- | --- | --- | --- | --- |
| PHP syntax + JS syntax, source documentation/policy, MVC/mutation contracts | PHP parser and source AST versions matter for syntax; security/policy defects must be rejected before qualification | Preflight and five full-profile variants | CONSOLIDATE duplicate execution only after preserving PHP 8.1/8.5 parser coverage | Exact candidate preflight for invariant source contracts; both compatibility jobs for version-specific PHP parsing; aggregate gate refuses missing owner | Medium, extensions and PHP version affect parsers; preflight step in run was 70s including setup, audit only not isolated |
| Generic pure PHP regressions | Pure PHP behavior can depend on 8.1 vs 8.5, extensions and Unicode/ASCII normalization | Two PHP source plus three real DB full suites | MOVE only proven DB-independent repetitions from real DB jobs; KEEP PHP version-specific tests | Mandatory 8.1 Unicode and 8.5 ASCII source jobs, centrally registered PHP regressions, aggregate gate | High until per-test environment classification; 351 PHP PASS on earlier PHP 8.5 sample, 17 context SKIPs |
| Native MySQL/MariaDB migrations and installation | MySQL 8.4, MariaDB 10.11/11.4 SQL semantics, transactions, schema, first install | Three database jobs with common disposable fixture | KEEP each engine | All three required DB jobs, installer and migration PASS with real disposable DB | Critical: engine coverage cannot migrate to source job |
| Authenticated HTTP, CSRF, access, session races and concurrent writers | Real HTTP server, shared session/DB fixture, Chromium on MySQL | Disposable DB workflow jobs, live Chromium on MySQL | KEEP variants whose HTTP/schema behavior is engine-sensitive; CONSOLIDATE only proven overlaps | Database job contract plus MySQL required Chromium journey; cannot replace with mocked HTTP | Critical, security and scheduling bugs; initial 2026-10-11 samples insufficient |
| Node syntax/regressions | Node 22; some fixtures require Chromium, others do not | Five full profiles and separate browser-map | MOVE only pure Node work to one mandatory source owner; KEEP browser-capability-dependent fixtures | One mandatory Node runner within centrally owned full audit, independent browser-map for Chromium | Medium; Node 45 PASS in 38141952073 PHP 8.5 job, not per-fixture costs |
| WinApp Python regressions | Python runtime/OS paths, child subprocesses; largely no database | Five broad full profiles | CONSOLIDATE only engine-independent repeats; preserve OS-specific requirements | Named mandatory central source job with WinApp PASS, not silently skipped | Medium; 134 PASS, 1 SKIP in 38141952073 sample |
| Runtime route/performance probes | Database-backed routes need disposable DB, isolated source fallback can SKIP | Five full audit runs | KEEP database-required probes in a disposable job; investigate pure route source overlaps | At least one DB-qualified route probe and PHP compatibility probes where meaningful | High until probe-by-probe SKIP/dependency accounting |
| deploy_app_packaging_test.php negative scenarios | Bash/PowerShell installed; crafted dirty source/ZIP and forbidden production members | PHP regression tree (five full profiles) | KEEP negative-path suite even if moved to fewer owners | Central PHP regression owner with explicit negative assertions; separate OS positive packaging still mandatory | High; positive package jobs do **not** test these negative cases |
| Positive production packaging | Bash on Linux/macOS, PowerShell on Windows, manifest membership | Dedicated three OS jobs | KEEP all three | Required package matrix, exact generated manifest and aggregate gate | High, cross-OS path/quoting differences; approx. 22s Linux / 28s macOS / 44s Windows job wall in run 38140343512 |
| Isolated browser-map fixture suite | Chromium discovery and Node/browser runtime, no live DB journey | Required Chromium job | KEEP separate browser owner | Browser-map required job PASS with unavailable-browser failure | High, cannot substitute real MySQL login journey; browser job approx. 72s in run 38140343512 |

Pending decision: collect exact per-suite environment dependencies and reliable test-only
timings before reducing any execution. Never treat optional SKIPs as coverage,
duplicate array keys as duplicate runs or job wall time as billable CI usage.

## Phase 2c: per-test hosted ownership proof and required-PASS hardening (2026-10-11)

**Implementation scope:** source-only, *no reduction of executed tests*, no new
audit profile or YAML selector, and no change to candidate/release workflows,
required contexts, or the historical candidate's `full` fallback. The existing
disposable database wrapper has been hardened to require individual structured
`PASS` evidence from each proven mandatory PHP/HTTP/DB test. The central
audit remains the sole test dispatcher.

### Provenance of the per-test sample

Downloaded the actual `latest.json` artifacts for **successful run
[38143567033](https://github.com/klusik/PHP_gallery/actions/runs/38143567033)**
on candidate **d0eaa48798c7121869d85f7245953f7a08cc2ffe**, comparison base
**5509c865ede3f54df87024ac8692fa5e3166ea1a**. These are **real hosted
GitHub Actions artifact reports**, not synthetic fixtures and not a controlled
performance comparison.

| Profile / job | Artifact ID | PHP children PASS / SKIP / total |
| --- | ---: | --- |
| PHP 8.1 Unicode, source-only | 11703194669 | 351 / 17 / 368 |
| PHP 8.5 ASCII, source-only | 11703927448 | 351 / 17 / 368 |
| PHP 8.3, MySQL 8.4, live Chromium | 11703792843 | 367 / 1 / 368 |
| PHP 8.3, MariaDB 10.11 | 11704002415 | 365 / 3 / 368 |
| PHP 8.5, MariaDB 11.4 | 11703613211 | 365 / 3 / 368 |

All five PHP suites had overall `PASS`, but a suite `PASS` is **not**
proof that every PHP child passed: real-DB tests `SKIP` without a disposable
database in both source compatibility jobs. The two live Chromium children
`PASS` only in MySQL 8.4 and `SKIP` in the two MariaDB jobs as designed.
`cli_http_boundary_test.php` was `SKIP` in all five profiles: do not silently
promote that to a required PASS or presume a defect without inspecting its
own opt-in contract.

### Individual required owner ledger

Source inspected for the named tests and shared disposable fixture.
**Environment common to the three real-DB jobs:** Linux Ubuntu 24.04;
PHP 8.3 for MySQL 8.4/MariaDB 10.11, PHP 8.5 for MariaDB 11.4;
`pdo_mysql,pdo_sqlite,curl,gd,zip,dom,mbstring,intl` provisioned, Node 22,
Python 3.12. Installed extensions are **not automatically individual
dependencies**; direct/indirect dependencies noted below come from the test
code and its fixture. These 14 share a disposable migrated database (unless
a test provisions another database); most mutate fixture state and are
serialized via `audit_php_registry.php`. No individual test is moved here.

Columns `PASS` and seconds are in **MySQL 8.4 / MariaDB 10.11 /
MariaDB 11.4** order. Every row below is `SKIP` in both source-only
compatibility jobs, and `PASS/PASS/PASS` in the real-DB jobs.

| Exact PHP child | Concrete dependency and regression detected | Real DB child seconds (3 jobs) | Decision and mandatory future owner |
| --- | --- | --- | --- |
| database_engine_contract_test.php | Two live PDO connections, InnoDB/utf8mb4, SQL JSON/vote constraints and locks; no browser/HTTP | 0.06 / 0.06 / 0.06 | KEEP in all 3 DB engines; engine SQL PASS required; critical |
| installer_first_install_test.php | Fresh MySQL-compatible schema, migrations, PHP HTTP child, cURL, advisory lock and rollback; owned database | 4.05 / 1.03 / 1.35 | KEEP in all 3; migration/install PASS; critical |
| gallery_workflow_integration_test.php | Authenticated HTTP, PDO, cURL, ZipArchive, CSRF, uploads, media, lifecycle and original hashes | 2.22 / 1.92 / 2.04 | KEEP in all 3; HTTP/security PASS; critical |
| public_visual_preview_workflow_test.php | Authenticated/anonymous HTTP, PDO and cURL; no-store privacy, CSS inheritance and media denial | 1.92 / 1.73 / 1.77 | KEEP in all 3; preview/access PASS; critical |
| theme_visual_background_workflow_test.php | Shared PDO, authenticated HTTP, cURL uploads; Theme backgrounds and recovery | 0.68 / 0.60 / 0.76 | KEEP in all 3; real Theme persistence PASS; high |
| gallery_image_move_crash_test.php | Shared HTTP/PDO, forced subprocess death, mutation journal and file recovery | 3.73 / 3.40 / 3.66 | KEEP in all 3; crash recovery PASS; critical |
| viewer_phase07_mysql_concurrency_test.php | Independent PDO processes, live SQL locks and transaction races; no Chromium | 0.46 / 0.41 / 0.46 | KEEP in all 3; concurrency PASS; critical |
| gallery_edit_concurrency_mysql_test.php | Shared PHP HTTP/PDO, independent writers and edit reservations | 1.41 / 1.26 / 1.56 | KEEP in all 3; writer/race PASS; critical |
| admin_operation_keys_http_test.php | Authenticated HTTP/PDO, idempotent replay ledger and GET_LOCK, multipart/cURL | 1.84 / 1.65 / 2.14 | KEEP in all 3; duplicate prevention PASS; high |
| public_content_widget_db_workflow_test.php | Independent live PDO handles, SQL migration rollback and widget persistence, no HTTP browser | 0.13 / 0.11 / 0.11 | KEEP in all 3; schema/concurrency PASS; high |
| public_content_widget_admin_http_workflow_test.php | Shared real HTTP/PDO, separate authenticated sessions and widget/Theme persistence | 2.47 / 2.17 / 2.32 | KEEP in all 3; auth/CSRF + persistence PASS; high |
| breadcrumb_workflow_integration_test.php | Shared HTTP/PDO, Theme/gallery writes and Trash restoration | 2.58 / 2.26 / 2.53 | KEEP in all 3; serialized state restoration PASS; high |
| content_language_workflow_integration_test.php | Shared HTTP/PDO, persisted languages/translations and navigation state | 1.40 / 1.24 / 1.41 | KEEP in all 3; language lifecycle PASS; high |
| session_route_contention_test.php | Live MySQL inputs, actual route requests, PHP processes, session-lock timing/serialization | 11.49 / 8.41 / 9.08 | KEEP in all 3; session contention PASS; critical |

The two additional real-app Chromium children
`gallery_workflow_browser_test.php` (21.17s) and
`public_widget_persistence_browser_test.php` (7.84s) returned
`PASS` only in the PHP 8.3 / MySQL 8.4 job and intentional `SKIP`
in the other four. They require Chromium, Node, the shared real migrated
HTTP/PDO fixture and exclusive fixture access. Both remain mandatory PASS
when that MySQL job enables `GALLERY_WORKFLOW_BROWSER`. The separate
`browser-map` 34-fixture Chromium owner must also remain required.

The wrapper now verifies **all 14** named real-DB PHP children, plus the
two browser children when enabled, against the central
`php-regression.details.tests` JSON records. This is a status
enforcement change, **not a new test runner, selector or additional
execution**. Unknown, missing, duplicated, malformed, nonzero-exit,
timed-out, `SKIP`, `BLOCKED` and `FAIL` required child reports
cannot yield a successful wrapper. An unrelated optional `SKIP`
remains allowed when all mandatory children have a real `PASS`.
The unchanged runtime probe gate still requires **11 PASS and 0 SKIP**.
`tests/audit_runner_test.php` contains synthetic positive/negative
regressions for the structured guard; these fixtures are not production
hosted provenance.

### Expensive general executions: no move yet

| Test or suite | Actual owner and dependency evidence | Observed seconds in source 8.1 / source 8.5 / MySQL / MariaDB 10.11 / MariaDB 11.4 | Status and conservative proposal |
| --- | --- | --- | --- |
| deploy_app_packaging_test.php | Exclusive PHP child invokes real Bash and PowerShell wrappers, owned dirty staging, ZipArchive negative membership/security assertions; all five jobs are Linux, distinct from three positive OS package builders | 35.01 / 44.83 / 51.07 / 50.24 / 43.40 | Five PASS. KEEP negative cases. Candidate MOVE only after a new mandatory exact-SHA owner and PHP/platform proof, never replace with positive packaging |
| runtime_module_plan_test.php | PHP version-specific dynamic runtime loading, owned subprocess comparison children, generated module freshness; no live DB/HTTP | 15.14 / 21.88 / 21.06 / 20.67 / 21.28 | Five PASS. KEEP both PHP 8.1 and 8.5; DB-job repetition is a MOVE candidate, not yet approved |
| node-full | Node 22, explicitly registered non-browser test scripts, 45 PASS in each of five profiles | Per-child durations available in the same artifacts; aggregate Node suite spans overlap parallel PHP scheduling | CONSOLIDATE candidate only after all per-fixture environmental and ownership checks |
| winapp | Python 3.12, platform/process filesystem contracts, 134 PASS and one environment SKIP in each of the five jobs | Per Python unittest timing not individually provided by these central reports | CONSOLIDATE candidate, but first classify the one SKIP and OS dependencies |
| Source, manifest, MVC, mutation, PHP/JS syntax | Full central audit and candidate preflight; PHP parser/version differences are real, manifest/inventory code path is common | Suite durations available per job in report JSON | MOVE only verified invariant work; retain oldest/newest PHP syntax compatibility and preflight |
| Standalone browser-map | Chromium required in its dedicated owner; 34 browser fixtures are `SKIP` inside all five general full jobs | Required browser job separate from these five reports | KEEP the independent required Chromium job and live MySQL browser journey separately |

`breadcrumb_workflow_integration_test.php` was declared twice with
the **same** `timeout=180` configuration in `audit_registry.php`.
One redundant PHP array declaration is removed; it did **not** execute
the test twice and cannot be counted as speedup.

### Security and execution decision

Source branch `candidate-preparation.yml` runs the full candidate
preparation from its mutable branch and its gate has
`GITHUB_TOKEN` with `checks:write`. Thus same-app custom check-runs,
even perfectly correlated to the exact candidate SHA, are **not** an
independent attestation of approved workflow code. Maintain
`workflow-authority-not-independently-attested` and `scope=full`
for historical candidate classification until #142/#101 reviews a
protected immutable workflow/token authority. Do not add a caller-set
trust flag or partial GitHub API proof.

Current implementation does **not** alter five `full` executions,
three DB engines, PHP 8.1/8.5, full release audit, OS packaging,
Chromium jobs, `Complete required CI matrix`, the final
unchanged-HEAD gate or any branch/ruleset. Real reductions are **OPEN**.
Observed child wall times on one SHA cannot predict job-level savings
or provider-billable minutes. Known 2026-10-11 browser/CSRF fixes (#178)
were different source revisions, not same-SHA flakiness proof; historical
bug-detection evidence for the other individual children is **UNKNOWN**.

**Next (#95):** inspect every proposed moving Node/WinApp/negative
packaging child for OS, PHP version, extension, browser and shared
fixture assumptions; identify one mandatory PASS owner and a failure
test for missing/forbidden SKIP; use same-SHA comparable runner samples
before any actual consolidation. The script-level guard added here is
prerequisite hardening, not a claimed performance improvement.

### Phase 2c contract regression repair

The first source commit `d70f130d899024db9cf67bfc2b01cf98d2b8d081`
failed hosted `release-preflight` in [run 38145342626](https://github.com/klusik/PHP_gallery/actions/runs/38145342626)
at `tests/gallery_workflow_ci_trigger_policy_test.php`. The CI contract
fixture previously extracted the real runner's mandatory-test selector
using an exact source-text `foreach ($requiredTests as $test)` sentinel.
Replacing the old free-text log parser with structured JSON evidence removed
that loop, so the old fixture blocked before any candidate generation. This was
a genuine stale regression contract, not random test flakiness; PHP and JS syntax,
changed declaration documentation, runtime policy and source inventory passed.

The regression fixture now stops selection extraction immediately before the
new structured `missing_required_php_test_passes()` validation call, asserts
that the mandatory JSON validator is invoked and the old text-log matching
is absent, and tests the extended fourteen-DB-child plus two-Chromium owner
lists under real environment flags. The original inert bootstrap permissions,
full-profile, protected browser and required check assertions remain intact.
No workflow or gate behavior is weakened to get green results. A new source SHA
is required for hosted qualification; the failed older SHA is not qualified.

## Phase 2d: hosted mandatory PASS for expensive general regressions (2026-10-11)

**Decision: do not move execution yet.** This is a small safety/diagnostic
contract change. The existing central full/release PHP regression now uses
the Phase 2c structured mandatory child validator against two entries in
the central audit registry whenever executed in GitHub Actions. It requires
individual PASS/zero exit/no timeout for *deploy_app_packaging_test.php* and
*runtime_module_plan_test.php*. Missing, duplicated, SKIP, BLOCKED, FAIL
or internally inconsistent evidence cannot yield a hosted PASS. An invalid
coverage registry blocks the suite. No extra execution, selector, profile,
YAML workflow, required status, package job or release behavior was added.
Quick and local diagnostic profiles keep their existing coverage contracts.
Other optional source-only DB/HTTP and browser SKIPs remain allowed.

Why the guard matters: *audit_run_php_regression()* historically considers
an overall PHP suite PASS when it has no failed/blocked children, even if
individual children SKIP. The packaging test can print an explicit SKIP for
unavailable PowerShell link or file symlink fixtures. The process status
classifier recognizes these as an individual SKIP, but the old aggregate
could remain green. This change refuses that hidden loss of proof in
GitHub-hosted full/release CI. The same structured validator from Phase 2c
is reused; the audit registry remains the sole orchestration owner.

### Source-inspected per-test ownership: first two expensive candidates

| Evidence | deploy_app_packaging_test.php | runtime_module_plan_test.php |
| --- | --- | --- |
| Actual assertions | Real Bash and PowerShell deploy wrappers create folder and ZIP packages from a dirty owned source; SHA-256 and canonical inventory equality; forbidden media/secrets/unlisted WinApp files, existing/unsafe destinations, missing source, FTP test inclusion, interactive Windows collision and filesystem link protections | Generated route dependency graph freshness; composed and legacy module loading; actual route handler preparation; include order, dependency closure, no eager updater or diagnostics loading, no bootstrap output/header/session/umbrella side effects, and dynamic callback review |
| PHP / extensions | Current PHP_BINARY runs manifest/release-file tools; ZIP comparison directly uses ZipArchive extension; also filesystem, hashing, JSON and process capture. No observed live PDO, MySQL/MariaDB or HTTP server dependency | PHP_BINARY runs one child for each module and composed/legacy mode, via four-worker pool; generated module compiler and PHP runtime module loading are version-sensitive. Source compilation and tokenizer/other transitive extension contracts should be verified before removing a PHP version. No live DB connection or HTTP request is performed in the inspected fixture |
| Node / Chromium | Neither is invoked by the test | Neither is invoked by the test |
| OS / external tools | Discovers Bash and PowerShell; Unix SymbolicLink vs Windows Junction and source file symlink ability are materially different. All five broad profiles run on Linux, so they do not prove full Windows negative-path execution | Child module PHP loads use current PHP runtime and actual filesystem include order; no shell/Node/browser requirement; OS path semantics warrant caution |
| Fixture / concurrency | Random isolated temporary dirty tree and package outputs, bounded subprocesses, cleanup; exclusive serial execution in audit_php_registry.php; timeout 600s | Tracked generated module plan, fresh PHP processes for each module, nested four-worker comparisons; exclusive serial execution; timeout 240s |
| Current mandatory CI owner | Both source PHP jobs and all three DB/HTTP full jobs; Linux/Windows/macOS positive package jobs are separate and cannot replace negative-path suite | Both source PHP jobs and all three DB/HTTP full jobs; PHP 8.3 is repeated under two different DB-engine jobs but exact environmental equivalence is not demonstrated |
| Observed per-child status | PASS in all five prior sampled profiles | PASS in all five prior sampled profiles |
| Observed seconds | 35.01 / 44.83 / 51.07 / 50.24 / 43.40 | 15.14 / 21.88 / 21.06 / 20.67 / 21.28 |
| PASS / SKIP / BLOCKED | PASS requires successful negative Bash/PowerShell paths and no emitted SKIP; missing optional shell/link path may report SKIP; nonzero child/timeout is FAIL; hosted full now rejects missing/SKIP | PASS requires complete compiler and every fresh-process route comparison; no intentional SKIP; missing/timeout/nonzero child fails, and hosted full explicitly rejects a missing or reported SKIP |
| Recommendation / risk | KEEP, potential MOVE only after proven mandatory replacement owner and OS/extension coverage. High security/OS risk | KEEP, possible per-identical-PHP-runtime consolidation only after verified source/environment equivalence. High runtime-compatibility risk |

Time columns are PHP 8.1 Unicode source / PHP 8.5 ASCII source /
PHP 8.3 MySQL 8.4 / PHP 8.3 MariaDB 10.11 / PHP 8.5 MariaDB 11.4.
They came from actual hosted candidate 38143567033 artifacts; the later
candidate 38145583170 also confirmed five individual PASSes. These are
not controlled A/B savings. Specific historic defects first caught by
these tests beyond the documented assertion purposes are NOT VERIFIED.

Positive and negative regressions in existing *audit_runner_test.php*
include two required PASS children, a missing module child, a forged
nonzero-exit PASS, and packaging SKIP/BLOCKED/FAIL. Existing Phase 2c
fixtures test duplicates, timeout and inconsistent totals. A source
contract checks that the existing central runner invokes the validator.
Qualification of these new tests requires fresh GitHub-hosted CI; no
synthetic fixture alone qualifies the source.

### Initial Node and WinApp ownership findings

The central audit selects *node-full* from the explicit non-browser
Node registry entries. Browser-flagged scripts are reserved for the
separately required Chromium browser-map suite. Non-browser Node scripts
can still generate ZIPs, invoke PHP, or depend on a platform/fixture, so
a non-browser marker alone is not proof of independence. In hosted
run 38145583170, PHP 8.1 source executed 45 PASS/0 SKIP in 8.91s.
Per-file Node implementation and transitive dependencies remain OPEN
before changing any of its five repeated full invocations.

The WinApp suite is one Python unittest discovery subprocess over
winapp/tests/test_*.py, not independent central child records per Python
test. The same hosted source job reports 134 PASS and one SKIP, 2.92s.
The exact identity and platform significance of the skipped unittest
is NOT VERIFIED. Do not consolidate five WinApp invocations until the
individual skipped fixture, Python/OS/subprocess/resource constraints
and a required owner with fail-closed PASS proof have been reviewed.

**No first execution MOVE has been authorized or measured.** Normalization,
PHP 8.1/8.3/8.5 module behavior and real negative package/OS capabilities
need explicit proof before any candidate owner reduction. The current
GITHUB_ACTIONS environment guard is only a hosted test-coverage switch,
not an independent trusted workflow authority. Historical generated
candidate provenance remains scope=full with reason
workflow-authority-not-independently-attested (#142). Maintain the
unchanged-head gate, three real DB engines, real/browser fixtures,
positive OS packaging jobs and full release qualification.

## Phase 2e: first narrow CI execution consolidation (2026-10-11)

Scope: only the repeated read-only `manifest` suite in the three hosted disposable-database `full` jobs. No PHP, Node, WinApp, browser, syntax, database, HTTP or security child test is omitted, and no separate test selector, new profile, workflow list or runner has been introduced.

### Owner equivalence and the exact candidate contract

The central `manifest` suite calls `scripts/prepare_candidate.php --check`, which invokes the existing generated module plan, Git-index production membership, and complete-byte manifest freshness checks in three independent PHP child processes. Source inspection found no live MySQL/MariaDB, HTTP, Chromium, session or authentication input to those canonical generators. The preflight already executes precisely this suite on Ubuntu 24.04 / PHP 8.3 after verifying the exact prepared checkout SHA. The three disposable database jobs use the same checked-out candidate ref and depend on that preflight.

The canonical `candidate-preflight` profile must have an individual manifest PASS, not an aggregate suite PASS with a concealed SKIP, BLOCKED, FAIL, missing or duplicate manifest task. The existing native `ci-gate` already requires a successful preflight, all three disposable database jobs, both PHP source variants, Chromium and every positive OS packaging job. The candidate-preparation terminal unchanged-HEAD check and both exact-SHA custom checks remain mandatory. Their presence does not independently establish trusted historical workflow authority: #142 remains OPEN.

All PHP 8.1 Unicode and PHP 8.5 ASCII source `full` executions retain their own independent `manifest` checks. The authoritative `release` profile and ordinary/local `full` also retain the suite. Only the bounded GitHub-hosted disposable database environment may delegate manifest execution to the mandatory preflight. Unexpected environment, missing owner registry, or altered non-full profile fails closed to the unchanged complete suite selection. The central JSON report explicitly lists `delegated_to_preflight: [manifest]`; it does not fabricate an individual local PASS.

### Source-backed baseline from hosted candidate 38149539308

| Audit owner | `manifest` suite time | Baseline outcome |
| --- | ---: | --- |
| Required exact-SHA candidate preflight (PHP 8.3) | 11.23 s | PASS, 1,145 PHP and 203 JavaScript syntax files also passed |
| PHP 8.1 Unicode source full | 12.81 s | PASS; manifest retained |
| PHP 8.5 ASCII source full | 11.62 s | PASS; manifest retained |
| PHP 8.3 MySQL 8.4 disposable full | Executed, individual suite time not recovered here | PASS; targeted delegation |
| PHP 8.3 MariaDB 10.11 disposable full | Executed, individual suite time not recovered here | PASS; targeted delegation |
| PHP 8.5 MariaDB 11.4 disposable full | Executed, individual suite time not recovered here | PASS; targeted delegation |

Thus the planned aggregate count for this single suite is six to three across the hosted matrix, with no new job. The observed 11-13 second figures are individual source/preflight suite samples, **not** before/after elapsed-time or billable-minute savings. Runner jobs execute concurrently. The first post-change candidate run must verify the actual three delegations and three retained mandatory PASSes and provide matched per-suite/job evidence before any speedup claim.

### Negative and positive coverage contracts

`tests/audit_runner_test.php` exercises the real central ownership helper with valid hosted DB context, all nine independently invalid context keys, non-full profiles, missing preflight or release owners, and required-suite PASS against SKIP/BLOCKED/FAIL/missing/duplicate evidence. `tests/gallery_workflow_ci_trigger_policy_test.php` statically enforces that the actual reusable workflow has the required same-SHA preflight, both source/database `needs: preflight` dependencies and the unchanged native aggregate required owner.

Hosted `php-regression` continues to require actual individual PASS/exit code zero/no timeout for `deploy_app_packaging_test.php` and `runtime_module_plan_test.php` in all five full profiles. All 14 DB/HTTP/race children remain mandatory in all three databases; two real Chromium PHP children remain mandatory in MySQL; 34 browser-map fixtures and Linux/Windows/macOS positive packaging owners are unchanged. `cli_http_boundary_test.php` retains its documented partial Apache SKIP semantics.

### Outstanding work

Confirm the new exact candidate SHA, 13 required native jobs, terminal unchanged-HEAD gate, both check-run IDs and their exact publisher/external-ID linkage. Fetch all five resulting audit reports, verify three explicit delegations and preserved 14/2 mandatory PHP test passes, then compare the observed suite, job and workflow elapsed times to the prior single-run baseline. Independently validate Node/WinApp fixture ownership before any additional MOVEs. Do not expand the optimization solely to chase a few seconds.

## Phase 2f: preserve final checkout integrity after Phase 2e delegation (2026-10-11)

Phase 2e moved the generated-artifact freshness suite from the end of the three disposable DB `full` audits to their mandatory exact-candidate preflight. Source review established an important sequencing difference: the preflight runs **before** the database/HTTP/race regressions; `audit_source_identity()` captured the initial checkout only, and the non-release profile had no subsequent source-identity comparison. Consequently, a later test-side mutation could invalidate generated inputs without being noticed by the already successful preflight.

The smallest correction is a read-only, **post-test checkout integrity** task in the existing central `scripts/audit.php`, executed only when `delegated_to_preflight` is nonempty. The common `audit_source_identity()` resolver is called again after all suites. A new pure helper in `scripts/audit_lib.php` requires valid before/after SHA evidence, the same HEAD, unchanged comparison ref and requested base, and an unmodified Git working tree at both endpoints. Missing/unreadable evidence BLOCKS; proven dirty/moved checkout FAILS. The result is a separate machine-readable task, `delegated-manifest-post-test-integrity`, not a fake `manifest` PASS. An unexpected failure propagates through the existing `overall_status()` and mandatory native DB job gate.

This check reuses existing Git identity probing and does **not** rerun the generators, fetch artifacts, add YAML steps, introduce a runner/registry/selector, move other tests, or modify preflight/source/release owners. It is not an independent substitute for reexecuting every generator under every database job's environment; the delegation still relies on the documented deterministic, database-independent generator-input assumptions and required PHP 8.1/8.5 source `manifest` owners. Git's ordinary porcelain status intentionally does not inspect ignored local files, which are outside the tracked source/inventory proof. Native full/release and local `full` continue to run the original complete `manifest` suite.

Positive and negative regression contracts cover unchanged clean checkouts; dirty initial/final worktrees; moved HEAD, comparison SHA or requested base; and missing/malformed Git identity. The regression also checks the task is wired into the existing central runner before its final status calculation. This change **restores a safety check**, removes zero additional executions and must not be counted as a performance speedup. Historical candidate trust remains `scope=full` / `workflow-authority-not-independently-attested` (#142).

**Qualification status at authoring:** the new commit and its exact prepared SHA require a fresh complete hosted CI run before this guard can be marked qualified. No claims about updated workflow elapsed or runner-job wall times are made from static fixtures.

## Phase 2g: Node/WinApp execution ownership, KEEP decision (2026-10-11)

This is a source/dependency and previously hosted artifact review, **not** an additional
execution reduction or a newly qualified candidate. Phase 2f remains the last completed
runtime change. The comparison is the exact-candidate Phase 2f run
[38158073314](https://github.com/klusik/PHP_gallery/actions/runs/38158073314)
on SHA `3a48d75594e0ed95165e751ea30905ccb72b7be2`, **before** the
branch was synchronized with newer `develop` work. The old run's PASS cannot
qualify any subsequent SHA.

### Actual dependencies and observed child costs

| Registered execution | Inspected dependency / capability | Historical hosted child time | Decision |
| --- | --- | ---: | --- |
| `release_completion_test.mjs` | Transitive `tests/support/release_lifecycle_fixture.mjs`: real temporary Git checkout, bare remote, subprocesses, commit/ref/tag operations, local filesystem and release-policy source files | about 7.12-9.66 s | KEEP |
| `release_draft_test.mjs` | Reuses that same real Git lifecycle fixture; validates create-only draft/release security semantics | about 1.74-2.70 s | KEEP |
| `linear_release_graph_test.mjs` | Independently builds real Git objects, tags, branches and merge-tree scenarios in an isolated temporary directory | about 0.34-0.55 s | KEEP |
| `gallery_download_zip_test.mjs` | Actually writes a ZIP to a bounded temporary path; tests streaming, CRC, SHA, archive completion and negative cases | Not extracted independently from this sample | KEEP |
| `gallery_download_zip64_test.mjs` | Actually writes and reads a 65,535-entry ZIP64 archive; Node filesystem and memory pressure are part of the assertion | about 4.99-10.19 s | KEEP |
| `frontend_operational_policy_test.mjs` | Reads real production JS modules and source; executes VM/DOM policy seams, not just a static filename assertion | Not extracted independently from this sample | KEEP |
| `hosted_release_policy_test.mjs` | Imports real release-promotion and authorization validators and reads current workflow/repository sources; mocks external GitHub records, not source behavior | Not extracted independently from this sample | KEEP |
| WinApp's eight registered Python modules | Real temporary files and installer/Windows API mocking, including platform-sensitive update scenarios | Whole suite about 2.66-2.97 s | KEEP |

The central registry has **79 distinct Node entries**: 45 non-Chromium
`node-full` entries and 34 mandatory separately owned `browser-map`
Chromium entries. The last qualified matrix used hosted Node `v22.23.3`.
All five full PHP profiles reported 45 Node PASS / zero Node SKIP;
the separate mandatory browser-map reported 34 PASS / zero SKIP.
The Linux WinApp owner reported 134 PASS and one documented intentional
Windows-native installer smoke SKIP; Linux mocks do not prove that native case.

`browser=false` is only a Chromium capability classification, **not**
a pure/environment-independent declaration. In particular, real Git,
OS filesystem, archive size, process spawning, PHP-specific integration,
environment variables and actual transitive source imports need review
before moving a given test to a different required job. The five profiles
use different PHP and database contexts even when their hosted Node major
version is the same. A matching Node version does not independently establish
identical `PHP_BINARY`, OS facilities, resource pressure or fixture state.

### Why there is no additional MOVE in this increment

No current exact-candidate required owner has been proved to replace each
tested environment and negative-path assertion of these candidate tests.
The non-browser Node suite's observed slow fixtures are not sufficient to
prove a critical-path reduction. The complete WinApp suite costs under
three seconds per observed job, so adding relocation selectors, cross-job
evidence and trust conditions could cost more to maintain than it saves.

The decision is **KEEP all existing Node and WinApp executions**.
There is no new selector, profile, workflow YAML, suite registry, required
status, skip policy or CI delegation. No source-test count or wall-clock
speedup is claimed. For any later MOVE require: exact candidate SHA,
approved and independently trusted required owner, environment/OS/PHP/Node
capability equivalence, retained per-child PASS/FAIL/BLOCKED/SKIP semantics,
negative missing/duplicate/stale/timeout evidence and meaningful measured
critical-path or total-runner improvement. See #95 and #142 for ownership
and the unresolved workflow-authority boundary.

### New develop integration and qualification boundary

The #185 feature's twelve maintainer-authored source commits were replayed
in order over `develop` SHA `84a651672497c099bb4d37ff7987891c6cfeb86d`.
The nine historical bot-generated candidate commits were intentionally
excluded: their hashed `app/core-manifest.json` would conflict with the
newer release's manifest, and old generated identity must not be reused.
All authored file revisions and commit messages were retained; the
existing candidate-preparation pipeline must generate fresh runtime
artifacts, inventory and complete-file manifest and then run **new**
full exact-SHA hosted qualification. Prior qualified SHAs remain only
historical evidence, not transferable successful checks.

## Phase 3: approved lifecycle-level qualification reuse, and Phase 3a read-only tree evidence (2026-10-11)

### New priority and measured execution overlap

The latest maintainer target is **a complete integration qualification on every authorized
pre-release integration fast-forward of the prepared candidate to `develop`**.
The full matrix there must execute final browser, database and WinApp coverage. The earlier proposal to reuse
same-SHA candidate evidence on `develop` is **superseded and cancelled**; same-SHA
feature-to-develop reuse is not an optimization target. For a later exact-Q release, the
proposed gate may reuse only individually eligible child evidence from the
successful full develop qualification, while newly executing the release delta
and all release-specific checks. This does not approve a broad whole-tree rerun
merely because release notes, manuals or generated release artifacts differ:
reusability must be based on independently approved, complete per-profile
executable-input closures. That closure policy is not yet available, so current
full matrices remain active. All Phase 0–2h KEEP decisions remain applicable
inside the full develop matrix.

The maintainer's subsequent clarification distinguishes a pure post-release FF
to already qualified Q. That synchronization needs no new test matrix and retains
Q's completed release qualification. It neither substitutes feature evidence for
the earlier D integration nor qualifies additional parallel develop work. The
existing #101 reconciliation keeps refusing parallel advancement; no extra
post-release dispatch or credentials change is part of this target.

A real current-lifecycle sample, with independently fetched source/run identities:

| Stage | Immutable input | Actual workflow run | Observed elapsed | Current work |
| --- | --- | --- | ---: | --- |
| Final feature prepared integration | `ffa6b9d41d57c5d91be7aa9c82170a7946b56bb6` | [38156679344](https://github.com/klusik/PHP_gallery/actions/runs/38156679344) | 454 s | Full matrix PASS |
| Pre-release develop integration FF of same feature candidate | `ffa6b9d41d57c5d91be7aa9c82170a7946b56bb6` | [38157313277](https://github.com/klusik/PHP_gallery/actions/runs/38157313277) | 328 s | Full matrix PASS again |
| Release preparation and exact release qualification | D0 `ffa6b9d41d57c5d91be7aa9c82170a7946b56bb6` to Q `84a651672497c099bb4d37ff7987891c6cfeb86d` | [38157737907](https://github.com/klusik/PHP_gallery/actions/runs/38157737907) | 642 s | Release preparation/manuals, full PHP/DB/Chromium/WinApp and release audit |

The D0-to-Q comparison is **18 changed paths** in two commits: pinned release
origin/predecessor, version and metadata, patch notes, README/architecture/
database/testing identifiers, four maintained TeX/PDF manuals and generated
manifest. In `app/bootstrap.php`, the observed one-line source change updates
`CMS_VERSION` from `0.127` to `0.127.1`, without new PHP control flow. This is
an evidence-backed example of a *candidate for targeted release verification*;
file extension or a small textual diff alone does not prove unchanged behavior
at runtime, particularly for version consumers and package/manifest content.

The sum of these three workflow elapsed intervals is 1,424 seconds, **not** a
controlled critical-path or provider-billable duration. They occur in distinct
lifecycle steps and have different generated inputs. The develop run is retained
as evidence that the same SHA's full matrix actually executed after the develop
pre-release integration fast-forward; its execution is required by the current
target. The actual release prepare runner job lasted 197 seconds and includes document/toolchain work; exact
per-manual build costs are not separately evidenced. A later Q completion already
verifies the existing exact-Q check, immutable origin, approval and merge
parents/tree and **does not run another PHP/DB/Chromium/WinApp matrix**. The future
release-delta policy may reduce repeated release-stage execution only after its
per-profile input closure and child evidence are independently proven. Neither
five repeated matrices nor a numerical savings estimate is substantiated.

### Phase 3a implementation: content-addressed delta inventory only

The existing `scripts/audit_source_evidence.php` now owns:

1. `parse_git_raw_delta_z()`, a complete bounded NUL parser of
   `git diff --raw --no-abbrev --no-renames -z` output. It preserves each
   changed path, old/new modes, status and **old/new blob identities**, and
   rejects invalid, duplicate, truncated, escaping or oversized evidence.
   Rename detection is deliberately disabled so delete/add is visible; neither
   a filename nor a mode-only change is silently classified harmless.
2. `audit_cross_stage_tree_delta()`, a shell-free, read-only Git consumer
   validating the canonical local checkout, non-shallow readable history,
   both immutable commits, target HEAD, clean worktree, proved ancestor
   relation and exact endpoint tree IDs. It reads the complete content-addressed
   delta and rechecks the target/cleanliness after inspection. Error results
   are `evidence=BLOCKED` with stable diagnostic reasons.

Even a successful identical tree yields `evidence=RECORDED`, **always
`scope=full`**, with a clear `history_sensitive` marker. `RECORDED` means
*tree data is available*, not that original test outcomes were validated for
reuse. No GitHub check-runs, source-branch trust, historical tests, actual
runtime profiles, source-base-sensitive tests, artifact retention or release
eligibility can be inferred from this primitive. Ancestry-bound comparison does
not yet handle unrelated equivalent trees, intentionally preferring BLOCKED.
The existing registered `tests/audit_change_evidence_test.php` contains
positive same-candidate and changed-manifest Git fixture assertions and negative
malformed raw diff, duplicate/blob/mode/path, stale target, absent commit,
missing binary and dirty-checkout cases. This **does not** enable a selective
workflow, introduce another orchestrator, alter a registry, remove a test or
skip a required job. Actual hosted qualification is tracked in #185 separately.

### Minimum remaining authority and executable release-delta contract

Future reusable coverage must independently bind original candidate SHA/tree,
source run and attempt, per-suite and mandatory **individual child** results,
exact source/test/helper/workflow and their transitive imports, Git comparison
base and relevant environment (PHP/Node/Python versions, OS, DB, Chromium and
runtime capabilities). For each reused child, demonstrate that its **entire
relevant input set** remains unchanged. History/base-sensitive checks are
rerun on any changed source comparison base. Missing/expired/duplicate,
SKIP/BLOCKED/FAIL/timeout, stale, wrong-profile or conflicting evidence never
passes as reuse; an older green run never conceals a new failure.

Exact release Q requires newly executed coverage for actual changed inputs,
semantic version consumers, release metadata, notes, all four rebuilt manuals,
manifest/package membership and integrity, generated state and unchanged HEAD,
plus valid inherited individual coverage. Under the intended future policy,
unexpected production/authentication, migration, test, CI or runtime changes
refuse reduced release qualification and return the integration change to
develop for complete qualification. Current full release execution remains
mandatory until activation. The release completion check remains an independently
validated downstream proof, not a fresh full-matrix execution.

**BLOCKED prerequisite (#142):** current candidate check publishers use mutable
feature-sourced workflows with `GITHUB_TOKEN` `checks:write`. Matching App ID
15368, bot commit identity, source SHA, tree, run/attempt and aggregate PASS do
not independently authenticate approved workflow/test source. No self-attesting
branch code or caller trust flag may authorize reuse. The independently controlled
policy/provenance owner and any maintainer-only server configuration must be
reviewed and qualified separately. Until then **all current full matrices remain
required, including the full matrix on the pre-release develop integration fast-forward**.
Pure post-release FF to qualified Q retains release proof without another matrix. #95 retains
inside-matrix optimization; #101 owns protected exact-Q lifecycle; #142 owns
workflow/trust; #94 owns browser reliability.

## Phase 3 follow-up: source-event and prepared-candidate suite correlation

The existing read-only historical correlator previously compared each prepared-SHA
custom check's `check_suite.id` with the source-event run's `check_suite_id`.
Those suites belong to different commits. In actual Phase 3a run
[38165197107](https://github.com/klusik/PHP_gallery/actions/runs/38165197107),
source event `97bd4bfb00e3c615e5cbf8751e45d54fede0ffe0` has native suite
`103401329988`, while prepared candidate
`07b641815628858971a1c89a7b76acd286e60c95` has custom-check suite
`103402547358`. The former equality rejected otherwise consistent real evidence.
This is a correlation defect, not evidence that Phase 3a's actual qualification failed.

The same owner now requires complete, bounded `candidate_suites_pages` from
`GET /repos/klusik/PHP_gallery/commits/<prepared-SHA>/check-suites`, alongside
complete check-run and source-job pages. `audit_complete_hosted_pages()` also
accepts `check_suites`; it retains contiguous pages, exact counts, positive
unique IDs and the existing 500-record bound. Both required custom checks must
refer to one positive candidate suite ID. The retrieved suite must identify the
exact prepared SHA in both `head_sha` and `head_commit.id`, repository
`klusik/PHP_gallery`, Actions App ID 15368 and completed/success. The generated
candidate's suite cannot also be the source-event suite. Unrelated suites such
as queued GitHub Pages are ignored after the complete listing is validated.

The existing `audit_change_evidence_test.php` fixture now uses distinct source
and candidate suite IDs and check-run payloads containing only the real API's
nested `{id}` suite reference. It rejects missing/truncated/duplicate pages,
invalid IDs, wrong SHA/commit/app/repository, pending/red/skipped/cancelled suite
outcomes, disagreement between the two required checks, and conflated source
and candidate suites. A complete two-page listing and an unrelated queued Pages
suite remain positive correlation cases. All positive cases deliberately retain
`scope=full`, `correlated=true` and
`workflow-authority-not-independently-attested`. No trigger, mandatory job,
check context, execution registry or trust decision changes.

### Concrete #142 activation proposal: protected-main release evidence owner

**PROPOSED ONLY; not implemented, hosted-qualified or deployed.** An API-only
verifier on protected `main`, for example
`.github/workflows/candidate-evidence-verification.yml`, is owned by one
independently approved immutable main revision W. Its `workflow_run` trigger
must select completed `Isolated gallery workflows` runs only when the actual
event is a push to `refs/heads/develop`; it must reject pull requests, test-merge
refs, merge groups, dispatches and other branches. The verifier must bind the
completed run, attempt, exact candidate SHA and full develop-matrix child ledger.
At this investigation, current main was
`a013c54663d8872130e565245c913cf5552155af`; W does not yet exist with this
verifier. GitHub documents that `workflow_run` uses default-branch source and
runs only when the workflow exists there:
[workflow_run semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_run).

Use only `contents:read`, `actions:read` and `checks:read` for that owner.
It must never check out or execute candidate code, restore candidate caches,
execute artifact contents, receive publication credentials, or infer authority
from candidate check names/App IDs. It independently obtains the original run,
attempt-specific native jobs/steps and complete artifact metadata/reports;
validates source/candidate parent and generated-only delta, candidate tree,
branch/ref snapshots and actual suite/mandatory-child/environment outcomes;
and authenticates the original caller/reusable-workflow and complete transitive
audit/test/helper/fixture/toolchain inputs against policy approved at W.
A branch-selected hash, recorded candidate hash or artifact trust flag is not
approval. Any unapproved or unresolved execution input refuses reuse.

The existing two workflow YAML blobs match current protected main, but the
candidate's `scripts/audit.php`, `audit_lib.php`, `audit_registry.php`,
`audit_source_evidence.php`, `gallery_workflow_run.php` and relevant tests
differ. YAML equality therefore cannot authorize this historical run.
Source-test changes require independent approval of their exact executable
input closure; collecting their hashes alone does not supply that approval.

The concrete consumer is the existing release workflow's pre-expensive decision
and `ci-gate`, bound to exact release candidate Q. It may inherit individually
eligible child outcomes from the authenticated, successful full develop-push
report only after validating the complete approved executable-input closure for
each profile. It must execute source/base-sensitive, generated-state, changed
input, semantic version consumer, release metadata, notes, four-manual, manifest
and package checks for Q. The approved input policy must account for the
`CMS_VERSION` marker semantically: a version-only `app/bootstrap.php` blob change
must not by itself invalidate PHP/DB/browser evidence, while version consumers
and exact-Q inventory/package checks still run. An unexplained functional,
security, test, CI or workflow delta blocks release-delta qualification and sends
the integration change back through full develop qualification; it does not
authorize a reduced release gate. Until a separate policy is approved and
implemented, all current full matrices remain active. Missing proof retains
current full owners; malformed, red or conflicting proof fails or blocks
according to the owned contract. Pull request test-merge and merge-group contexts
are not release evidence origins.

Maintainer activation needs a reviewed main installation of W plus its approved
input policy, full feature-safe hosted positive/adverse qualification of the
consumer, and proof that its main-origin decision is consulted before skipping
expensive owners. No protected source/ref, environment, secret or ruleset change
is authorized by this proposal. A separate App is not automatically required for
API-only evidence validation. Conversely, removing `checks:write` from feature
YAML is not a server restriction, and an App-15368 check does not prove that this
verifier ran. If server-required independent publisher enforcement is desired,
that is a separate maintainer design, with explicit App/ruleset implications.

GitHub also limits check eligibility by event/test-merge context and documents
a seven-day required-check condition:
[required-check semantics](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks).
Do not invent a broader suite-result expiry or promise server FF acceptance from
this source proposal. The active develop ruleset currently requires strict
`Complete required CI matrix` from App 15368. Legacy branch-protection visibility
was denied to the connector and remains NOT VERIFIED.

Current status: correlation correction source-ready; the same-SHA develop
shortcut proposal is superseded and cancelled. Release-delta reuse remains OPEN
and activation-blocked on independently approved/deployed W, approved per-profile
input closures and consumer proof. The source-event reports described below
identify the original source/prepared candidate runs; they are not reports from
the actual full matrix after the candidate's pre-release develop integration
fast-forward. The historical develop run in the table above did execute that full matrix. Existing #101 exact-Q
approval/completion behavior is retained. No active workflow optimization is
authorized, and this proposal removes **zero** required develop matrix executions.
The concrete review contract is specified in
[`CI_COVERAGE_REUSE_CONTRACT.md`](CI_COVERAGE_REUSE_CONTRACT.md). This batch adds
no standalone advisory layer and does not rerun the already green Phase 3a
candidate. Its own new prepared candidate requires fresh full hosted qualification.

## Origin evidence follow-up: readable artifacts and named WinApp outcomes

After the maintainer authorized the existing local feature checkout, original
artifacts from [run 38168485907, attempt 1](https://github.com/klusik/PHP_gallery/actions/runs/38168485907)
were downloaded through the connector, checked against GitHub's SHA-256 digest
metadata, and read as data. This removes the earlier inspection limitation for
that retained report set; it does not change what the original run executed.
All prepared-candidate reports identify clean
`1fd325fe09998001737f695c7fc351edec0ee518` and comparison base
`84a651672497c099bb4d37ff7987891c6cfeb86d`.

| Original artifact / owned profile | PHP PASS / SKIP | Node PASS | Runtime probes PASS / SKIP |
| --- | ---: | ---: | ---: |
| `runtime-source-0`, PHP 8.1 Unicode | 351 / 17 | 45 | 2 / 9 |
| `runtime-source-1`, PHP 8.5 ASCII | 351 / 17 | 45 | 2 / 9 |
| `gallery-workflows-0`, MySQL 8.4 / PHP 8.3 | 367 / 1 | 45 | 11 / 0 |
| `gallery-workflows-1`, MariaDB 10.11 / PHP 8.3 | 365 / 3 | 45 | 11 / 0 |
| `gallery-workflows-2`, MariaDB 11.4 / PHP 8.5 | 365 / 3 | 45 | 11 / 0 |

These are repeated per-job outcomes, not summed unique test cases. The exact
caller/reusable workflow revision and its `strategy.job-index` artifact naming
bind the DB profiles; artifact timestamps alone do not. MySQL's two real
application browser children passed. MariaDB explicitly disabled those two
children; the standalone required Chromium artifact records 34 individual PASS
outcomes. All DB jobs retain the CLI test's partial Apache-fixture SKIP. Source
profiles lack the disposable DB/HTTP fixture, explaining their named DB skips
and nine skipped route measurements. No missing mandatory browser owner is
inferred from the sibling profiles' explicit browser opt-out.

The original WinApp tasks each report 134 PASS and one SKIP out of 135, but
retain no named Python child records or skip identity. Their successful logs
contain only unittest progress and the aggregate trailer. These historical
counts cannot be promoted to individual reusable PASS evidence or retroactively
repaired by inferring the skipped test from present source.

The existing central audit therefore now consumes structured outcomes from a
reporting adapter around standard unittest discovery. It preserves the same
test-tree selection, host isolation and outer timeout, and records canonical
test IDs, observed durations, outcomes, reasons and fixture errors/skips. Nested
subtest events retain their order, including repeated labels; a skipped subtest
cannot establish complete individual PASS coverage for its parent. The central
consumer rejects incomplete, duplicate, malformed, inconsistent or empty
evidence and checks the actual process outcome. Expected failures are explicit
non-PASS records; unexpected success, failing subtests and fixture errors remain
failures. The full/release owners actually consume this evidence; it is not an
unused historical parser, another test graph or a new suite orchestrator.

This is an origin-reporting prerequisite for the proposed verifier W, not a
release-delta reuse decision. Only newly executed reports contain the added child
records. The protected-main verifier/approved execution-input policy and its
pre-expensive/`ci-gate` consumer remain unimplemented and activation-blocked.
No workflow, required context, selector, matrix owner or #101 completion changes;
no real lifecycle matrix is removed. Current JSON plus native records identify
outcomes, but do not independently approve the transitive execution inputs.
