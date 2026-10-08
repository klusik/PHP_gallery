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
