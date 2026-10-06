# Patch Notes Generation Template

This document defines the required format for AI agents generating new entries for `PATCH_NOTES.md`.

Do not overwrite existing patch notes. Add the newest version entry above older versions and keep the established Markdown structure.

## Version numbering rules

- Version numbers may use either `X.Y` or `X.Y.Z` format.
- `X`, `Y`, and optional `Z` are numeric identifiers and may contain any number of digits.
- Valid examples: `1.2`, `1.23`, `3.232.101`, `10.500.12345`.
- Do not use leading zeroes. Examples like `1.02` or `2.5.001` are invalid.

## Required structure

```markdown
## Version X.Y

Short release summary paragraph explaining the purpose of the release, main features, and important changes.

  ### Highlights

  #### Added feature or changed area name

  - Describe user-visible changes.
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

- Use Markdown headings exactly like existing `PATCH_NOTES.md` entries.
- Use version heading level `##`.
- Use main sections with `###`.
- Use feature groups with `####`.
- Use bullet lists for individual changes.
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

- When a change has an available, evidenced issue or pull-request reference, include a clickable Markdown link beside the relevant change or feature group. A bare `#70` is not sufficient.
- Use the canonical URL for the actual repository, for example `[#70](https://github.com/klusik/PHP_gallery/issues/70)` or `[PR #77](https://github.com/klusik/PHP_gallery/pull/77)`.
- Establish references from the reviewed commits, issue/PR metadata, or supplied task context. Resolve the repository from its configured remote; distinguish issues from pull requests and retain the repository identity for cross-repository references.
- If several references apply, link each one separately. An optional references list may supplement links beside the changes.
- Do not invent references, substitute an unrelated issue, or imply that an issue was closed merely because it is linked. If no reliable reference is available, omit it.
- Apply this rule to newly authored entries; preserve historical entries unless their revision is explicitly requested.

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
  differently where applicable. For security/authentication, describe fail-closed
  behavior. For destructive or ingestion workflows, explicitly document the
  preflight point, any proven legacy compatibility/bootstrap path, recoverability on
  refusal, and whether credential revocation intentionally uses a narrower schema
  than issuance/authentication. For optional presentation/reporting features, state
  whether an unavailable read can be omitted safely, which writes still require
  conclusive schema, and whether feature-disabled health checks avoid unnecessary
  metadata probes.
- When System Health or Runtime Diagnostics changes, list the new capability keys or
  groups and confirm that visible/logged context is bounded and does not expose raw
  SQL, database exceptions, credentials, tokens, or private filesystem paths.

Keep entries detailed enough that users can understand the release without reading Git diffs.
