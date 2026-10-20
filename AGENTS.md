# Repository Guidelines

## Authoring Contract (Read Before Editing)

Read this contract and affected area rules before edits; nested AGENTS are not
loaded automatically. User rules prevail.

**Before writing:** verify branch authorization, find the domain owner and a
compliant declaration, and read affected contracts from the map.

**While writing:** write declarations and complete factual docs together.
Preserve
comments and update permanent docs. Fix repeated problems throughout the batch;
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
| Admin UI/design/controls | [design contract](docs/ADMIN_DESIGN.md) |
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

Run Git commands, especially ref creation, checkout, fetch and push, outside
the sandbox. The user explicitly requires non-sandbox Git because sandboxed Git
cannot reliably write repository refs or access the configured remote.

Before writes, verify branch/HEAD, exact remote repository/head, explicit branch
authorization and usable write permission. Targets: `feature/*`, `fix/*`,
`bugfix/*`, `hotfix/*`, `codex/*`; new work uses `feature/`. Missing identity,
access or authorization is **BLOCKED / not CI-qualified**; stop and request
access.

**Never directly mutate `develop` or `main`**, by Git or API: no push,
force-push,
FF, reset, deletion, ref update or merge. Creating/switching branches, squash,
release operations, tags and publication need separate explicit authorization.
Broad credentials/feature edits grant no authority. Protected writes belong to
approved maintainer workflows; do not change protections or enable bypass.

