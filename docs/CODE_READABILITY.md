# Advisory code readability

Prefer authored code, TeX and Markdown near 80 display columns when a natural split
helps GitHub side-by-side diffs and narrow editor panes. This is guidance, not a maximum
or a merge condition. Keep meaningful types, comments and documentation. Change
only relevant lines; the report never rewrites source.

## Run and read the report

In Actions, open **Code readability report**, choose **Run workflow**, select the
branch in GitHub's **Use workflow from** selector, and run. There are no other
inputs. The selected branch must contain this workflow, checker and rules. The
workflow definition and checker both come from that branch's event SHA, which is
checked before analysis. Later branch pushes do not change an in-flight snapshot.
Tags are rejected. No default-branch evaluator checks another branch's files.

The optional job retains its ten-minute limit for setup, analysis and artifact
publication. The central reporter has a 240-second deadline; each Git or PHP
token-adapter child has 180 seconds, leaving the outer runner time to retain
failure diagnostics. Contract execution has a 90-second optional-profile budget,
with a 75-second PHP-to-Python bridge and 45-second complete-report fixture
children. These are named, documented tooling policies; recommendations never
change their values or turn report findings into qualification failures.

The new workflow becomes visible in the Actions sidebar after normal reviewed
installation on the default branch. Before installation, an API dispatch against
the feature ref may depend on GitHub's workflow discovery; do not claim the sidebar
entry is installed merely because the feature branch contains it.

The central `scripts/audit.php --profile=readability` owns the contract regression
and advisory scan. It scans the entire selected commit, without a delta/base or
generated-artifact preparation. The existing BASIC/FULL/release profile lists and
required check contexts retain their coverage and authority. Only this optional
workflow executes advice. Its findings always exit zero; broken tooling, failed
regressions or unavailable reports remain real operational failures. Do not add
this workflow as a required branch check or mask failures with continue-on-error.

Download `readability-report-<SHA>` from that run. Inside the audit run directory,
open `readability/report.html` for an escaped, searchable report grouped by file.
`report.md` is the complete plain-text alternative; `report.json` retains every
finding and coverage record. `diagnostics.jsonl` records every selected file,
including files with zero suggestions. `guide.md` contains these examples and
interpretation rules. Central child logs and `latest.md`/`latest.json` retain
regression and process errors. Artifacts are retained for 30 days.

Each suggestion includes an immutable source link, path/line, language, rule,
measured width, tier/threshold, original line and a wrapping or spacing suggestion.
The concise Actions summary records branch, immutable SHA, file/finding counts,
tiers, parser gaps and stale exceptions. A green advisory run means publication
succeeded; it does not qualify a candidate or approve a cleanup.

## Scope, current tools and owners

| Language | Maintained classes | Existing owners/tools | Advisory analysis |
| --- | --- | --- | --- |
| PHP | Controllers, services, models, views, CLI, migrations, tests, root entrypoints | PHP syntax and `source_contracts/php.php` | Native `token_get_all()` and existing declaration spans |
| JS | Public modules/workers, compatibility entrypoints and Node fixtures | Node syntax and `source_contracts/javascript.php` | Existing conservative comment/string/template-aware lexer |
| MJS | CI scripts, tools, website and Node/browser fixtures; CJS uses the same rule family | Node syntax and the same JS declaration owner | Same lexer; reported separately from JS |
| Python | WinApp modules/PYW, tests and source tooling | Native AST import/declaration checks and WinApp regression | Standard-library AST and `tokenize`; no imports of inspected modules |
| CSS | Authored public styles and website styles | Native attribution inventory; no installed formatter | Conservative comment/string/URL masking and width advice; no spacing inference |
| TeX | All four manuals and other tracked TeX sources | Release-owned compiler/typography checks | Prose whitespace advice; commands/arguments, comments, math, code and table environments stay opaque |
| Markdown | Maintained guides, README files, AGENTS instructions and other tracked Markdown | Permanent documentation owners; no installed prose formatter | Prose whitespace advice; code, tables, headings, metadata, HTML and links stay opaque |

