# Contributing to PHP Gallery

Thanks for taking the time to improve PHP Gallery. Bug reports, focused fixes,
documentation improvements, and carefully scoped feature proposals are welcome.

## Before opening an issue

- Use the repository's bug report, feature request, or accessibility issue form
  when it fits. Include the exact PHP Gallery version and enough steps to
  reproduce the behavior.
- Use [GitHub Discussions](https://github.com/klusik/PHP_gallery/discussions)
  for general questions and installation help.
- Do not post credentials, private gallery links, personal data, or security
  vulnerabilities publicly. Follow [SECURITY.md](.github/SECURITY.md) for
  vulnerability reports.

## Development expectations

PHP Gallery targets PHP 8.1 and newer, has no Composer or Node build, and uses
plain PHP, JavaScript, and CSS. Read the repository's `AGENTS.md` and relevant
architecture documentation before changing code. In particular:

- Keep new and materially refactored code within the controller/service/model/
  view ownership rules documented in `AGENTS.md`.
- Follow existing naming, formatting, source documentation, and compatibility
  conventions. Do not add `from __future__ import annotations` to Python code.
  Write each named declaration and its complete contract in the same edit:
  purpose before tags, every parameter, actual return variants, concrete shapes
  and native types. Runtime policy sites independently need purpose, type, units,
  scope, consumers and rationale. Use the scanner-checked examples in
  [the authoring reference](docs/AGENT_AUTHORING.md); tag-only comments are incomplete.
- Add schema changes as new timestamp-prefixed migration files. Preserve
  upgrade compatibility and cover behavior through the central audit where
  practical.
- For any material change to user/admin behavior, architecture, runtime,
  security, deployment, CI, or developer workflows, update relevant permanent
  Markdown documentation and all four maintained TeX manual source editions
  in the same batch. Do not wait for release automation to author the prose.
  Ordinary development does not rebuild PDFs or update `PATCH_NOTES.md`.
  See `AGENTS.md` and `docs/LATEX_BUILD.md`.
- Keep changes focused. Do not include local configuration, credentials,
  uploads, caches, generated deployment archives, or unrelated formatting
  changes.
- For a new or materially changed compatibility branch, explain its reason,
  protected behavior/version/environment, owner, and safe retirement condition
  or permanent-support rationale in the change description. Record high-impact
  paths in [the compatibility registry](docs/COMPATIBILITY_LIFECYCLE.md).
  Mark unmeasured usage and uncertain dates as unknown; removal needs evidence
  that the recorded condition is met.

## Preparing and verifying an ordinary source candidate

Read the top-priority [CI-first contract](AGENTS.md#mandatory-agent-verification-contract).
Before writing, verify the current working branch/HEAD, remote head and explicit
branch-scoped user authorization plus usable write permission. Agents must never
directly mutate `develop` or `main`, even through APIs or broad credentials.
Missing authorization or access is BLOCKED before writes.

Finish source, tests, relevant permanent Markdown and all four maintained TeX
manual sources. Leave PDFs, manual version/date metadata and patch notes to
release preparation. Make atomic source commits and push only the exact authorized
`feature/*`, `fix/*`, `bugfix/*`, `hotfix/*` or `codex/*` ref with a normal push.
Check for concurrent writers and incorporate bot commits before subsequent fixes.

[Candidate preparation](.github/workflows/candidate-preparation.yml) generates
runtime plans, indexed production inventory and complete-file integrity hashes,
commits only changed artifacts, then calls full central CI for its exact prepared
SHA. Its lease and final gate refuse stale branches. Read-only candidate preflight
blocks expensive jobs for stale artifacts or source violations. Bot commits do
not trigger recursion: the caller owns the reusable qualification.

Inspect hosted summaries, required jobs and `source-failures.md`/linked JSON or
failure logs. Repair the complete batch, commit and push the same branch until
fresh required CI is green. Do not routinely prepare generated artifacts, execute
audits, run test loops or duplicate lint locally. Focused local reproduction is
exceptional for an observed environment-specific CI failure, genuinely inaccessible
CI or explicit user request. Red or slow CI is not unavailable CI; local evidence
never replaces hosted qualification.

The central audit remains the only test orchestrator in GitHub Actions. Ordinary
handoffs need full hosted coverage; release qualification uses the release profile
and matrix in `RELEASE.md`. Required FAIL/BLOCKED/SKIP/missing/cancelled/stale jobs
prevent a green handoff. Report workflow URL, branch, prepared SHA, immutable
comparison base, required job results and material/manual gaps. Preserve all
PHP, Node, Python, browser, database, WinApp, runtime and source-policy coverage.

Local generator/audit commands documented elsewhere are recovery/diagnostic tools,
not mandatory agent steps. Source debt budgets remain decrease-only; never add
baselines or weaken assertions to obtain green CI.

## Pull requests

1. Start from the repository's current default branch and keep the change
   focused.
2. Explain the user-visible behavior and the reason for the change. Link a
   related issue when one exists.
3. Describe schema, filesystem, compatibility, security, or configuration
   effects when applicable.
4. State the audit profile and result, plus any relevant skips or manual checks
   that remain. Record the final candidate SHA and immutable comparison base,
   and identify the applicable authoring contracts reviewed.
5. Include before/after screenshots for visible UI changes when useful, and
   mention any setup needed to reproduce them.

Opening a pull request does not authorize a release, publication, or deployment.
