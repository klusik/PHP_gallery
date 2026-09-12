# Temporary implementation plan: visibility eye on gallery cards

> Working document for the follow-up implementation agent. Remove this file after the feature is implemented and its verification is complete.

## Scope

Add an eye action beside the existing trash and edit actions on every applicable Admin gallery card: galleries, subgalleries, and pictures. The action must expose the three supported visibility states and allow an authorized administrator to switch the current object between them:

- published — normal/open eye;
- unpublished — crossed/hidden eye;
- private — distinct private/locked-eye variant.

The exact persisted values, labels, icons, endpoints, and entity identifiers must be taken from the existing visibility/access implementation discovered in the codebase. Do not introduce a parallel visibility setting or vocabulary.

## Required discovery before editing

1. Read `AGENTS.md`, `ARCHITECTURE.md`, `TESTING.md`, and all relevant Markdown documentation. Record any existing gallery-card, visibility, side-panel, mutation-envelope, and icon conventions.
2. Locate the server-rendered card partials/views for galleries, subgalleries, and pictures, plus the shared trash/edit action markup and their CSS/JS owners.
3. Locate the canonical visibility/access service and its existing published/unpublished/private values, authorization checks, CSRF handling, and mutation helpers.
4. Locate existing Admin visibility controllers/routes, if any. Reuse their service and policy logic where possible instead of adding a second mutation path.
5. Locate the Admin side-panel/AJAX coordinator and its dynamic-fragment event binding. The eye submenu must work for initially rendered and dynamically refreshed cards.
6. Locate existing icon assets or the project’s icon-library convention. Reuse those assets/classes; add new artwork only if no suitable established variant exists.
7. Locate focused tests covering gallery-card actions, visibility, admin mutations, side-panel persistence, and dynamic forms. Add the new assertions to the appropriate registered suites.

## Implementation requirements

1. Render one accessible eye trigger beside edit and trash for each supported card type. Include the current state in server-rendered markup so the control is meaningful without JavaScript.
2. On hover and click, show a small submenu with exactly the three canonical states. Keep the submenu keyboard accessible: focusable trigger, visible focus state, Escape-to-close, outside-click close, and usable labels/tooltips. Do not rely on color alone.
3. Use event delegation or the existing panel binding mechanism so injected/re-rendered cards are intercepted automatically.
4. When JavaScript is enabled, submit through the existing in-place Admin side-panel/AJAX workflow. Keep the URL unchanged, keep the panel open, avoid navigation/reload, and replace only the owned card/fragment after success.
5. Return and preserve the canonical mutation envelope from `app/helpers_mutation.php`, including typed mutation metadata, stable entity IDs, affected contexts/postconditions, panel refresh metadata where applicable, and fallback metadata.
6. Enforce authentication, authorization, CSRF, visibility policy, and schema/mutation readiness through existing services. Unknown schema state must not be treated as permission to mutate.
7. Define the semantics for parent/child visibility explicitly from existing behavior: changing one card must not silently change descendants unless the current product policy already requires that behavior. Test galleries, subgalleries, and pictures independently.
8. Preserve the non-JavaScript/direct POST fallback, but do not let it become the normal path for a card action.
9. Update cache-busting imports/manifests for any changed JavaScript or managed application file. Do not edit `PATCH_NOTES.md` for this feature-planning task.

## Verification

1. Add focused regression coverage for all three states and all three card types, including authorization/CSRF rejection and invalid-state rejection.
2. Add browser/contract coverage proving hover/click submenu behavior, unchanged URL, in-place update, panel persistence, keyboard access, and handling of dynamically re-rendered cards.
3. Run `php scripts/check_admin_mutation_contracts.php` when the persistent mutation or dynamic form wiring changes.
4. During implementation, use `php scripts/audit.php --profile=quick` when a verification pass is useful.
5. Before handoff, run `php scripts/audit.php --profile=full` once and inspect its compact summary. Diagnose only reported failures with focused tests, then rerun the appropriate central audit.
6. If an updater-managed release file changed, run `php scripts/generate_manifest.php` followed by `php scripts/generate_manifest.php --check` before handoff.

## Handoff checklist

- [ ] Discovery findings and exact paths are filled into this document.
- [ ] Existing visibility policy and values are reused.
- [ ] All three card types expose the eye control.
- [ ] Published, unpublished, and private states have distinct accessible icons/labels.
- [ ] AJAX/in-place and no-JavaScript paths are covered.
- [ ] Dynamic re-rendering and side-panel contracts are covered.
- [ ] Central full audit passes.
- [ ] This temporary file is removed after implementation.
