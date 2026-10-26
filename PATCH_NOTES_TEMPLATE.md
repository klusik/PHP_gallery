# Patch Notes Generation Template

This document defines the required format for AI agents generating new entries
for `PATCH_NOTES.md`.

Do not overwrite existing patch notes. Add the newest version entry above older
versions and keep the established Markdown structure.

## Version numbering rules

- Version numbers may use either `X.Y` or `X.Y.Z` format.
- `X`, `Y`, and optional `Z` are numeric identifiers and may contain any number
  of digits.
- Valid examples: `1.2`, `1.23`, `3.232.101`, `10.500.12345`.
- Do not use leading zeroes. Examples like `1.02` or `2.5.001` are invalid.

## Required structure

```markdown
## Version X.Y

Short release summary paragraph explaining the purpose of the release, main
features, and important changes.

  ### Highlights

  #### Added feature or changed area name

  - Describe user-visible changes with enough context to explain the completed
    behavior and its practical effect.
  - Keep every item as a clear completed action.
  - Group related behavior together.

  ### Technical Details

  #### Backend

  - Added `route_or_identifier` route.
  - Added service logic in `app/services/example.php`.
  - Updated `app/controllers/example.php` to support the workflow.

  #### Database

  - Added migration `YYYYMMDDNNNN_description.php`.
  - Describe new tables, columns, indexes, and migration behavior.

  #### Frontend

  - Added `public/assets/gallery-modules/example.js`.
  - Updated CSS, templates, and browser interactions.

  #### Tests

  - Added `tests/example_test.php`.
  - Describe covered behavior.

  ### User Impact

  #### For visitors

  - Describe public-facing improvements.

  #### For administrators

  - Describe admin workflow improvements.
```

## Formatting rules

- Always include all three main sections: `### Highlights`,
  `### Technical Details`, and `### User Impact`, including for releases limited
  to tests, documentation, or tooling.
- In `### User Impact`, explain the evidenced visitor, administrator, or
  maintainer impact. Explicitly state when public or administrator behavior did
  not change instead of inventing a product improvement.
- Only optional `####` subsections without relevant changes may be omitted; the
  three main sections remain mandatory.
- Use Markdown headings exactly like existing `PATCH_NOTES.md` entries.
- Use version heading level `##`.
- Use main sections with `###`.
- Use feature groups with `####`.
- Use bullet lists for individual changes.
- Wrap newly authored prose at existing word spaces, targeting 80 columns.
  At 81-100 columns, choose a natural word boundary; actively split prose above
  100 columns. Use source line breaks within the same paragraph, not new blank
  lines or Markdown hard breaks.
- Indent wrapped list continuations under the item's text, as in the example
  above. Preserve paragraph boundaries, headings, tables and explicit hard
  breaks.
- Keep complete Markdown links, URLs, inline code and other indivisible
  constructs intact, allowing them to exceed the preferred width when needed.
- Apply wrapping to the new release entry only; preserve older entries unless
  their revision is explicitly requested.
- Use past tense: "Added", "Updated", "Fixed", "Improved".
- Avoid vague entries such as "changed some files".

## Code and filename references

Always wrap technical identifiers in backticks:

```markdown
- Added `app/services/photo_processor.php` for image handling.
- Added migration `202606040001_example.php`.
- Updated `gallery_render()` behavior.
```

Reference complete paths from repository root whenever possible.

## Content expectations

### Issue and pull-request references

- Link every evidenced issue or pull request directly addressed by a concrete
  change in this release, beside that change or feature group. Explain the
  delivered behavior so the relationship is clear. A bare `#70` is insufficient.
- Use the canonical URL for the actual repository, for example
  `[#70](https://github.com/klusik/PHP_gallery/issues/70)` or
  `[PR #77](https://github.com/klusik/PHP_gallery/pull/77)`.
- Establish references from the reviewed commits, issue/PR metadata, or supplied
  task context. Resolve the repository from its configured remote; distinguish
  issues from pull requests and retain the repository identity for
  cross-repository references.
- Treat reference mentions as candidates: match each to its commit's purpose,
  changed paths and the current source diff. A number appearing somewhere in
  the evidence does not establish a relationship to every change in the release.
- Previous release notes provide style only. Historical documentation, fixture
  examples and routine release origin/predecessor/version/manifest bookkeeping
  do not establish current issue work. Do not present routine bookkeeping as a
  newly implemented feature or repeat the issues that introduced that process.
- Apply the same contextual rule to open, closed and previously referenced
  issues. A new fix to an older issue may qualify; its status or age alone does
  not establish or remove relevance. There is no fixed required issue list.
- If several references apply, link each one separately. An optional references
  list may supplement links beside the changes.
- Do not invent references, substitute an unrelated issue, or imply that an
  issue was closed merely because it is linked. If no reliable reference is
  available, omit it.
- Apply this rule to newly authored entries; preserve historical entries unless
  their revision is explicitly requested.

A complete release entry should include:

- User-facing changes.
- Admin-facing changes.
- Backend changes.
- Frontend changes.
- Database migrations.
- New services, controllers, scripts, or tools.
- Tests added or changed.
- Compatibility notes when relevant.
- For schema-sensitive changes, state whether each affected operation treats
  `available`, confirmed `missing`, `unknown`, and configuration `disabled`
  differently where applicable. For security/authentication, describe
  fail-closed
  behavior. For destructive or ingestion workflows, explicitly document the
  preflight point, any proven legacy compatibility/bootstrap path,
  recoverability on
  refusal, and whether credential revocation intentionally uses a narrower
  schema
  than issuance/authentication. For optional presentation/reporting features,
  state
  whether an unavailable read can be omitted safely, which writes still require
  conclusive schema, and whether feature-disabled health checks avoid
  unnecessary
  metadata probes.
- When System Health or Runtime Diagnostics changes, list the new capability
  keys or
  groups and confirm that visible/logged context is bounded and does not expose
  raw
  SQL, database exceptions, credentials, tokens, or private filesystem paths.

Keep entries detailed enough that users can understand the release without
reading Git diffs.
