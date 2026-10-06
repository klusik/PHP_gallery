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
- Add schema changes as new timestamp-prefixed migration files. Preserve
  upgrade compatibility and cover behavior through the central audit where
  practical.
- When changing user-facing manual content, update all four maintained manual
  editions together as described in `AGENTS.md` and `docs/LATEX_BUILD.md`.
- Keep changes focused. Do not include local configuration, credentials,
  uploads, caches, generated deployment archives, or unrelated formatting
  changes.
- For a new or materially changed compatibility branch, explain its reason,
  protected behavior/version/environment, owner, and safe retirement condition
  or permanent-support rationale in the change description. Record high-impact
  paths in [the compatibility registry](docs/COMPATIBILITY_LIFECYCLE.md).
  Mark unmeasured usage and uncertain dates as unknown; removal needs evidence
  that the recorded condition is met.

## Verification

The central audit is the project's authoritative automated verification
interface:

```text
php scripts/audit.php --profile=quick
php scripts/audit.php --profile=full
```

Use `quick` during implementation and `full` for a final deterministic check
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
   that remain.
5. Include before/after screenshots for visible UI changes when useful, and
   mention any setup needed to reproduce them.

Opening a pull request does not authorize a release, publication, or deployment.
