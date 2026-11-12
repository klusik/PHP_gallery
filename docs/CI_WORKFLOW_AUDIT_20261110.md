# GitHub Actions workflow inventory and retirement review

**Author:** Rudolf Klusal  
**Date:** 2026-11-10 (UTC)  
**Repository:** `klusik/PHP_gallery`  
**Issue:** #362  
**Scope:** Read the currently installed `develop` workflow sources and their references before applying removal on the **existing #362 feature branch only**. No direct mutation of `main` or `develop`.

## Decision rules

Delete a workflow only when its original dedicated branch is absent, it has no known active workflow caller, and its expected regression/CI purpose is covered by maintained owners. Historical compatibility and exact-path checks take precedence over superficial inactivity. GitHub Actions historical run records remain available independently of current workflow-file presence; no historical run or protected branch was deleted.

## Verified inventory

| Workflow on reviewed develop | Trigger and ownership | Decision | Evidence / reason |
| --- | --- | --- | --- |
| `begin-release.yml` | Explicit release-start dispatch | **KEEP** | Active release branch creation lifecycle |
| `candidate-preparation.yml` | Feature/fix pushes, explicit maintainer dispatch | **KEEP** | Canonical BASIC gate and protected candidate preparation |
| `develop-integration.yml` | Explicit maintainer dispatch | **KEEP** | Protected release-independent integration and exact qualification |
| `gallery-workflows.yml` | Reusable `workflow_call`, advisory dispatch | **KEEP** | Main CI owners, PHP/Windows matrix and release qualification |
| `issue-345-python-qualification.yml` | Pushes restricted to `feature/345-browser-approved-linking` and manual dispatch | **REMOVE on #362** | Dedicated issue branch no longer exists (GitHub branch search returned no match). GitHub code search found only the workflow itself by exact path. Its PHP/MariaDB/Windows patterns exist in the maintained central owners. The issue itself is still open; this review retires a special diagnostic dispatcher, **not** its feature support or historical results |
| `manual-toolchain-cache.yml` | Main toolchain changes, manual warm-up | **KEEP** | Release TinyTeX build and `docs/LATEX_BUILD.md` depend on this exact cache policy |
| `project-website.yml` | Main/feature pushes, release, scheduled, manual | **KEEP** | GitHub Pages project site and previews |
| `readability-report.yml` | Manual on-demand | **KEEP** | Explicitly advisory tool, referenced by `CODEMAP.md` and `tests/readability_report_test.py`; lack of scheduled runs is intentional |
| `release-bootstrap.yml` | Historical reusable `workflow_call` | **KEEP** | Legacy workflow/source bytes are referenced by release policy tests, the integration recovery allowlist and contract checks; deletion requires a separately verified migration of historical qualification support |
| `release-promotion.yml` | PR and maintainer dispatch | **KEEP** | Human-reviewed release publication and completion |
| `release-qualification.yml` | Release branch push / maintainer dispatch | **KEEP** | Candidate assembly, preparation and qualification |
| `release-retirement.yml` | Explicit plan/retire dispatch | **KEEP** | Owner-controlled protected release-ref cleanup, not a temporary issue workflow |

**Issue #362 addition:** `.github/workflows/issue-362-a1-baseline.yml` is new and **issue-scoped**, with no production or protected-branch permissions. It is not a replacement for BASIC/FULL and should be removed or converted into a reusable performance owner after A1 is accepted. Its GitHub history and raw results must be retained before retirement.

## Dependencies and limitations

- The review inspected actual workflow YAML, direct same-repository `uses:` paths, GitHub code-search references and the latest observed Actions runs. Exact code search has limited visibility into unindexed historical refs; a missing result is not proof of zero historical dependencies.
- The old `release-bootstrap.yml` is deliberately retained because tests and integration-recovery policy still reference it. Calling a workflow "legacy" is not evidence it can be removed safely.
- `readability-report.yml` remains user-invoked, not automatic. Its absence from recent push runs is expected.
- `manual-toolchain-cache.yml` warms an existing release cache and should not be conflated with an unused test workflow.
- The removed issue-345 workflow is deleted only from the feature branch. It remains available on protected branches until an authorized integration is completed.
- No CI authority, branch protection, rulesets, environments, required-check names, release tags, GitHub Pages deployment or workflows on protected branches were changed.

## A1 workflow ownership

The new A1 workflow uses two *independent* runner jobs:

1. Ubuntu 24.04, PHP 8.5, disposable MariaDB 10.11, the existing owned test installation and real loopback HTTP bearer/endpoint reads. See `tools/perf/issue_362_a1_ci.php`, `issue_362_a1_seed.php` and `issue_362_a1_http.php`.
2. Windows 2025, Python 3.14, registered central `winapp-native` regression and bounded synthetic Tk Treeview timings. See `tools/perf/issue_362_a1_tk.py`.

The GitHub-hosted runner is **not WEDOS**. A single-worker PHP built-in server does not represent FastCGI worker occupancy, and isolated Windows Tk measurements are not end-to-end HTTP-to-GUI timings. Each report must preserve these boundaries. A1 acceptance remains open until the validated scenario matrix is sufficient.
