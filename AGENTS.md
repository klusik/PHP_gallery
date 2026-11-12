# Repository Guidelines

## Authoring Contract (Read Before Editing)

Read this and affected area rules before edits. User rules prevail; nested
AGENTS are not automatic.

**Before writing:** verify task/branch scope, find the domain owner and a
compliant declaration, and read affected contracts below.
For **every public/Admin UI change**, automatically follow
[the mandatory design contract](docs/ADMIN_DESIGN.md): compact translucent surfaces,
trigger-anchored panels, Theme tokens and action hierarchy from the approved
visitor bell/PDF/music treatment. Verify rendered desktop/narrow results without
waiting for user requests/corrections.

**While writing:** write declarations and factual permanent docs together.
Preserve comments. Fix repeated problems throughout the batch;
never weaken types, checks, limits or baselines for PASS.

**Before FULL:** complete the impact review in
[authoring](docs/AGENT_AUTHORING.md).
**Before handing off:** review diff/new files, push only the authorized branch,
inspect exact-SHA CI and state missing coverage.

## Read the applicable area before editing

| Affected area | Required instructions/reference |
| --- | --- |
| PHP/MVC/runtime/capabilities/Admin | [app rules](app/AGENTS.md), [architecture](ARCHITECTURE.md) |
| Schema/access/auth/mutation readiness | [schema policy](docs/AGENT_SCHEMA_POLICY.md), [database](DATABASE.md) |
| Browser/assets/thumbnails/lightbox/OFP | [asset rules](public/assets/AGENTS.md) |
| Every public/Admin UI, panel, popup or control | [mandatory design contract](docs/ADMIN_DESIGN.md) |
| Fixtures/children/browser harnesses | [test rules](tests/AGENTS.md), relevant [testing](TESTING.md) section |
| CLI/audit/scanners/inventory/packaging | [tool rules](scripts/AGENTS.md) |
| CI/evidence/preparation/integration | [CI rules](.github/AGENTS.md), [CI contract](docs/CI_COVERAGE_REUSE_CONTRACT.md) |
| Windows companion/installer | [WinApp rules](winapp/AGENTS.md), [readme](winapp/README.md) |
| Docs anywhere/manuals/notes | [docs rules](docs/AGENTS.md) |
| Declaration/header/policy | [examples](docs/AGENT_AUTHORING.md) |
| Compatibility | [lifecycle contract](docs/COMPATIBILITY_LIFECYCLE.md) |
| Releases | [RELEASE.md](RELEASE.md), [lifecycle](docs/RELEASE_LIFECYCLE.md), [docs rules](docs/AGENTS.md) |

Read applicable rows; scoped rules cannot waive authorization, CI or security.
[Historical CI notes](docs/AGENT_CI_HISTORY.md) grant no permission or
installation proof.

## Branch authorization and repository safety

Run all Git commands outside the sandbox: the user requires this because
sandboxed Git cannot reliably write refs or access the configured remote.

Before writes, verify branch/HEAD, exact remote repository/head, task scope and
write access. An implementation/fix request authorizes automatic feature creation
from verified `origin/develop`, checkout, commits, exact feature push and hosted
BASIC; never ask again for routine branch permission. Reuse an explicitly selected
suitable branch. Allowed: `feature/*`, `fix/*`, `bugfix/*`, `hotfix/*`, `codex/*`;
new work uses `feature/`. Missing identity/access is **BLOCKED / not CI-qualified**;
request only missing access or genuinely unclear scope.

**Never directly mutate `develop`/`main`**, by Git or API: no push, force-push,
FF, reset, deletion, ref update or merge. Integration, squash, release, tags and
publication need separate explicit authorization. Feature work grants none.
Protected writes belong to approved maintainer workflows; no protection changes
or bypass.

