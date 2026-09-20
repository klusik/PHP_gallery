<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/CODE_DOCUMENTATION.md
Module Type: Maintainer Guide
Purpose: Define source documentation, attribution and policy ownership conventions.
Responsibilities:
  - Describe enforceable syntax contracts and the semantic review they cannot replace.
Author: Rudolf Klusal
-->

# Source documentation and policy ownership

This is the detailed companion to the Comment and Docstring Rules in
[ARCHITECTURE.md](../ARCHITECTURE.md). It describes source contracts, not release history.

## File attribution

First-party source uses native comment syntax. Keep the shebang first and
preserve licenses, useful comments and third-party attribution. The leading
header identifies Project: PHP Gallery, Repository:
https://github.com/klusik/PHP_gallery, the exact repository-relative File:,
Module Type:, a factual Purpose:, concrete Responsibilities:, and
Author: Rudolf Klusal. Preserve existing Contact:, License: and maintenance notes;
do not manufacture a new license.

PHP may put its strict-types declaration before or after that header. Batch files
may keep their leading @echo off. A name inside a string, fixture body or later
comment is not a source header. YAML and Apache .htaccess use hash comments;
HTML and first-party SVG use HTML/XML comments (after a required prolog);
TeX uses percent comments. New Markdown guides use an HTML metadata comment
before prose, as this guide does. Existing licenses and upstream credits remain
unchanged. JSON, binaries and generated artifacts receive attribution through
their owning source/generator and repository metadata; adding comments to JSON
is invalid.

The shipped telemetry assets have a byte-identity compatibility contract.
Their shared File field is exactly
`public/assets/usage.js (canonical); public/assets/telemetry.js (compatibility copy)`.
Only those two paths accept that identity. Keep their comments and line endings
identical; this is not a general copied-header exemption.

[inventory.php](../scripts/source_contracts/inventory.php) owns discovery and
native header parsing. Its report names pruned directories and counts other
formats. Do not scan live configuration, custom CSS, uploads, caches or agent
state for documentation metrics. Its exact-path provenance registry identifies
the four upstream flag SVGs and Bootstrap-derived brands.svg using existing
README/license evidence; newly added first-party SVGs are still checked. Do not
relabel vendored sources as first-party or broadly exclude SVG/HTML/YAML/TeX.

## Declarations and data shapes

Document a declaration immediately above its modifiers/attributes. State what it
does and what a caller can observe; generic “Handles ... logic for the gallery
application” sentences do not explain behavior.

Every new or materially changed function, constructor, private method and named
or lifecycle-significant callback needs typed/named parameter documentation and
an explicit return contract, including void or never. Names must match the
signature. PHP uses @param TYPE $name Description and @return TYPE Description;
JavaScript uses @param {TYPE} name Description and @return {TYPE} Description
(@returns is accepted). Explain reference mutation, failure modes, side effects,
authorization preconditions and cleanup ownership where they matter. Python uses
native docstrings; shell and PowerShell use native comment-help conventions.
Discovery does not imply those language semantics are automated.

A dense, fixed-length JavaScript array destructure may document its aggregate
input by parameter position, for example @param {[string, string]} selectors for
([controlSelector, displaySelector]). The scanner checks tuple arity and explicit
element types; it does not require replacing valid runtime destructuring. Sparse,
nested, rest, defaulted and object patterns remain review findings. Loose arrays,
wrong tuple lengths and untyped/wildcard members do not satisfy this narrow rule.

Inherited PHP driver signatures may legitimately accept unrestricted, unused
values, including resources. Keep the honest @param mixed ...$args type rather
than inventing a finite union or a record shape. Add a per-parameter rationale:
@source-contract-opaque-param $args Unused inherited PDO driver arguments; never
inspected, forwarded, stored or logged. Keep that annotation and rationale on one
line in the actual docblock. The scanner accepts this as a complete opaque input
contract only when the body has no direct use of that parameter or recognized
argument/scope introspection. Constructors and abstract signatures provide no such
proof. Used/forwarded mixed values, return shapes, missing tags and meaningful
summaries remain subject to their ordinary rules. This is semantic documentation,
not a per-file exemption or allowed-debt baseline.

Class documentation explains responsibility, lifecycle, collaborators and
invariants. Properties need their type and meaning; promoted fields must be
explained by the constructor contract and class lifecycle. A substantive standard
@var TYPE Description supplies the property's meaning without a duplicate prose
sentence; a bare type does not. JSDoc may precede a static member assignment such
as window.fetch = callback, but cannot be borrowed across unrelated code or an
array's preceding key. Reusable records need
one canonical shape near their domain owner. Document field types, optionality,
units, allowed values, null meaning and sensitivity. A generic array, mixed,
object or map of arbitrary values cannot alone explain an envelope or job record.
Use PHPDoc array shapes/aliases and JSDoc @typedef/@property, referencing the owner
instead of copying divergent field lists. Never put live secrets into examples.

