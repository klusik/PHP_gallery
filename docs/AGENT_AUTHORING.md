# Writing compliant code before verification

[Root AGENTS.md](../AGENTS.md#authoring-contract-read-before-editing) is the
canonical instruction for coding agents. This reference supplies copyable syntax
and the source-feedback workflow. It applies to agent-authored application code,
tools and tests. It does not replace the existing domain, access, MVC or runtime
ownership rules.

Write a complete declaration and its documentation together. When changing a
signature or behavior, update the same contract in the same edit. Review all
changed declarations and policy sites before verification. Repair repeated
omissions throughout the batch, then rerun the appropriate central profile once.
Do not increase debt caps, add MVC baseline entries, disable checks, or replace
truthful contracts with filler.

## Declaration contracts

Readability is advisory: prefer around 80 display columns, reconsider natural
breaks at 81-100, and actively seek a readable split above 100. Format lengthy
arguments, arrays, objects and multi-item lists with one entry per indented
line.
Group boolean terms by meaning. Separate logical steps and neighboring named
declarations with language-appropriate blank lines, keeping docs/decorators
attached. Preserve semantics, types, comments and necessary longer literals.
Use focused changes and review the source in a GitHub split diff; editor
soft-wrap
does not improve the stored diff. The [readability guide](CODE_READABILITY.md)
contains actual-source before/after examples, exceptions and the optional
report.
These recommendations never weaken or add to mandatory source gates.
They also cover TeX and Markdown source prose: wrap at existing spaces while
preserving paragraph boundaries, TeX `~` bindings, commands, literal blocks and
Markdown block/list structure. See the scoped documentation rules for
exceptions.

PHPDoc/JSDoc goes immediately above the named declaration. Python's docstring is
the first statement inside the declaration. Start with a factual purpose
sentence
before annotation tags. Every explicit parameter needs its actual name, type and
description, including optional/defaulted, nullable, variadic and added
parameters.
Document the actual return variants and their meaning, including `void`/`None`.
Class-like declarations need a purpose summary. Native PHP/Python signature
types
are independent of the docstring; PHP constructors/destructors cannot declare a
native return type, but still document their void result.

Anonymous functions, closures, arrows and variable/member-bound callbacks need
no declaration docstring. Variables and properties have the same exemption.
Named functions or classes nested inside callbacks remain checked. Do not create
a named helper solely to satisfy documentation or put a docblock in call
arguments.

### Authoring steps learned from CI findings

Always add explicit native parameter types to PHP callback signatures,
including arrow callbacks used in `array_map()`. Apply the anonymous-callback
exemption to the declaration docstring requirement only.

Always attach a factual purpose docblock immediately to each class-like
declaration, including anonymous classes inside JavaScript object literals or
test fixtures. Reuse an existing suitable fixture type when it already provides
the required behavior; document every newly introduced class, including an
empty anonymous class.

Always complete the entire declaration contract when changing a function body.
Describe structured `array` parameters with actual collection elements/row
fields, document every added/defaulted parameter, and state every actual return
variant including `void`. Review these together with the behavior edit.
When extending a health registry or label map, complete the enclosing function's
return description and structured input documentation in the same edit.

Always search production imports, re-export modules and every regression
assertion for the old cache-busting module URL. Update the complete import
chain.
Covered Admin consumers use `scripts/admin_import_contracts.php` to validate
actual targets, full shared URL identity and stale payload/importer invalidation
against the immutable base; do not add a historical revision string assertion
where this relational owner applies. Keep unrelated exact security/route checks
strict. Always
represent presentation choices as explicit value/label entries. Reserve literal
`'page' => 'target'` entries for registered routes because the route-reference
check validates those entries as route targets.

### Implementation checks learned from hosted CI

For SQL embedded in PHP, review the outer PHP string and inner SQL literals as
two syntax layers. For example, a double-quoted PHP query can contain the fixed
SQL condition `kind <> 'history'`, while changing values remain bound
parameters.
Review this at the declaration edit so hosted syntax feedback reaches the
intended query rather than stopping at a PHP parse error.
Use `bindValue(..., PDO::PARAM_INT)` for numeric/boolean placeholders compared
with SQL literals. Passing an integer through `execute(array)` can bind it as
a string; SQLite distinguishes that placeholder from the numeric literal where
MySQL/MariaDB may coerce it. Keep the real driver fixture's strict expectation.

For an independent PHP process fixture, list and require the minimal Core and
service dependencies inside the child bootstrap. A passive cache-status reader
needs the safe-relative-path policy as well as its storage entry point. Read the
real child exit code, stderr and serialized persisted evidence; the parent
process's loaded functions do not establish the child's include contract. Keep
the parent’s exact success marker synchronized with changed child coverage text.

For resumable work, identify the owner of every counter before initializing it.
A newly created manual identity starts its own observation round, an existing
regional identity advances its durable round, and installation-wide confirmed
removal receipts survive both. Extend the existing scheduler fixture to compare
fresh-cycle and continuation behavior with the same strict expectations.

For filesystem crash injection, distinguish admitted observations from removed
files. The existing deletion primitive counts `.` and `..` as observations;
its neighboring scheduler fixture admits three observations to reach one unlink.
Reuse that setup and assert a real removal before discarding the uncommitted
cursor. This establishes the intended crash boundary while preserving the
production worker cap, time budget and suite limits.

For retained cache measurements, keep a current manual operation's counters
separate from regional last-known observations and lifetime deletion receipts.
Keep the current manual incomplete-check marker visible with its counters.
Pass that distinction through controller messages, diagnostic events and browser
progress; a repeatedly displayed retained total cannot prove a stuck cursor.
For historical ZIP expiry, use positive native-format proof and producer/payload
age. Use ctime to detect replacement at detachment, not to renew TTL after
chmod.
Retain the shared base lease across archive creation, publication and HTTP
output.

Pass a named interval constant to each new timer or sleep, including fixtures.
Document runtime constants
with explicit `Type:`, `Units:`, `Scope:`, `Consumers:` and `Rationale:` lines
so both changed-source and whole-tree checks recognize the contract.

Assert short-opportunity caps and durable progress across bounded continuations;
cover admission exhausting the deadline without requiring a first-attempt slice.

Hold a follow-up passive read while asserting a mutation response in a browser
fixture; then release it separately to verify refresh without racing snapshots.

For live filters, invalidate active requests at the input event rather than
waiting for the debounce. Capture each request URL once, suppress superseded
responses before and after JSON parsing, and derive visible history from the
committed response. Extend the existing asynchronous fixture with an older
response arriving during debounce and an older body completing after a newer
empty result.

For browser harness controls such as `/__viewport`, use the captured native
`fetch` directly. Keep those controls outside the mock that validates
application reads/writes, retaining its strict credential and method assertions.
This lets viewport tests reach the owned harness without impersonating a
dashboard request.

For reusable formatting in runtime-scanned views, call a named typed
presentation helper directly. This lets the compiler resolve the owner without
an unreviewed variable-call dependency. An isolated render fixture must
explicitly require that view module before calling it. Remove the retired
dynamic-call review entry when replacing its callback.

For runtime-plan ownership, inspect the full historical closure before adding a
shared dependency edge. Reuse an existing logical owner only when all of its
files already belong to that consumer's closure. Generate from the authored
roots/dependencies, inspect plan-wide duplicate entries and each affected
route's
transitive modules/file counts, and retain the established baselines and limits.
Count the dependency's transitive owners against the consumer's remaining module
allowance as well as checking its file closure: sharing existing PHP files can
still introduce extra logical modules. Choose a complete existing consumer that
fits both constraints.

For retained measurements, present the newest retry/error evidence alongside the
last successful result. A completed older observation can remain useful while a
newer checkpoint or discovery attempt is unverified. Record actual removal time
when removal is confirmed and preserve an unknown time for legacy receipts that
lack one.

### PHP: added optional parameter and structured result

The boolean parameter is documented even though it has a default. The documented
row fields and nullable result describe the actual returned value; native
`array`
alone cannot express the row shape.

<!-- checked-example: php-declaration -->
```php
<?php
declare(strict_types=1);

/**
 * Prepare one image row for the requested panel presentation.
 * @param array{id:int,title:string} $image Stored image identity and display title.
 * @param bool $panelMode Whether the caller selected the panel presentation.
 * @return array{id:int,title:string,panel:bool}|null Prepared row, or null for an invalid identity.
 */
function prepare_image_row(array $image, bool $panelMode = false): ?array
{
    if ($image['id'] < 1) {
        return null;
    }
    return ['id' => $image['id'], 'title' => $image['title'], 'panel' => $panelMode];
}
```

This is a transport-independent example; controllers choose its semantic inputs.
Keep SQL in models, reusable orchestration in services and rendering in views.

### JavaScript: summary, concrete fields and nullable return

A tag-only block is incomplete. JSDoc types use braces. Specify collection
elements and fields; do not hide structured state behind bare `Object`/`Array`.

<!-- checked-example: js-declaration -->
```js
/**
 * Find the selected file occurrence with the requested client identity.
 * @param {Array<{id: string, file: File, source: string}>} items Ordered selected occurrences.
 * @param {string} id Stable document-local occurrence identity.
 * @returns {File|null} Original source file, or null when the occurrence is absent.
 */
function selectedFile(items, id) {
    const item = items.find(entry => entry.id === id);
    return item ? item.file : null;
}
```

Reusable typedefs may reduce repetition only when their actual fields/elements
are documented. The conservative scanner does not resolve every alias, subtype
or imported type; review those relationships yourself. A syntactically accepted
alias is not proof that its definition exists or matches the value. `mixed`,
`any` and wildcards are not substitutes for a structured contract. The existing
opaque-unused-parameter exception is limited to explicitly explained, lexically
unused PHP inputs.

### Python: native annotations and typed Google-style docstring

Do not add `from __future__ import annotations`; the repository's Python import
policy checks this independently. This example needs no postponed annotations.

<!-- checked-example: python-declaration -->
```python
def selected_count(count: int, *, include_pending: bool = False) -> int:
    """Return the selected count with the requested pending adjustment.

    Args:
        count (int): Number of already selected records.
        include_pending (bool): Whether to include one pending record.
    Returns:
        int: Selected count including the requested adjustment.
    """
    return count + int(include_pending)
```

Use typed Google-style `Args:` and `Returns:` consistently in repository Python
sources. The scanner retains tag-style support for compatibility; keep legacy
input fixtures and parser behavior intact. Named nested functions and async
methods follow the same rule; ordinary bound receivers are excluded from
explicit
parameter entries, while static methods document their explicit parameters.
Bash/PowerShell named functions use meaningful immediately preceding native
comments; argument/result typing and execution semantics remain manual. Changed
batch bodies and unsupported script syntax may report BLOCKED coverage. SQL,
YAML, CSS, SVG, TeX and Apache rules receive native header checks, not invented
callable-docstring requirements.

## Numeric and runtime policy contracts

Constants do not need *declaration* docstrings. Independently, name added or
materially changed numeric policy choices in runtime code, tools and tests:
timeouts, sleep/retry intervals, size/count/depth bounds and defaults. Use a
purpose
sentence and meaningful `Type:`, `Units:`, `Scope:`, `Consumers:` and
`Rationale:`
fields; `@var`/`@type` can express the type. Never invent a rationale. Determine
it
from the protected behavior or make the design decision explicit before coding.
The file header cannot document an individual bound. State the actual value,
policy owner and reason at the constant. For named YAML configuration fields use
attached native comments; comment-free JSON needs the equivalent permanent
configuration reference. Explain structural literals (delimiter lengths,
indexing,
wire-format versions) and fixture boundary values locally; they are not tunable
operational policies.

Tooling, fixtures and advisory heuristics retain their existing scanner scope.
An exemption from the changed-runtime gate does not exempt whole-tree inventory
or waive this authoring rule. Review all related new policy sites in the batch,
including subprocess bridges and delegated children, before the first FULL.
Repair
undocumented owners without raising inventory caps or moving baseline debt.

These illustrative limits belong to an example preview renderer. They are not
recommended defaults for a production subsystem; use its existing policy owner.

<!-- checked-example: php-policy -->
```php
<?php
declare(strict_types=1);

/**
 * Bound the example preview renderer's simultaneously active object URLs.
 * @var int
 * Units: preview items. Scope: one example renderer instance.
 * Consumers: the example renderer's preview admission step.
 * Rationale: retain at most twelve decoded candidates while the user scrolls.
 */
const EXAMPLE_ACTIVE_PREVIEW_LIMIT = 12;
```

<!-- checked-example: js-policy -->
```js
/**
 * Bound source bytes admitted by the example local preview renderer.
 * Type: number.
 * Units: bytes. Scope: one selected source file in the example renderer.
 * Consumers: the example renderer's source admission step.
 * Rationale: reject sources above 24 MiB before allocating a local preview URL.
 */
const EXAMPLE_PREVIEW_SOURCE_LIMIT = 24 * 1024 * 1024;
```

## New-file attribution

Use the file's native leading comment format. Include the actual path, module
purpose, responsibilities and the established project attribution. This header
does not replace declaration or policy comments. Copy it only after correcting
the path and describing the actual module. Keep `declare(strict_types=1);` in
new
PHP files. JavaScript/CSS use block comments; Python/YAML/shell use hash
comments;
TeX uses percent comments and SVG uses XML comments.

<!-- checked-example: php-header -->
```php
<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/example_preview.php
 * Module Type: Preview Service
 * Purpose: Prepare bounded example preview metadata.
 * Responsibilities:
 *   - Validate example preview admission inputs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
```

The marked examples are parsed directly from this document by the registered
`tests/agent_authoring_examples_test.php` regression. It uses the existing
declaration, policy and header scanners without executing example source. It
also proves that deleting a summary, adding an undocumented parameter, replacing
the shape with `Object`, or removing policy explanation still fails. A changed
example therefore cannot silently drift away from the implemented checkers.

## Source feedback and comparison base

The [CI-first contract](../AGENTS.md#mandatory-agent-verification-contract) owns
agent execution. Verify branch identity, HEAD, remote head and explicit push
authorization before writes. Commit authored source, tests and documentation;
push only the exact authorized working ref. Never directly mutate develop/main.
Missing access means BLOCKED, before writes.

Before requesting FULL, read the complete base-to-candidate batch, including
delegated edits, against its affected owners and consumers:

| Changed contract | Review together |
| --- | --- |
| Declaration/delegation | Callers, imports, all adapters, arguments, types and docs. |
| HTTP mutation | Auth/CSRF, status/envelope, persisted state, errors and public completion. |
| Browser fixture | Visible/mock URL, method/envelope, owner generation, terminal phases and per-operation layout. |
| Persistence/import | Schema readiness, old precedence, atomic updates and reconciliation. |
| Runtime dependency | Contained closure, current counts/limits and expected deltas, including consumed allowance. |
| Catalog/policy | All four JSON key/placeholder sets and comments attached to owning policy sites. |

Record reviewed contracts and unresolved points in the working summary; label
estimates. Static review is not executed evidence and grants no local audit,
generator or CI-authority exception. Apply the whole-class repair procedure in
[.github instructions](../.github/AGENTS.md) before retrying a failed batch.

Follow that file's installed automatic preparation/squash path; use the manual
checkpoint only when automatic mode is disabled or unavailable. Include
generated
bot commits, preserve the prepared tree and verify one commit above the exact
develop parent, clean checkout and fresh BASIC for the final SHA. FULL must
qualify
that unchanged candidate before the guarded fast-forward.

Feature pushes/final squash run hosted **Basic source checks**, BASIC
`--changed`
against `git merge-base HEAD origin/main`, without preparation/FULL. The
approved
**Integrate into develop** action observes P, prepares A and runs FULL using the
owner-selected main or feature workflow. Seal execution before publication;
identical inputs may reuse it. Release trusts protected develop and assembles
artifacts without another source audit. Missing history/base blocks CI;
never substitute HEAD. Reports retain candidate/base/dirty-state identity. The
[CI technical contract](CI_COVERAGE_REUSE_CONTRACT.md) owns
authority/installation.

BASIC covers syntax and changed declaration docs, not native headers/FULL
inventory.
Review new/changed headers before commit. Any early gate must reuse the header
scanner/negative cases and receive separate CI-authority
qualification/installation;
documentation changes do not install coverage.

Local diagnostics follow only the root exceptions; do not routinely run audits,
generators or repeat green coverage. Inaccessible mandatory CI remains BLOCKED;
local/profile PASS is not release approval. Read scoped rules through the root
map.

Read hosted `source-failures.md` once per failed batch, then linked evidence;
repair all related findings before committing/pushing the same authorized branch
for fresh preparation/qualification. Read no passing child logs. Finish
permanent
Markdown/all-four-TeX edits before commit; leave PDFs/release markers/notes
alone
during ordinary development.

Handoff records workflow URL, branch, exact prepared SHA, immutable base, all
hosted
job results and skipped/blocked/manual coverage. Earlier source-event SHAs,
green
runs or stale candidates cannot qualify the current branch.

## Measuring prevention

Use existing run reports and commit history; do not add telemetry or a new
runner.
For a documented sample of authoring batches, record the first source-preflight
result, number of source-policy repair commits/runs, audit wall time and time to
actionable diagnostics. Count overlapping changed-source and inventory findings
separately, not as distinct defects. A representative fixture passing on its
first check proves the example contracts, not future agent reliability. Report
unknown real-workload savings until enough actual batches have been observed.
