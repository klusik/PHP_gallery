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

## Preparing an ordinary source candidate

Complete the permanent documentation impact review, including all four TeX
manual sources when affected, before the final preparation run. If no TeX
content applies, document the reason in the handoff. Leave PDFs, manual
version/date metadata and `PATCH_NOTES.md` to release preparation.

Do not publish an intermediate source commit with stale generated state.
After final source or documentation edits, stage any newly created/deleted
production files and run:

```sh
git add <new-or-removed-production-files>
php scripts/prepare_candidate.php
git diff -- app/runtime/modules.php app/production-files.json app/core-manifest.json
git add app/runtime/modules.php app/production-files.json app/core-manifest.json
git commit -m "chore(candidate): refresh generated artifacts" # only if changed
php scripts/prepare_candidate.php --check
```

This uses the canonical generators in dependency order: runtime plan, Git-index
production inventory, then core-manifest hashes from complete checkout bytes.
Repeat it after any later source/test fix. Repeating without changes creates
no diff or timestamp-only manifest update. The `--check` command is read-only.

For a feature/fix branch, the stable hosted
[Candidate preparation](.github/workflows/candidate-preparation.yml)
automation can perform the generated-artifact commit for the exact pushed SHA
and pass that prepared candidate to the reusable full CI workflow. It checks
the remote branch head before a non-forced write and refuses superseded work.
The ordinary CI preflight is read-only and blocks expensive jobs when the
published candidate remains stale. GitHub Actions bot commits do not themselves
trigger recursive workflow runs. Do not add PR-specific codegen workflows.

Once prepared, run `php scripts/audit.php --profile=full` (only once at final
handoff, rather than redundant direct test-tree walks) and record its result
against the exact commit. For release preparation use `RELEASE.md` and the
release audit instead. Nothing in candidate preparation merges, squashes,
tags, or publishes an artifact.

## Verification

The central audit is the project's authoritative automated verification
interface:

```text
php scripts/audit.php --profile=release-preflight
php scripts/audit.php --profile=quick
php scripts/audit.php --profile=full
```

Choose `release-preflight` for source-only authoring feedback or `quick` for
behavior/regression feedback during implementation; do not run both mechanically.
The historical preflight name does not imply release actions. It includes the
whole-tree category budgets that quick omits. Freeze `PHP_GALLERY_SOURCE_BASE`
to the branch merge-base with `origin/main`, as shown in
[the authoring reference](docs/AGENT_AUTHORING.md#source-feedback-and-comparison-base),
so already committed omissions remain visible. Missing history is BLOCKED.
On source failure inspect the linked `source-failures.md`, repair all applicable
findings in the batch, then rerun after the fixes. Reports identify source HEAD,
comparison SHA and dirty state; checkout feedback is not clean-commit qualification.

Use `full` for a final deterministic check
when the required runtimes are available. The `release` profile is reserved for
maintainer release preparation. Read [TESTING.md](TESTING.md) for audit scope,
environment requirements, and manual acceptance boundaries. If a required
runtime or optional integration is unavailable, say so and include the audit's
reported status; do not describe skipped coverage as passed.

Full/release audits enforce historical source debt by stable categories through
the existing inventory. Reliable counts may decrease; they must not exceed their
reviewed caps. New categories start with zero allowance. Only the three documented
noisy policy heuristics remain advisory. See [source debt maintenance](TESTING.md#source-debt-category-budgets)
for the explicit decrease-only refresh workflow. Do not reset or inflate budgets
to pass an audit; preserve meaningful existing documentation when moving code.

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