The source reports use these stable data shapes:

| Record | Fields and meaning |
| --- | --- |
| Inventory | files: relative path to lowercase extension; other_formats: extension to path-only entry count; excluded: relative pruned path to reason; provenance: exact upstream asset path to origin/license evidence. Excluded contents are never read. |
| Finding | path: relative identity; line: one-based line; rule: stable rule, optionally suffixed with a parameter name; name: source symbol or empty for headers; kind: optional declaration/header category; missing: optional constant documentation fields. No literal values or snippets. |
| Documentation summary | source_files: selected files; languages: file counts; declarations: language/kind counts; finding_count: issue occurrences; files_with_findings: affected files; rules: issue counts by family. |
| Policy definition | path, line, name; missing_documentation: absent semantic fields; lexical_consumers: other paths mentioning the identifier, advisory until imports and scope are reviewed. |
| Policy summary | source_files, languages, definitions count, finding_count, rules counts. |
| Report | inventory, summary, findings, coverage; policy reports also contain definitions. Strict mode never silently removes findings. |

Counts overlap: one function can lack a parameter description and a return
contract. Zero findings proves only implemented scanner rules. Primitive PHP type
contradictions are detected; aliases, subtypes, complex types, truthfulness and
actual field semantics require review.

## Constants and configuration

[configuration_defaults.php](../app/configuration_defaults.php) remains the
deployment-tunable default map, read through cms_runtime_limit().
[policy_constants.php](../app/policy_constants.php), in namespace Gallery\Core,
is the central immutable invariant owner. Security/access invariants, protocol
and schema vocabulary, and format hard limits must not become editable merely to
centralize them. Existing local definitions remain inventory debt until their
semantic scope and consumers are reviewed. Parent integration owns reconciliation
of ARCHITECTURE.md, CODEMAP.md and TESTING.md with the canonical immutable owner.

Equal numbers do not imply equal meaning. Reuse a semantic definition or name the
independent policy explicitly. Do not invent ONE, TWO or universal timeout
constants. Loop initializers, counters and fixture sample values do not need
policy names. Protocol/enum values still need compatibility meaning and an owner.

A constant documents purpose and @var type, plus Units:, Scope:, Consumers: and
Rationale:. State bounds and security/compatibility implications where applicable.
Units may explicitly be dimensionless or enum string; rationale may explain
protocol compatibility rather than inventing a performance justification.

Browser modules receive only the public values they require through an existing
prepared view-model/asset path. Never serialize all server defaults. Early guards
must stay dependency-free; immutable definitions must not load configuration,
sessions, services or a database. Standalone tooling may own documented CLI/report
constants; that does not justify duplicating application policy.

## Enforcement and integration

[source_header_author_test.php](../tests/source_header_author_test.php) enforces
all seven standard native header fields: project, repository, exact file identity,
module type, purpose, responsibilities and exact author. Missing content cannot
borrow the following field. There is no first-party debt baseline or exemption.
The new guides' Markdown metadata is checked by the disposable-fixture suite.
[function_documentation_test.php](../tests/function_documentation_test.php) remains
the historical named PHP/JS presence gate, now sharing discovery and PHP parsing.
Its JS check no longer borrows a docblock across intervening code.

[check_source_documentation.php](../scripts/check_source_documentation.php) reports
broader headers, classes, callbacks, properties, parameters, returns and loose
shapes. [check_policy_constants.php](../scripts/check_policy_constants.php) reports
definitions, lexical consumers, assignment/timer candidates, CSS durations and
script assignments. Both accept --json, --strict, --root=PATH and repeated
--path=RELATIVE. Default exit zero means inventory succeeded, not compliance.
Strict mode exits one for any finding; bad options, unreadable source, or
excluded/missing selections exit two.

The central PHP regression runner auto-discovers
[source_contract_inventory_test.php](../tests/source_contract_inventory_test.php),
and [source_contract_changes_test.php](../tests/source_contract_changes_test.php)
use disposable fixtures only. Parent has registered both whole-tree inventory
CLIs in every audit profile, retaining complete JSON and advisory counts. The
source-documentation-changed task is also registered in every profile, retaining
source-documentation-changed.json and strict PASS/FAIL/BLOCKED status. Do not
interpret an inventory report exit as full
compliance. Strict checks can target remediated exact paths while whole-tree debt
remains visible. No baseline or allowlist is introduced; whole-tree strict mode
currently fails. The existing MVC suite also enforces the narrow core-helper and
security SQL/PDO rule after the three persistence-owner migrations; HTTP/session
compatibility behavior is deliberately not reclassified as a strict violation.
The converted discovery, Google OAuth, duplicate-detector and viewer anti-automation
services take caller-owned state maps; the report-job service part takes a nullable
checkpoint.
Their exact service files and split parts additionally forbid direct session
globals. This narrow guard does not reclassify unreviewed report siblings.
The security compatibility facade and its future split parts additionally reject
direct filesystem mutation after setup-lock ownership moved to auth_accounts.
The converted mobile_webdav controller and future parts reject direct filesystem
mutation; request-stream reads and service-owned cleanup remain allowed.