Only tracked Git blobs in these classes enter the report. Discovery uses
`git ls-tree` and one `git cat-file --batch`; modified/untracked working files and
installation state cannot enter source context. The checker and rules must match
the commit. Canonical directory exclusions come from
`scripts/source_contracts/inventory.php`: private/cache/data/upload trees, agent
state, vendor/node_modules, virtual environments and similar generated trees are
excluded before blob reads. Symlinks/submodules are not followed. Advisory-specific
globs additionally exclude minified JS/CSS, generated `app/runtime/modules.php`,
private config and installation-owned Theme CSS. Excluded paths/reasons are kept.
Fixtures remain included because they are maintained code; literal fixture payloads
receive the contextual exceptions below.

There is no installed repository-wide formatter. Alternatives considered:

| Candidate | Useful capability | Decision for this pilot |
| --- | --- | --- |
| [Prettier](https://prettier.io/docs/options#print-width) | Parser/printer layout with a preferred print width | Useful future preview adapter; full-file style differences would add noise to focused suggestions. No formatter or Node dependency is introduced. |
| [Ruff E501](https://docs.astral.sh/ruff/rules/line-too-long/) | Python width advice with contextual exclusions | Good Python-only alternative; this pilot needs a shared report schema for code and document source. |
| [PHP CS Fixer](https://cs.symfony.com/doc/rules/index.html) | PHP argument and blank-line formatting rules | Good PHP-only alternative; installation and formatter diff review would add a separate toolchain. |
| [Stylelint](https://stylelint.io/user-guide/rules/) | CSS semantic/style rule inventory | Consider if CSS false positives justify a full parser; do not treat CSS value whitespace as safely interchangeable. |
| Existing tokenizers plus Python stdlib | Shared source ownership, literal boundaries, AST sibling spans and Unicode widths | Selected dependency-free reporting implementation; conservative lexical limits remain explicit. |

This is a design comparison, not a benchmark claiming these alternatives were run.
Measured scanner runtime is recorded on each hosted report. Real-workload savings,
false-positive rate and maintainer acceptance of split diffs remain unknown until
reviewed samples exist. Tuning never changes existing hard gates or their budgets.

## Rules, measurement and exceptions

`scripts/readability_rules.json` is the sole advisory configuration owner.
Defaults currently coincide across languages: at most 80 preferred, 81-100
informational, above 100 prominent advisory, and above 160 priority for triage.
All tiers remain suggestions. Per-language values can be calibrated there without
changing required checks. The informational tier requires a recognizable semantic
break opportunity: argument/collection commas, boolean terms, nested calls,
chains or CSS selector/declaration structure. A line gets one width finding with
one primary suggestion, not overlapping width/density/list warnings.

Width counts rendered terminal-style columns, not UTF-8 bytes: tabs advance to
four-column stops; Unicode combining marks and format characters count zero;
East Asian wide/fullwidth code points count two; other code points count one.
Tabs can be configured from one through 16 display columns: a positive interval
keeps measurement defined, and the upper bound prevents implausibly large stops
from dominating recommendations. Browser font shaping and emoji grapheme clusters
may differ; the report does not claim pixel-perfect GitHub measurements.

PHP/JS/MJS recommend one blank line between adjacent named declaration siblings.
Python recommends two at module scope and one between class members, using AST
spans and attached decorators. PHPDoc/JSDoc stays attached to the declaration.
Unrelated executable statements reset adjacency. CSS spacing is left to human
review. These are style conventions, never syntax requirements.

TeX and Markdown use the same width tiers for ordinary prose with a natural
whitespace boundary. Preserve source paragraph breaks: a blank TeX/Markdown line
changes meaning and must not be added merely to reduce width. TeX `~` bindings
stay intact; wrapping must remain outside commands/arguments, math and literals.
Markdown wraps retain list continuation indentation and explicit hard breaks.
Tables, headings, fenced/indented code, link destinations, front matter and raw
HTML blocks are excluded. TeX comments, command arguments, inline verbatim, math
and common literal/table environments are excluded. These conservative lexical
scanners do not expand macros or implement complete TeX/CommonMark grammars;
unknown custom environments or complex inline constructs still need human review.
Document paragraph spacing is deliberately not inferred.

Comment-only lines, docstrings, mixed PHP/HTML lines, long indivisible identifiers,
URLs, regexes, translations, SQL/heredocs and other long literal fragments are
excluded or conservatively suppressed. Do not break literals mechanically. The
whole line is suppressed when an indivisible fragment exceeds the preferred
width; this deliberately trades some recall for less noise. Noise counts remain
visible. JS regex contexts are conservative; nested/template constructs and CSS
custom values may still need manual review. Python parse failures are coverage
gaps, not zero-finding files. No claim of full JS/CSS grammar validation is made.

For a remaining justified case, add an entry to `exceptions`, for example:

```json
{
  "path": "tests/example_test.php",
  "rule": "line-width",
  "contains": "specific stable source fragment",
  "reason": "The external snapshot requires this exact representation."
}
```

An exception must match exactly one finding by path, rule and source fragment.
Blank reasons are rejected. Missing or ambiguous matches remain visible as stale
exceptions and suppress nothing. Avoid line-number baselines and blanket file
suppression. Existing suppression reasons, stale exceptions and complete
unsuppressed findings all remain downloadable.

## Focused before/after and split-diff examples

These are proposals copied from maintained source, not applied cleanups. The
baseline is source commit `bbe3d5e5068897193425a41bc1d883c51e283708`.
They show semantic breaks without deleting types, documentation or behavior.

PHP: `scripts/audit_registry.php`, source-contract profile entries. Before:

```php
            'php-lint', 'js-lint', 'source-documentation-changed', 'source-policy-changed',
```

After (each future list-entry change has its own short diff line):

```php
            'php-lint',
            'js-lint',
            'source-documentation-changed',
            'source-policy-changed',
```

JS: `public/assets/gallery-modules/admin-cache-cleanup.js`, request identity:

```diff
-        const runId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
+        const runId = Array.from(
+            crypto.getRandomValues(new Uint8Array(16)),
+            byte => byte.toString(16).padStart(2, '0'),
+        ).join('');
```

Python: `scripts/source_contracts/python_scan.py`, a typed declaration. Keep its
existing docstring immediately inside the function:

```diff
-def type_issues(parameters: list[dict[str, str]], return_type: str, documentation: str) -> list[str]:
+def type_issues(
+    parameters: list[dict[str, str]],
+    return_type: str,
+    documentation: str,
+) -> list[str]:
```

In GitHub's split view, compare these proposed right-hand lines with the original
left-hand expression: the right-hand argument/entry boundaries fit narrow review
panes and isolate the changed value. The original left-hand line remains wide
during the cleanup itself; later edits benefit from the new layout. Actual
horizontal scrolling depends on viewport/font. No browser split-diff usability
acceptance or false-positive percentage is claimed by synthetic tests.

`tests/readability_report_test.py`, bridged by the central PHP regression, tests
80/81/95/100/101/130/161 columns, tabs/Unicode, all language families, attached
docs/decorators, actual immutable Git blobs, exclusions, narrow exceptions,
complete escaped reports, findings with exit zero and rejection of a mismatched
event SHA. The optional profile runs it before reporting; normal PHP regression
also discovers the PHP bridge. The hosted report itself is the real-tree sample
for maintainer review and gradual tuning.

Document regressions cover TeX prose, escaped control symbols, nested arguments,
math, literal/table environments, Markdown prose/list indentation, code fences,
tables, links, headings, metadata, HTML and hard breaks. The real report smoke
fixture includes both `.tex` and `.md` blobs, proving they enter the same report.