Check remote heads before writes. Use non-forced ordinary updates and exact refs
(`git push origin HEAD:refs/heads/feature/example`); integrate concurrent bot
commits before fixes. Never overwrite another writer. An authorized feature
squash requires an exact old-head lease and the
[checkpoint](.github/AGENTS.md#squash-checkpoint-after-preparation).
Do not routinely prune refs.

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

**Readability (advisory, no hard limit):** Prefer code/TeX/Markdown
at <=80 display
columns for GitHub split diffs/narrow editors. At 81-100 consider a natural
split;
above 100 actively seek one. Format lengthy arguments/collections/lists one
entry
per indented line; group conditions and separate logical steps/declarations with
blank lines. Preserve semantics, types, comments and docstrings. Allow justified
long literals, URLs, identifiers and generated content. Focus diffs; never gate
CI.
[examples and exceptions](docs/CODE_READABILITY.md).

MVC is mandatory for new/materially refactored responsibilities:
`Controller -> Service -> Model`. Models own SQL/PDO/persistence/row mapping;
services own reusable policy/orchestration/filesystem workflows; controllers own
HTTP, auth/CSRF, responses and prepared view models; views own presentation.
Find the existing owner before adding helpers. Pass semantic inputs, never SQL
fragments. Views do not discover domain policy. Never add MVC baseline debt.

New source files need leading native-comment headers: Project, Repository,
actual
tracked File path, Module Type, Purpose, Responsibilities, Author (Rudolf
Klusal),
License. Correct copied/moved paths. Headers, docs, native types and policy
explanations are independent; use checked examples.

Each new/changed named function/method needs purpose before tags,
typed/described
parameters (defaulted, nullable, variadic and added too), and every return
variant
including void/None/failure. Document concrete collection elements/object
fields,
not bare array/Object/mixed/any/wildcards. Keep the opaque-unused-PHP exception
narrow. PHPDoc/JSDoc immediately precedes declarations. Python docstrings come
first inside, using typed Google-style `Args:`/`Returns:` consistently.
Native PHP/Python types are independent; PHP constructors/destructors
document returns without a native return type.

All class-like declarations, anonymous included, need purpose docs. Anonymous
callbacks/arrows and variables/constants/properties need no declaration
docstring;
nested named declarations do. Explicit PHP callback parameters need native
types.
Do not extract helpers just for docs or put docblocks in arguments. Preserve
meaningful comments and local reasoning.

Use named constants/configuration for numeric policies, including tools/tests:
timeouts, sizes, counts, retries and defaults. At each policy site explain
purpose,
`Type:` (or @var/@type), `Units:`, `Scope:`, `Consumers:`, `Rationale:`, value
and
reason. Headers cannot replace this. Explain structural literals/test boundaries
locally. Keep scanner scope/budgets: changed-runtime exemptions for tooling do
not
exempt whole-tree inventory.

Changed fallbacks need reason, protected behavior/environment, owner and
retirement
proof/permanent-support rationale. Record high-impact regression/usage evidence
(or unknown) in the lifecycle contract. Preserve support until safe retirement;
never infer versions/usage.

## Mandatory Agent Verification Contract

**CI-first and working-branch-only writes override local test instructions.**
`scripts/audit.php` is the sole orchestrator; no parallel YAML test registry.
No routine local audits/lint/test loops/generators or repeats of green CI.
Focused
local diagnosis needs an observed environment-specific CI failure, inaccessible
CI or explicit user request. Red/slow CI is available; local evidence never
replaces
hosted qualification.

Installed cadence: finish source/tests/permanent docs -> commit -> exact working
branch push -> hosted **Basic source checks**. Feature pushes/final squash
receive
BASIC syntax and changed named-declaration docs. BASIC currently omits native
headers and FULL inventory; review changed/new headers before commit. An early
header gate needs a separate scanner/CI change and qualification.

Maintainer-approved **Integrate into develop** uses the main/feature workflow
ref
selected in GitHub, including its tests/CI; prior main installation is
unnecessary.
Select one candidate above observed develop P, prepare artifacts, obtain fresh
BASIC and run central FULL on final exact A before guarded non-force FF. Seal
execution before writing; reuse only identical candidate/base after publication
failure. Owner dispatch, all native owners and protected-write rules remain
required.

A separately authorized release trusts protected-develop A, assembles notes,
four
manuals, inventory, manifest and attachments, then opens a PR without new
BASIC/FULL.
Keep assembly, leases, approval, merge/tag, draft and manual publication
distinct;
preserve origins/tags/assets. FF develop to actual tagged main: equal trees
alone
do not prove shared history. Never create content-copy synchronization commits.

Hosted preparation owns runtime modules, index-based inventory and complete-file
manifest hashes in dependency order. Stage all new/deleted paths,
development/docs
included. Edit authored runtime inputs, not modules.php. Never hash truncated
Contents API data. Later edits invalidate earlier evidence.

Read compact summaries, source-failures.md once per failed batch, then linked
JSON.
Read raw logs for FAIL/BLOCKED/material SKIP only. Freeze base SHA: feature
BASIC
uses origin/main merge-base, integration observed develop P, release delta A.
Missing history/base is BLOCKED, never HEAD.

Handoff includes workflow URL, branch, exact checked/prepared SHA, immutable
base,
required results and missing/manual coverage. Failed/blocked/missing/cancelled/
skipped/stale mandatory checks never qualify; earlier SHAs cannot qualify a
later
candidate. Distinguish BASIC-only, FULL-qualified, PR-open, merged/tagged,
draft-ready and published.

## Before-commit and handoff checklist

- Review the whole diff/new files, tools and fixtures included.
- Correct headers/paths, declaration docs/types/shapes and policy comments.
- Preserve MVC, route/security and Admin contracts; finish permanent docs with
  code.
- Update affected sections in all four TeX sources; otherwise record
  `No TeX manual
  impact` with a reason. Follow docs rules for PDFs, markers and typography.
- Leave PATCH_NOTES.md, packages/installers and release actions to their
  explicitly
  authorized lifecycle. Use focused imperative commits; describe behavior,
  schema/filesystem impact and validation.
- Inspect exact-SHA hosted results and state missing coverage. GitHub issue
  titles,
  bodies and **all comments are always English**; chat may be Czech.

Keep root at 6-10 kB; keep lessons topical. Measure prevention per authoring;
size or fixture PASS does not prove effectiveness.