The separate enforcement command is
`php scripts/check_source_documentation.php --changed --json`. Its report carries
status (PASS/FAIL/BLOCKED), summary (HEAD base, discovered/byte-changed files,
added/changed/unchanged declarations, moved subset, doc regressions and findings),
value-free path/line/identity/rule findings, safe blocked reasons and coverage limits.
Exit 0 means the bounded gate passed; 1 means findings; 2 means coverage blocked.

Namespace/owner identities and executable-token fingerprints ignore header-only
edits, ordinary formatting and unchanged legacy bodies. Removing documentation
still fails. A changed body/signature requires the current implemented contract.
Exact cross-file moves are matched after same-path originals are consumed, so a
copy is still new code. Scoped --path runs compare selected files only; whole-tree
mode also consumes removed source files to identify moves. No Git index,
checkout, baseline, commit or live data is written; missing Git/HEAD cannot pass.

PHP, JS modules and inline HTML scripts are scanned, including nested template
expression callbacks. Ordinary markup and CSS/YAML/SVG/TeX use the separate
native-header gate. Changed Python/shell/PowerShell contracts, unknown HTML script
languages, private/computed JS syntax, ambiguous regex and semantic aliases remain
disclosed limitations, not baseline exemptions.

## Changed runtime policy enforcement

The separate command is
`php scripts/check_policy_constants.php --changed --json`. Parent registered it
as source-policy-changed in every central profile through the shared changed-source
audit adapter. This does not replace the existing whole-tree policy inventory.

The bounded scope is PHP/JS/MJS/CJS under app/, public/ and root index.php.
Recognized sites are uppercase PHP const/static-name define and JavaScript
const/let/var definitions, semantically named direct numeric assignments, and
direct sleep/usleep/set_time_limit or global setTimeout/setInterval numeric
arguments. Literal arithmetic is recognized; unrelated digits, quoted code,
ordinary loop initializers and independent object methods are not blanket hits.
Zero/one assignment initialization stays outside the assignment rule, but a
literal timer delay still needs a policy explanation.

A changed site needs a substantive purpose, type and nonempty Units:, Scope:,
Consumers: and Rationale: fields. @var, @type and Type: identify type;
Scope/consumers: explicitly supplies both fields and Compatibility: may state the
rationale. Empty labels cannot borrow a following field. A separate native file
header cannot substitute for a policy contract or invalidate the first real
docblock. Missing labeled fields do not mean all existing prose is meaningless:
retain its facts when making the contract explicit. No generated boilerplate,
per-file exemption or baseline may make a finding disappear.

Read-only HEAD comparison uses containing-scope identities and executable site
fingerprints. Header/ordinary formatting and unrelated body changes do not
reopen unchanged legacy sites. Definition visibility changes and changed policy
expressions do. Removing explanation fields is a regression. Same-path originals
are consumed before cross-file moves, so copying an unchanged site is still an
addition. No Git index, checkout or application data is written.

JSON carries status, summary, findings, blocked, coverage_review and coverage.
Summary adds runtime_files, sites_with_findings and coverage_review_count to the
HEAD/change counters. A finding identifies relative path, line, symbol, site kind
and a rule suffixed by its missing field. Values, hashes, snippets and doc text
are never returned. Exit status is 0 PASS, 1 FAIL, 2 BLOCKED; unavailable Git/HEAD,
required unreadable source or unclassifiable recognized policy syntax cannot pass.

PASS applies only to these implemented change rules. Configuration-map/object
entries, dynamic names, embedded scripts, unsupported native formats and
out-of-scope tooling/tests have explicit coverage-review records. These are not
strict findings or exemptions. Numeric syntax outside recognized arithmetic,
shadowed/imported timer ownership, resolved duplicate ownership and semantic
truthfulness still require review. Whole-tree legacy policy remains in the
separate advisory inventory; configurable defaults are not relabeled immutable.

[policy_constants_changes_test.php](../tests/policy_constants_changes_test.php)
uses disposable sources and synthetic read-only Git responses. It covers
explanations, field regressions, moves versus copies, redaction, first-definition
headers, valid PHP interpolation, malformed recognized calls and unknown history.

The central audit remains authoritative. Direct CLI inventory is source
inspection, not a replacement for regression/syntax audits. The measured findings,
MVC review and limitations are in [SOURCE_CONTRACT_INVENTORY.md](SOURCE_CONTRACT_INVENTORY.md).