Check remote heads before writes. Use non-forced ordinary updates and exact refs
(`git push origin HEAD:refs/heads/feature/example`); integrate concurrent bot
commits before fixes. Never overwrite another writer. An authorized feature
squash requires an exact old-head lease and the
[checkpoint](.github/AGENTS.md#squash-checkpoint-after-preparation).
Do not routinely prune refs.

Create features with `git switch --no-track --create feature/name origin/develop`
from the verified base; never inherit that base branch's upstream.
Its first push uses `git push --set-upstream origin HEAD:refs/heads/feature/name`.
Verify `git branch -vv` after creation/push and before handoff: develop/main track
their same-named origin refs; features track only their own remote (or none before
first push). Check commit equality separately; tracking does not prove freshness.

Never commit credentials, config.php, caches, uploads or deploy archives. Use
config.example.php and existing access services. Ignored `.claude/`, `.codex/`,
`.agent/`, `.agent-local/` hold only tool paths/settings/notes; ignore new state
folders before use. Keep `.agents/`/`.github/` tracked and local state
unpackaged.
Revalidate saved tool paths before retrying missing executables.

## Code and declaration essentials

PHP 8.1+, four-space indentation, `declare(strict_types=1);` in new PHP files;
no Composer or Node build, framework-free public JS/CSS. Never use Python
`from __future__ import annotations`.

**Readability is advisory, never a CI gate.** Prefer <=80 columns; consider
splitting at 81-100, actively above 100. Preserve semantics/comments and focused
diffs; use [the complete rules/exceptions](docs/CODE_READABILITY.md).

New/materially refactored responsibilities require `Controller -> Service -> Model`.
Models own SQL/PDO/persistence/mapping; services own policy/orchestration/filesystems;
controllers own HTTP/auth/CSRF/responses/prepared view models; views own presentation.
Find existing owners; pass semantic inputs, never SQL fragments. Views cannot
discover domain policy. Never add MVC baseline debt.

[The complete authoring rules](docs/AGENT_AUTHORING.md) are mandatory, including
all declaration/type/header/policy details and narrow exceptions; this summary
grants no exemption:

- New sources need native-comment Project, Repository, tracked File, Module Type,
  Purpose, Responsibilities, Author (Rudolf Klusal), License. Correct copied paths.
- Changed/new named functions/methods need purpose, every typed/described parameter
  (defaults/nullable/variadic too), all return variants including void/None/failure,
  concrete collection/object shapes. No bare array/Object/mixed/any. PHPDoc/JSDoc
  precedes declarations; Python first-inside docstrings use typed Google
  `Args:`/`Returns:`. Native types are independent; PHP constructor/destructor
  return docs have no native return type.
- Every class, anonymous included, needs purpose docs. Anonymous callbacks/arrows
  and variables/constants/properties need no declaration docstring; nested named
  declarations do. Explicit PHP callback parameters need native types. Preserve
  comments/reasoning; no helpers just for docs or docblocks inside arguments.
- Numeric policies, tools/tests included, require named constants/config and
  purpose, `Type:` (or @var/@type), `Units:`, `Scope:`, `Consumers:`, `Rationale:`,
  value/reason at each site. Headers do not substitute; explain structural literals
  locally. Preserve scanner budgets/scope; tooling exemptions exclude no inventory.
- Changed fallbacks need reason, protected behavior/environment, owner and
  retirement proof/permanent-support rationale. Record high-impact regression/
  usage evidence (or unknown) in lifecycle docs; retain support until safe
  retirement, never infer versions/usage.

## Mandatory Agent Verification Contract

**CI-first, working-branch-only writes override local test instructions.**
`scripts/audit.php` is the sole orchestrator; no parallel YAML test registry.
No routine local audits/lint/tests/generators or repeats of green CI. Local
focused diagnosis requires an observed environment-specific CI failure,
inaccessible CI or explicit user request. Red/slow CI remains available;
local evidence never replaces hosted qualification.

Finish source/tests/permanent docs -> commit -> exact working-branch push ->
hosted **Basic source checks**. Feature pushes/final squash check BASIC syntax
and changed declarations, omitting headers/FULL inventory. Review headers before
commit; an early header gate needs a separately qualified scanner/CI change.

Integration/release require separate approval and all candidate, seal, lease,
owner, FULL, protected-FF, assembly and publication rules in
[CI instructions](.github/AGENTS.md), [coverage contract](docs/CI_COVERAGE_REUSE_CONTRACT.md),
[RELEASE.md](RELEASE.md) and [lifecycle](docs/RELEASE_LIFECYCLE.md).
Those full rules are mandatory; this summary grants no exceptions.

Hosted preparation owns modules, index inventory and complete-file manifest
hashes in dependency order. Stage every new/deleted path, including development/
docs; edit authored inputs, never modules.php. Never hash truncated Contents API
data; later edits invalidate evidence. Read compact summaries, source-failures.md
once per failed batch, then linked JSON; raw logs only for FAIL/BLOCKED/material
SKIP. Immutable bases: feature BASIC origin/main merge-base, integration observed
develop P, release delta A. Missing history/base is BLOCKED, never HEAD.

Handoff: workflow URL, branch, exact checked/prepared SHA, immutable base, required
results and missing/manual coverage. Failed/blocked/missing/cancelled/skipped/stale
mandatory checks and earlier-SHA evidence never qualify a candidate. Distinguish
BASIC-only, FULL-qualified, PR-open, merged/tagged, draft-ready and published.

## Before-commit and handoff checklist

- Review the whole diff/new files, tools/fixtures included; check declarations,
  headers/paths, types/shapes and policy comments.
- Preserve MVC, security/routes and Admin contracts; finish permanent docs with code.
- Update all four affected TeX sources or record `No TeX manual impact` with reason.
  Follow docs rules for PDFs, markers and typography.
- PATCH_NOTES.md, packages/installers and release actions require their authorized
  lifecycle. Use focused imperative commits; explain behavior, schema/filesystem
  impact and validation.
- Verify exact-SHA CI and [persisted metadata](app/AGENTS.md); state gaps.
  All GitHub titles, bodies and comments are English; chat may be Czech.

Keep root at 6-10 kB; keep lessons topical. Measure prevention; size/fixture PASS does not prove effectiveness.
