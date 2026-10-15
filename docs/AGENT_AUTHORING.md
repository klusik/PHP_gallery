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

PHPDoc/JSDoc goes immediately above the named declaration. Python's docstring is
the first statement inside the declaration. Start with a factual purpose sentence
before annotation tags. Every explicit parameter needs its actual name, type and
description, including optional/defaulted, nullable, variadic and added parameters.
Document the actual return variants and their meaning, including `void`/`None`.
Class-like declarations need a purpose summary. Native PHP/Python signature types
are independent of the docstring; PHP constructors/destructors cannot declare a
native return type, but still document their void result.

Anonymous functions, closures, arrows and variable/member-bound callbacks need
no declaration docstring. Variables and properties have the same exemption.
Named functions or classes nested inside callbacks remain checked. Do not create
a named helper solely to satisfy documentation or put a docblock in call arguments.

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

Always search production imports, re-export modules and every regression
assertion for the old cache-busting module URL. Update the complete set together
and keep every exact expectation aligned with the new real import. Always
represent presentation choices as explicit value/label entries. Reserve literal
`'page' => 'target'` entries for registered routes because the route-reference
check validates those entries as route targets.

### Implementation checks learned from hosted CI

For SQL embedded in PHP, review the outer PHP string and inner SQL literals as
two syntax layers. For example, a double-quoted PHP query can contain the fixed
SQL condition `kind <> 'history'`, while changing values remain bound parameters.
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
process's loaded functions do not establish the child's include contract.

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

For runtime-plan ownership, inspect the full historical closure before adding a
shared dependency edge. Reuse an existing logical owner only when all of its
files already belong to that consumer's closure. Generate from the authored
roots/dependencies, inspect plan-wide duplicate entries and each affected route's
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
row fields and nullable result describe the actual returned value; native `array`
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

Tag-style Python docstrings are also supported. Named nested functions and async
methods follow the same rule; ordinary bound receivers are excluded from explicit
parameter entries, while static methods document their explicit parameters.
Bash/PowerShell named functions use meaningful immediately preceding native
comments; argument/result typing and execution semantics remain manual. Changed
batch bodies and unsupported script syntax may report BLOCKED coverage. SQL,
YAML, CSS, SVG, TeX and Apache rules receive native header checks, not invented
callable-docstring requirements.

## Runtime policy contracts

Constants do not need *declaration* docstrings. Independently, added or materially
changed PHP/JS runtime policy sites need attached explanations. Use a purpose
sentence and meaningful `Type:`, `Units:`, `Scope:`, `Consumers:` and `Rationale:`
fields; `@var`/`@type` can express the type. Never invent a rationale. Determine it
from the protected behavior or make the design decision explicit before coding.
The file header cannot document an individual bound. Tooling, fixtures and
advisory heuristics retain their existing scanner scope.

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
the path and describing the actual module. Keep `declare(strict_types=1);` in new
PHP files. JavaScript/CSS use block comments; Python/YAML/shell use hash comments;
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

Always verify one final integration commit above current develop after generated
candidate preparation, including bot-authored artifacts. When preparation adds
a commit, cancel the in-flight integration before expensive FULL execution,
fetch that head and perform the authorized feature-only squash with an exact
remote-head lease. Preserve and compare the prepared tree, verify one commit and
the exact develop parent, and obtain fresh BASIC on the rewritten SHA. Dispatch
integration again once idempotent preparation can retain that SHA, then complete
FULL on the final candidate that the workflow will integrate.

Hosted candidate preparation resolves `git merge-base HEAD origin/main` once and
retains its immutable SHA through preparation and qualification. Release workflows
use their previous stable tag. Missing history/base blocks CI; substituting HEAD
would hide already committed omissions. The central runner passes the same base
to declaration/policy checkers and records candidate identity and dirty state.

GitHub uses source-only `release-preflight` before generation, read-only
`candidate-preflight` before expensive jobs, and full coverage for ordinary
handoff. These profiles do not establish release approval. No local audit or
generator is a default agent step, and successful hosted coverage is not repeated
locally. Focused local diagnostics follow only the explicit exceptional paths in
AGENTS.md; unavailable CI remains BLOCKED regardless of local evidence.

Read hosted `source-failures.md` once for all failed declarations, policy sites,
blocked coverage and over-budget categories. Repair the entire relevant batch,
commit and push the same branch for fresh preparation/qualification. Do not read
passing child logs. Finish all permanent Markdown/all-four-TeX source edits before
the source commit; ordinary development leaves PDFs and release markers/notes alone.

Handoff records workflow URL, branch, exact prepared candidate SHA, immutable base,
full hosted job results and material skipped/blocked/manual coverage. A source-event
SHA, earlier green run or stale candidate cannot qualify the current branch.

## Measuring prevention

Use existing run reports and commit history; do not add telemetry or a new runner.
For a documented sample of authoring batches, record the first source-preflight
result, number of source-policy repair commits/runs, audit wall time and time to
actionable diagnostics. Count overlapping changed-source and inventory findings
separately, not as distinct defects. A representative fixture passing on its
first check proves the example contracts, not future agent reliability. Report
unknown real-workload savings until enough actual batches have been observed.
