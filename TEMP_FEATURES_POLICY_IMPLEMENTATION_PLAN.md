# TEMP: Optional Capability and Feature-Control Architecture Roadmap

> Temporary implementation roadmap for the PHP Gallery feature/settings expansion.
>
> This file is intentionally a phased engineering plan, not permanent product documentation. Remove it before a release once the work has been completed and the permanent behavior has been transferred into `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `README.md`, `TESTING.md`, the Admin manual, and release notes as appropriate.

## 1. Purpose

This roadmap converts the current Admin > Features implementation from a mostly flat set of Boolean feature flags into a coherent, registry-driven capability policy system that can safely control:

- existing feature flags,
- existing canonical global settings that act as subsystem master switches,
- route availability,
- Admin navigation visibility,
- public UI visibility,
- background work,
- outbound network behavior,
- subsystem dependencies,
- fresh-install versus upgrade defaults,
- and reversible data-preservation semantics.

The objective is **not** to add dozens of disconnected `is_x_enabled()` helpers or duplicate existing settings under new names.

The objective is to create a foundation where later feature additions are mostly declarative: register the capability once, bind it to the existing canonical state owner, assign routes/UI ownership and dependencies, then use one policy layer everywhere.

This must be a structural update, not a collection of patches.

---

# 2. Non-negotiable architectural rules

## 2.1 One source of truth per setting

Never store the same administrator decision in two independent places.

Examples:

- Gallery Trash already uses `gallery_trash_enabled`. Do **not** create an independent `feature_flag.gallery_trash.enabled` value.
- Development diagnostics already uses `dev_mode_enabled`. Do **not** create a second diagnostics Boolean.
- Automatic updates already use `application_autoupdate_enabled`. Do **not** create a second auto-update Boolean.
- Thumbnail background warmup already uses `thumbnail_background_warmup_enabled`. Do **not** introduce another persisted master switch for the same behavior.

A capability may be *presented* on Admin > Features while still being backed by an existing domain setting. The policy layer must delegate to that existing owner.

## 2.2 Preserve existing domain workflows and side effects

A generic capability writer must not bypass a domain setter that performs important lifecycle work.

Example: Gallery Trash settings are not a simple raw Boolean write. `set_gallery_trash_settings()` deliberately:

- disables destructive paths first,
- preserves existing Trash entries,
- preserves the independent auto-purge preference,
- rearms retention deadlines when destructive automatic purge becomes newly active,
- normalizes retention and batch values,
- and then persists the final state.

Therefore, toggling Gallery Trash from Admin > Features must delegate through the existing Trash workflow while preserving the current values of auto-purge, retention days and purge batch. It must **not** call `set_app_setting('gallery_trash_enabled', ...)` directly.

The same rule applies to any existing setting whose setter has normalization, safety checks, cache invalidation, schema checks, revision bumps, token revocation, maintenance state transitions, or other side effects.

## 2.3 Do not create a second parallel feature framework

The existing `app/services/feature_flags.php` is the natural compatibility boundary and should be evolved rather than abandoned.

Recommended pattern:

- keep `app/services/feature_flags.php` as the module entry point,
- preserve its module docblock and existing public compatibility functions,
- split it into `app/services/feature_flags/*.php` part files only if its size becomes uncomfortable,
- keep all part files private to that module and require them only from the entry point, following the repository module-split contract,
- evolve the registry from "simple flags" into a richer optional-capability registry,
- add generic policy functions in the same service module,
- keep existing `feature_flag_*` functions as compatibility adapters where required.

Do not introduce a new unrelated `FeatureManager`, `CapabilityManager`, or service container class merely to wrap procedural functions. The repository is deliberately a plain namespaced PHP service architecture. A Registry + Policy + Adapter pattern fits the existing codebase better than an isolated object-oriented subsystem.

## 2.4 Distinguish configured state from effective state

This distinction is essential.

A capability can be configured ON but effectively unavailable because a required parent capability is OFF.

Example:

- Picture Game may be configured ON.
- Image Voting may be globally OFF.
- Picture Game should then be effectively unavailable without silently changing the administrator's stored Picture Game preference.

The policy layer therefore needs two concepts:

### Configured state

The persisted administrator preference for that capability.

This is what the checkbox on Admin > Features should represent.

### Effective state

Whether the capability may actually execute after applying:

1. the configured state,
2. parent/dependency capabilities,
3. installation/schema availability where relevant,
4. subordinate site/gallery/entity configuration where the caller owns that logic,
5. authorization.

The policy layer should own items 1 and 2. Existing schema policy and caller-specific settings should continue to own the later layers.

Do not conflate "administrator left the feature enabled" with "the feature is active on this page right now".

## 2.5 Global master OFF always wins

A gallery-level, Smart Gallery-level, Theme-level, or site-level subordinate preference must never re-enable a globally disabled capability.

Required precedence:

1. stored local preference,
2. site/theme preference,
3. **global capability master**, applied last.

Examples:

- a Smart Gallery with `download_enabled = true` must still have downloads disabled when the global download capability is OFF,
- a Smart Gallery with `lightbox_enabled = true` must still have lightbox disabled when the global lightbox capability is OFF,
- an individual gallery's voting setting must never override global Image Voting OFF.

This must become a documented and tested invariant.

## 2.6 Feature disable is reversible and non-destructive by default

Turning a feature OFF should normally remove its availability, not its stored data.

Examples:

- Smart Galleries remain stored with their rules, placements and presentation settings.
- translations remain stored,
- tags remain stored,
- duplicate-review ledger entries remain stored,
- Viewer Accounts remain stored,
- Trash contents remain stored,
- cached favicons remain stored,
- historical telemetry remains stored unless separately deleted,
- local presentation settings remain stored.

Permanent cleanup or deletion must remain a separate explicit action.

## 2.7 Security/integrity policy must not become optional capability policy

Do not create master feature switches that can disable enforcement of:

- gallery visibility/access authorization,
- share authorization,
- password/private gallery protection,
- CSRF protection,
- authentication hardening,
- NSFW access restrictions,
- source-image authorization,
- mutation schema policy,
- presentation schema safety policy,
- database migration integrity,
- core logging required for operational diagnosis.

Optional UI for configuring those systems may be hidden if a future design requires it, but existing enforcement must remain active.

## 2.8 Core policy lookups should be strict; legacy compatibility can remain fail-open

Current `feature_flag_enabled()` deliberately returns true for an unknown key for compatibility with older extension code or partially deployed files.

Do not silently carry this behavior into the new internal policy API.

Recommended rule:

- new internal canonical policy lookup: unknown capability key fails closed and emits a bounded diagnostic in production; tests should fail loudly,
- legacy `feature_flag_enabled()` wrapper: preserve its documented unknown-key fail-open behavior for compatibility until intentionally deprecated.

All core application code should gradually migrate to the strict canonical policy API so a typo in a feature key cannot silently expose functionality.

## 2.9 Reuse existing schema-policy infrastructure

Do not create feature-specific schema booleans when the capability already has a resolver in:

- `app/services/presentation_schema_policy.php`,
- `app/services/mutation_schema_policy.php`,
- `app/services/schema_inspection.php`.

Feature state and schema state are different axes:

- capability OFF means do not use the optional subsystem,
- capability ON + schema missing may mean optional omission,
- capability ON + schema unknown at a mutation boundary must follow the existing fail-closed mutation policy.

The capability registry may reference the name of an existing schema capability for diagnostics, but should not duplicate schema probing logic.

## 2.10 Keep tests centralized through the repository audit runner

During implementation:

- use `php scripts/audit.php --profile=quick` when a verification pass is useful,
- before each affected-files handoff, run `php scripts/audit.php --profile=full` once,
- diagnose individual tests only when the central audit reports a specific failure,
- do not manually enumerate and run the entire `tests/` tree.

Each stage below should be independently mergeable and should produce an affected-files ZIP checkpoint when implemented.

---

# 3. Target policy model

The exact array shape may be adjusted during implementation, but the final registry must be capable of describing each optional capability without scattering ownership information across unrelated files.

Each registry entry should be able to define at least the following concepts.

| Metadata | Purpose |
| --- | --- |
| stable key | Canonical identifier, for example `downloads`, `smart_galleries`, `gallery_trash` |
| group | Admin > Features grouping |
| label/description | Localized UI metadata |
| source type | Existing feature-flag storage, existing app setting, domain adapter, or derived capability |
| default configured state | Upgrade-compatible default when no persisted value exists |
| fresh-install default | Optional different value explicitly seeded only for a new installation |
| editable on Features page | Whether Admin > Features may change it |
| routes | Routes exclusively owned by the capability |
| route condition | Single capability, `all_of`, or `any_of` requirement where necessary |
| dependencies | Capability keys that must also be effectively enabled |
| UI ownership metadata | Optional information used by menu/cards/settings discovery |
| behavior tags | Public, Admin-only, writes files, outbound network, background work, privacy, destructive capability, diagnostic |
| data disable policy | Normally `preserve` |
| specialized settings route | Where subordinate configuration lives |
| optional schema capability | Existing schema-policy capability identifier for diagnostics only |

Do not put arbitrary executable business logic in the registry where a domain service already exists. Use an adapter that delegates to that domain service.

---

# 4. Storage model

## 4.1 Keep `app_settings`

Do not create a new database table merely to store Boolean feature state.

The existing `app_settings` table already provides:

- stable global key/value persistence,
- request-local caching,
- default fallback behavior,
- safe upsert through `set_app_setting()`,
- compatibility with old installations before every optional value exists.

Continue using it.

## 4.2 Existing feature-flag storage remains valid

The existing convention:

`feature_flag.<key>.enabled`

should remain valid for capabilities that are genuinely owned by the feature-flag subsystem.

Do not migrate those values to different keys unless there is an unavoidable reason.

## 4.3 Bridged capabilities retain their current keys

Examples:

- Gallery Trash -> `gallery_trash_enabled`
- Public thumbnail self-healing -> `thumbnail_background_warmup_enabled`
- Development diagnostics -> `dev_mode_enabled`
- Automatic updates -> `application_autoupdate_enabled`

The feature policy registry references these existing owners instead of copying them.

## 4.4 Fresh-install versus upgrade defaults

Some capabilities should preserve current behavior on upgrade but start conservatively on a new installation.

The initial known cases are:

| Capability | Existing-install fallback | Fresh install |
| --- | ---: | ---: |
| Public thumbnail self-healing | Enabled | Disabled |
| Remote favicon discovery, once switchable | Enabled | Disabled |

Do not try to infer "fresh install" from missing individual settings during ordinary requests.

Instead, add one explicit setup-time seeding workflow that is called only during first installation after migrations have completed and before the installation is handed to the administrator.

The setup seeder should:

- write only declared fresh-install defaults,
- be idempotent for the first-install workflow,
- never run during a normal upgrade,
- never overwrite an administrator-modified installation,
- use the same canonical policy/domain writers where safe,
- avoid activating destructive side effects merely while creating defaults.

If the existing setup process already has an appropriate first-install settings seeding boundary, extend it. Do not create another setup lifecycle.

## 4.5 Database migrations

The foundation itself should **not** add a capability table.

Add a migration only when a later stage introduces a genuinely new persisted setting that needs an explicit row or when a schema change is independently required by that feature.

For Boolean `app_settings` values whose absence is already safely handled by `app_setting(..., default)`, a migration is not mandatory.

If a new migration seeds a setting for compatibility, use the repository's established "insert missing, preserve existing administrator value" semantics. Never rewrite an existing administrator's choice during upgrade.

---

# 5. Canonical API expectations

Names may be adjusted to fit the final implementation, but the module needs clear APIs with non-overlapping semantics.

Recommended conceptual API:

### Registry

- capability definitions
- capability groups
- capability existence validation
- capability metadata lookup

### State

- configured state lookup
- effective state lookup
- generic enabled-state persistence through source adapters
- summary state for Admin UI

### Route policy

- route requirement lookup
- route allowed check
- disabled-route response

### Admin discovery

- grouped capability definitions
- capability badges/tags
- specialized settings links

### Legacy compatibility

Existing public functions such as:

- `feature_flag_enabled()`
- `set_feature_flag_enabled()`
- `feature_flag_definitions()`
- `feature_flag_groups()`
- `feature_flag_route_map()`
- `feature_flag_route_enabled()`

must either remain behavior-compatible wrappers or be migrated with focused tests proving that all existing callers continue to work.

Do not add one public getter for every new capability. The whole point is to avoid `smart_galleries_enabled()`, `duplicate_detector_enabled()`, `metadata_organizer_enabled()`, `public_tags_enabled()`, and dozens of equivalent one-line wrappers unless a domain service genuinely needs a semantic API for reasons beyond feature toggling.

Existing domain getters that predate the policy layer may remain and can be used as adapter targets.

---

# 6. Route-policy model

The current single route-to-feature map is too limited because at least one route already has a special case where either of two capabilities may authorize it.

The replacement route policy must support:

- exactly one required capability,
- all-of requirements,
- any-of requirements.

Examples:

- `gallery_lightbox_data` -> requires Lightbox,
- Smart Gallery lightbox metadata -> requires Smart Galleries AND Lightbox,
- `gallery_map_data` -> allowed when Gallery Maps OR Flight Maps is effectively enabled,
- Smart Gallery download routes -> require Smart Galleries AND Public Gallery Downloads,
- Viewer routes -> require Viewer Accounts.

The central dispatcher remains the authoritative route gate.

Do not scatter direct controller-only feature rejection as the primary protection. Controllers may still assert feature state defensively at important mutation/service boundaries, but the dispatch registry should prevent disabled capability routes from being normally entered.

Public disabled responses should not advertise hidden optional functionality to anonymous visitors. For public-only subsystems such as Viewer Accounts and Smart Galleries, use the same non-advertising not-found behavior when appropriate. Authenticated Admin routes may identify the disabled capability and link back to Admin > Features.

JSON/AJAX responses must remain structured and deterministic.

---

# 7. Stage overview

| Stage | Purpose | Type |
| --- | --- | --- |
| 0 | Freeze baseline contracts and produce ownership inventory | Foundation |
| 1 | Refactor feature flags into canonical capability registry/policy module | Foundation |
| 2 | Add persistence adapters and fresh-install/upgrade default workflow | Foundation |
| 3 | Generalize route/dependency policy and strict master precedence | Foundation |
| 4 | Repair existing feature-switch contract inconsistencies | Foundation / correctness |
| 5 | Upgrade Admin > Features and centralized Settings discovery | Foundation / UI |
| 6 | Smart Galleries master capability | Higher-level integration |
| 7 | Inline public administration capability | Higher-level integration |
| 8 | Gallery Trash and public thumbnail self-healing integration | Higher-level integration |
| 9 | Multilingual authored content capability | Higher-level integration |
| 10 | Public tag browsing capability | Higher-level integration |
| 11 | Optional Admin tools capability bundle | Higher-level integration |
| 12 | Diagnostics, remote network behavior and deployment-policy controls | Higher-level integration |
| 13 | Cross-feature health, dependency diagnostics and contract audits | Hardening |
| 14 | Documentation, cleanup and release-readiness integration | Finalization |

Stages 0 through 5 are deliberately architecture-heavy. Later stages should not invent new toggle mechanics. They should consume the foundation.

---

# STAGE 0: Baseline ownership inventory and regression contract

## Goal

Create an authoritative inventory of current feature ownership before changing behavior.

This stage should make no intentional user-visible behavior change.

## Work

1. Enumerate every currently registered feature key from `app/services/feature_flags.php`.
2. Enumerate every call site of feature-state lookup.
3. Enumerate every route currently present in `app/bootstrap/dispatch.php`.
4. Map each optional route to one of:
   - core route,
   - single capability owner,
   - multiple capability requirement,
   - currently unowned optional route that must be fixed later.
5. Inventory Admin navigation items with existing `feature` ownership metadata.
6. Inventory public UI controls whose target route is feature-owned.
7. Inventory existing canonical global settings that function as master switches.
8. Inventory existing domain getters/setters so later stages reuse them.
9. Record known route/UI gaps found during the analysis:
   - physical-gallery download hero remains visible while downloads are globally disabled,
   - Smart Gallery local presentation can defeat global Lightbox/Downloads OFF,
   - Smart Gallery lightbox data route is not owned by Lightbox,
   - Picture Manager selected-photo ZIP route is not owned by the Picture Manager policy,
   - development profiler ownership does not consistently follow `dev_mode_enabled`.
10. Add a focused static/regression test that verifies every capability key used by core code exists in the registry, while explicitly excluding the legacy fail-open extension compatibility boundary.
11. Add a route ownership test that can identify optional routes with no declared owner.

## Files likely affected

- `app/services/feature_flags.php`
- `app/bootstrap/dispatch.php` only if a test helper needs exported route metadata; avoid behavior changes here yet
- `tests/feature_policy_inventory_test.php` or equivalent
- `CODEMAP.md` only if permanent architecture documentation is updated during implementation

## Acceptance criteria

- Existing feature behavior is unchanged.
- Existing feature defaults are unchanged.
- Unknown core feature keys are detectable by tests.
- The test suite contains an explicit list/derivation of the known ownership gaps so later stages cannot accidentally forget them.
- Full audit passes.

## Checkpoint

Produce an affected-files ZIP before starting Stage 1.

---

# STAGE 1: Canonical capability registry and policy core

## Goal

Turn the existing feature-flag service into the single registry/policy authority without yet adding most new features.

This is the main architectural stage.

## Design

Use the existing `feature_flags.php` service as the entry point.

If it grows too large, split it using the repository's established module pattern, for example conceptually:

- entry point: `app/services/feature_flags.php`
- registry part
- state/persistence part
- route-policy part
- Admin presentation part
- adapters part

Exact part filenames are implementation details. Do not register part files separately in `app/services.php`.

## Work

1. Expand the existing feature definition metadata into a capability definition structure.
2. Keep all existing feature keys stable.
3. Introduce a strict internal capability lookup.
4. Introduce separate configured-state and effective-state concepts.
5. Preserve `feature_flag_enabled()` as a compatibility wrapper.
6. Preserve its unknown-key fail-open behavior explicitly in the wrapper, not in the strict core policy function.
7. Introduce dependency support in definitions.
8. Define Picture Game's dependency on Image Voting in one place instead of requiring repeated pairs of checks in controllers/services.
9. Ensure recursive dependency resolution is bounded and cycle-safe.
10. Add a registry validation routine for tests that rejects:
    - unknown groups,
    - malformed storage definitions,
    - self-dependency,
    - dependency cycles,
    - unknown dependencies,
    - duplicate route ownership declarations that conflict,
    - invalid default values.
11. Keep labels and descriptions localized through the existing translation service.
12. Avoid schema probes during simple registry construction. Registry assembly must remain lazy and inexpensive.
13. Preserve request-local `app_settings` caching. Capability state checks must not add N+1 setting queries.
14. Preserve all docstrings when moving existing functions.

## Configured vs effective state rules

- Admin checkbox reads configured state.
- Core UI/route/service gate reads effective state.
- Effective state = configured state AND effective dependencies.
- A disabled dependency must not overwrite the dependent capability's stored configured preference.
- Re-enabling the dependency should automatically restore the dependent capability if its configured preference remained ON.

## Existing call-site migration

Do not immediately create new per-feature wrappers.

Where a call site currently checks two flags because one is a dependency, migrate it to the canonical effective-state lookup.

Where a call site needs the raw configured checkbox state, explicitly use configured-state lookup.

## Acceptance criteria

- Existing 19 feature switches render with the same configured checkbox values as before.
- Established flags still default Enabled unless explicitly declared otherwise.
- Viewer Accounts remains default Disabled.
- Admin Test Runs remains default Disabled.
- Picture Game effective state correctly follows Image Voting without changing its stored preference.
- Registry validation catches dependency cycles and unknown dependencies.
- Unknown core capability lookup fails closed.
- Legacy `feature_flag_enabled('unknown-extension-key')` remains compatibility fail-open.
- No new database table exists.
- Full audit passes.

## Checkpoint

Produce an affected-files ZIP before Stage 2.

---

# STAGE 2: Persistence adapters and installation-default lifecycle

## Goal

Allow Admin > Features to represent capabilities whose canonical state already lives outside `feature_flag.<key>.enabled`, without duplicating storage.

Establish a safe fresh-install default workflow.

## Work

### 2.1 Add storage source types

The canonical registry must support at least:

1. native feature-flag storage,
2. existing app-setting Boolean storage,
3. domain adapter storage where a domain getter/setter owns important semantics,
4. derived/read-only capability if later needed.

Do not expose raw storage implementation details to controllers.

### 2.2 Add domain adapters for existing settings

Initial adapters should be designed for:

- Gallery Trash,
- thumbnail warmup/self-healing,
- development diagnostics,
- automatic updates when/if shown on the Features surface.

Adapter rules:

- use existing getters,
- use existing setters where available,
- if a domain only has a compound setter, call the compound setter with the unchanged current subordinate values,
- do not bypass normalization or lifecycle behavior,
- do not duplicate setting keys in the controller.

### 2.3 Missing narrow setters

Only add a new domain setter when there is an existing domain getter and multiple legitimate callers need to persist that exact setting, and no existing safe setter already represents the operation.

A setter may be worthwhile for thumbnail warmup if the alternative would be multiple controllers writing `thumbnail_background_warmup_enabled` directly.

Do not add a second getter merely for naming symmetry.

### 2.4 Fresh-install seeding

Identify the existing first-install settings initialization boundary.

Extend that existing workflow with a single capability-default seeder.

The seeder must support a definition-level `fresh_default_enabled` only when different from normal absent-setting behavior.

Initial policy:

- public thumbnail self-healing: fresh OFF, upgrade-compatible absent fallback ON,
- remote favicon discovery later: fresh OFF, upgrade-compatible absent fallback ON.

Do not run fresh seeding during upgrade.

### 2.5 Migrations

Do not add a generic capability table.

Only add a timestamped migration if a later new setting genuinely requires it.

If a migration seeds an `app_settings` value, preserve existing values using the repository's established idempotent insert semantics.

### 2.6 Settings inventory integration

Extend centralized Settings discovery so bridged master settings are searchable even when edited on a specialized page.

Initial expected entries:

- Gallery Trash master,
- Gallery Trash automatic purge,
- Gallery Trash retention,
- Gallery Trash purge batch,
- thumbnail background warmup,
- automatic updates,
- development diagnostics if not already fully represented.

Do not mark a setting centrally editable unless central save delegates through the same domain setter and preserves all side effects.

## Acceptance criteria

- No capability has two persisted Boolean masters for the same decision.
- Toggling Trash through policy uses Trash lifecycle semantics.
- Fresh-install defaults are applied only by first-install setup.
- Upgrade behavior remains backward compatible.
- Central Settings search can locate the newly registered operational settings and point to their specialized screens.
- Full audit passes.

## Checkpoint

Produce an affected-files ZIP before Stage 3.

---

# STAGE 3: Generalized route requirements and master-precedence contract

## Goal

Make feature enforcement systematic instead of relying on ad-hoc route-map exceptions and local overrides.

## Work

### 3.1 Replace single-owner route assumptions internally

Create a canonical route requirement representation supporting:

- single capability,
- `all_of`,
- `any_of`.

The central dispatcher should ask one canonical policy function whether a route is allowed.

### 3.2 Derive compatibility route maps

Where legacy code/tests still need `feature_flag_route_map()`, derive the compatible simple map from the canonical route definitions where possible.

Do not keep two separately maintained route inventories.

### 3.3 Route ownership metadata belongs with capability definitions

Move route ownership out of a manually duplicated central map where practical so adding a capability can declare its routes in the same registry entry.

For multi-capability routes, use a small centralized route-policy section rather than repeating special cases in dispatcher logic.

### 3.4 Public/Admin disabled responses

Standardize response policy:

- anonymous public route for a hidden subsystem: normally 404/not-found, no feature advertisement,
- Admin-only route: 403 or equivalent disabled-feature response with Admin > Features link,
- JSON/AJAX: stable structured envelope,
- include `X-Robots-Tag: noindex, nofollow` where appropriate,
- never reveal private implementation details.

### 3.5 Master precedence

Audit every effective presentation computation for globally controlled features.

Global master must be applied after local preferences.

At minimum audit:

- Smart Gallery Lightbox,
- Smart Gallery slideshow,
- Smart Gallery downloads,
- Smart Gallery voting,
- physical-gallery Lightbox,
- physical-gallery downloads,
- Picture Game,
- maps.

Do not create a trivial generic helper merely to replace `master && local` unless it prevents real duplicated mistakes. The important change is semantic consistency and tests.

### 3.6 Tests

Add a reusable contract test concept:

> If capability X is effectively OFF, every route exclusively owned by X is rejected by the dispatcher.

And:

> If capability X is effectively OFF, server-rendered public/Admin UI must not emit actionable controls whose only target is an X-owned route.

The second contract can use focused source/render tests rather than a full browser if sufficient.

## Acceptance criteria

- `gallery_map_data` no longer needs an isolated hard-coded exception outside the generalized route policy.
- Multi-capability Smart Gallery routes can be expressed declaratively later.
- Master OFF cannot be defeated by local presentation overrides.
- Route ownership has one authoritative source.
- Full audit passes.

## Checkpoint

Produce an affected-files ZIP before Stage 4.

---

# STAGE 4: Repair existing feature-switch contract gaps

## Goal

Before adding new switches, make current switches truthful.

A disabled feature must be hidden and route-guarded consistently.

## 4.1 Gallery downloads

Current issue:

- download routes are feature-guarded,
- the physical gallery hero can still render the download control while the feature is OFF.

Required change:

- hide public download controls whenever the effective public download capability is OFF,
- do not render dead forms/buttons that only fail after clicking.

### Refine capability scope

Separate conceptually:

- **Public gallery downloads**
- **Admin full archive/export**

The current `downloads` flag owns both public download routes and `download_all`, although `download_all` is Admin-only.

During this stage decide whether to:

A. rename/refine the existing capability while preserving the historical key as compatibility, or
B. keep `downloads` as the public capability and remove Admin-only `download_all` from its ownership.

Preferred outcome: visitor download policy must not unnecessarily disable an administrator's maintenance/export archive.

Preserve backward compatibility for stored `feature_flag.downloads.enabled`.

## 4.2 Smart Gallery local presentation precedence

Current issue:

- defaults correctly read global Lightbox/Downloads capability,
- persisted Smart Gallery presentation values can override those defaults afterward.

Required change:

- local Smart Gallery preferences remain stored,
- global capability masters are applied last,
- switching the master back ON restores the previous local preference automatically.

Add focused regression coverage.

## 4.3 Smart Gallery lightbox metadata route

Add Smart Gallery lightbox metadata to the Lightbox policy requirement.

Once Smart Galleries receives its own master in Stage 6, this route must become `all_of: smart_galleries + lightbox_modes`.

Until Stage 6, enforce Lightbox consistently.

## 4.4 Picture Manager selected-photo ZIP

Current issue:

- move/copy/create operations are owned by Picture Manager,
- selected-photo ZIP is not part of its route ownership.

Decide exact product semantics:

Preferred policy:

- selection-mode-specific operations disappear when Picture Manager is OFF,
- Admin-selected-photo export should not be conflated with public visitor gallery downloads,
- route ownership should reflect the actual Admin subsystem rather than public ZIP policy.

## 4.5 Development profiler ownership

Current issue:

- `dev_mode_enabled` exists and defaults OFF,
- some render-profiler/benchmark paths are enabled merely because an Admin is authenticated.

Required change:

- Development Diagnostics becomes the canonical master for diagnostic overlays/profilers/benchmark instrumentation that are described as opt-in diagnostics,
- no second Boolean is introduced.

## Acceptance criteria

- Existing feature switch UI never advertises an action that the switch has route-disabled.
- Smart Gallery local settings cannot defeat global OFF.
- Admin exports are no longer accidentally governed by visitor download policy unless explicitly intended.
- Development Diagnostics meaning matches actual instrumentation behavior.
- Full audit passes.

## Checkpoint

Produce an affected-files ZIP before Stage 5.

---

# STAGE 5: Admin > Features becomes a capability dashboard

## Goal

Upgrade the Admin UI to accurately communicate configured state, effective state, dependencies, side effects and specialized settings without turning it into a duplicate of every settings page.

## Work

### 5.1 Render configured state, not merely effective state

Checkbox checked state must represent the administrator's persisted preference.

If a dependency disables a capability, show something like:

- Configured: Enabled
- Effective: Disabled because Image Voting is disabled

Do not silently uncheck and persist dependent capabilities merely because their parent is OFF.

### 5.2 Add capability metadata badges

Useful badges may include:

- Public
- Admin only
- Writes files
- Background work
- Outbound network
- Privacy
- Diagnostic
- Destructive maintenance

Keep them compact. They are operational context, not decoration.

### 5.3 Add specialized-settings links

When a capability has subordinate settings, provide a link such as:

- Gallery Trash -> Trash settings,
- Development Diagnostics -> dashboard/diagnostics settings,
- Automatic Updates -> Updates,
- public search -> Maintenance/general setting where its subordinate switch lives,
- Image Voting -> gallery editor where per-gallery enablement is controlled.

Do not duplicate subordinate controls on the Features page.

### 5.4 Add active/subordinate state hints

Examples:

- Public Search: global capability Enabled, public search site setting Disabled.
- Telemetry: capability Enabled, collection Disabled.
- Viewer Accounts: capability Enabled, mode Closed/Invite/Open.
- Trash: Enabled, automatic purge Disabled.

This should be read-only contextual state unless the value is the capability master itself.

### 5.5 Safe save semantics

`save_feature_flags_from_post()` currently treats every absent checkbox as disabled and writes every feature.

Once bridged and dependency-aware capabilities exist, replace this with a registry-driven save that:

- only writes capabilities editable on this surface,
- uses each capability's canonical writer/adapter,
- preserves read-only/derived capabilities,
- does not write disabled-by-dependency state unless the administrator actually changed the checkbox,
- keeps compound-domain setter semantics,
- produces an audit log summary of changed capabilities, not just aggregate counts.

Consider an explicit submitted registry revision/token if needed to prevent a stale Admin form from unintentionally changing newly added capability keys after an update. If implemented, keep it simple and deterministic.

### 5.6 Logging

Admin log should record bounded capability transitions:

- key,
- old configured state,
- new configured state,
- effective state if materially different,
- source surface.

Do not log secrets or unrelated settings.

## Acceptance criteria

- Admin can tell configured vs effective state.
- A dependency OFF does not destroy the dependent preference.
- Bridged settings update through canonical workflows.
- UI indicates operationally meaningful side effects.
- Central Settings and Features point to each other without duplicate persistence.
- Full audit passes.

## Checkpoint

This finishes the fundamental platform phase. Produce an affected-files ZIP before any higher-level feature integration.

---

# STAGE 6: Smart Galleries master capability

## Goal

Add a complete reversible master switch for the Smart Galleries subsystem.

## Default

- existing installations: Enabled
- fresh installations: Enabled

## Data policy

Preserve all Smart Gallery definitions and related data when disabled:

- rules,
- names/slugs,
- enabled/published state,
- placement relationships,
- ordering,
- presentation JSON,
- rating-related state,
- any future Smart Gallery metadata.

## Routes/areas to inventory and gate

At minimum:

- Admin Smart Gallery management page,
- Admin create/edit/delete/preview actions,
- attachment/placement actions,
- public Smart Gallery route,
- Smart Gallery lightbox metadata,
- Smart Gallery download start/manifest/file routes,
- any Smart Gallery voting/rating endpoints that are not shared with physical galleries,
- dashboard/menu cards and links,
- Smart Gallery cards attached to physical galleries,
- sitemap/public discovery if Smart Galleries are included there,
- search conversion/results if Smart Galleries can appear independently.

Do not assume this list is complete. Stage 0 inventory should provide the authoritative route set.

## Dependencies

Examples:

- public Smart Gallery route -> Smart Galleries,
- Smart Gallery lightbox metadata -> Smart Galleries AND Lightbox,
- Smart Gallery downloads -> Smart Galleries AND Public Gallery Downloads,
- Smart Gallery voting presentation -> Smart Galleries AND Image Voting.

## Disabled behavior

Anonymous direct public URL:

- return non-advertising 404/not found.

Admin direct URL:

- show disabled-feature result with route back to Admin > Features.

Physical galleries:

- do not render Smart Gallery attachments while globally OFF.

## Acceptance criteria

- no Smart Gallery public/Admin UI remains actionable while OFF,
- direct routes are guarded,
- all Smart Gallery data survives OFF -> ON roundtrip unchanged,
- local Smart Gallery `enabled` state remains a subordinate preference and is not overwritten,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 7: Inline public administration capability

## Goal

Create a master switch controlling Admin mutation affordances rendered on otherwise public gallery pages.

## Name

Recommended: **Inline administration on public pages**

## Default

Enabled for existing and fresh installations to preserve current behavior.

## Scope

When OFF, authenticated Admins browsing public gallery pages should not see/use inline controls such as:

- edit gallery,
- add child gallery,
- delete/trash gallery,
- edit image,
- delete image,
- drag reorder images,
- drag reorder child galleries,
- save public-page ordering/date actions,
- other mutation controls injected into public cards/lightbox where their purpose is public-page Admin editing.

Normal dedicated Admin pages must remain fully available.

## Relationship to Picture Manager

Do not merge these two concepts accidentally.

Recommended conceptual separation:

### Inline Administration

- edit,
- add,
- delete/trash,
- reorder,
- direct metadata mutations from public page.

### Picture Manager

- enter selection mode,
- select images,
- move/copy,
- create destination gallery,
- selection-specific export/share operations.

Picture Manager may optionally depend on Inline Administration for UI coherence, but do not create a dependency unless it produces a clearly better user model.

If independent:

- Inline Administration OFF + Picture Manager ON may still allow selection/move/copy tools.

If dependent:

- preserve Picture Manager configured state while Inline Administration is OFF.

Decide and document this explicitly in permanent docs.

## Implementation rule

Replace repeated expressions equivalent to:

`current_user() && !admin_anonymous_preview_active()`

when they specifically mean "render public inline Admin mutation controls" with one canonical policy decision at an appropriate existing controller/view capability boundary.

Do not globally replace every such authentication expression because some Admin-only public-page behavior may not belong to this feature.

## Acceptance criteria

- anonymous visitor output is unchanged,
- Admin public gallery can be made visually non-editable without logging out,
- dedicated Admin editing remains available,
- anonymous-preview mode continues to override Admin mutation UI,
- no stale JavaScript attempts mutation routes when their controls are absent,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 8: Gallery Trash and Public Thumbnail Self-Healing

This stage integrates two existing operational masters into the new capability surface without duplicating their storage.

## 8A. Gallery Trash

### Canonical owner

Existing Trash domain service and `gallery_trash_enabled` setting.

### Default

Enabled.

### OFF semantics

- future user-facing gallery deletion follows the existing immediate-delete behavior,
- existing Trash entries remain stored,
- existing Trash entries remain recoverable/manageable according to the current Trash design,
- auto-purge preference remains stored,
- scheduled automatic purge remains inactive while the Trash master is OFF,
- turning Trash back ON restores the prior auto-purge preference but must retain the existing retention safety semantics.

### Important

The Features-page toggle must use the existing compound Trash settings workflow. Do not raw-write the Boolean.

### Subordinate settings

Remain on Trash settings page:

- automatic purge,
- retention days,
- purge batch.

Automatic purge remains default OFF.

## 8B. Public Thumbnail Self-Healing

### Canonical owner

Existing `thumbnail_background_warmup_enabled` state exposed through the thumbnail warmup service.

### Recommended user-facing name

**Public thumbnail self-healing**

### Description

Allow authorized public gallery requests to request guarded background repair of missing thumbnails.

### Defaults

- existing installation absent-setting behavior: Enabled,
- fresh installation: Disabled.

### OFF semantics

Public visitors must never trigger thumbnail generation/repair writes.

Still allowed:

- upload-time thumbnail generation,
- Admin/manual rebuild,
- scheduled maintenance rebuild,
- explicit server-side maintenance processes.

### Security/behavior verification

Audit the entire warmup token/request path so OFF is checked before:

- lock creation when possible,
- cooldown mutation,
- source-image processing,
- derivative writes,
- metadata repair.

UI/markup should not emit warmup request metadata when the capability is OFF.

## Acceptance criteria

- no duplicate Trash or warmup settings exist,
- Trash OFF is non-destructive to existing Trash contents,
- public warmup OFF causes zero public-triggered derivative writes,
- fresh-install default seeding works,
- existing installations preserve historical behavior unless Admin changes it,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 9: Multilingual authored content capability

## Goal

Separate translated UI language from translated gallery/photo authored content.

## Default

Enabled.

## Scope

Master governs:

- gallery translation editing fields,
- image translation editing fields,
- source-language selection if it exists solely for translated authored content,
- public authored-content translation resolution.

It must **not** govern:

- Admin UI translation,
- public UI translation catalog,
- viewer language preference,
- language selector used for interface language.

## Data policy

OFF preserves:

- `gallery_translations`,
- `image_translations`,
- source-language metadata,
- existing translated text.

Public rendering while OFF:

- use canonical/source title and description,
- do not load translation rows unnecessarily,
- do not delete or rewrite translation data.

Admin while OFF:

- hide authored-content translation editing controls,
- retain source title/description editing.

## Performance

When globally OFF, translation batch loaders should be skipped as early as possible so disabling the feature also removes its query/runtime overhead.

## Acceptance criteria

- UI language still works in EN/CS/DE/SV while authored-content localization is OFF,
- translated content returns unchanged after OFF -> ON,
- no translation rows are deleted,
- public rendering avoids unnecessary translation queries while OFF,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 10: Public Tag Browsing capability

## Goal

Separate tags as internal metadata from tags as public navigation/discovery.

## Default

Enabled.

## Keep available while OFF

- tag records,
- image-tag assignments,
- Admin tag management,
- tag editing,
- Smart Gallery rules using tags,
- internal search metadata,
- internal organization logic.

## Disable while OFF

- public `/tag/...` route,
- public tag landing pages,
- public tag-page pagination/layout,
- public clickable tag navigation.

Recommended presentation:

- tag labels may remain visible as plain non-clickable metadata,
- do not force-hide tags completely unless a separate Theme preference is later added.

## SEO

When OFF:

- tag routes return 404,
- remove tag URLs from sitemap/discovery,
- do not emit links to disabled tag routes,
- existing search-engine URLs should eventually age out naturally.

## Acceptance criteria

- internal tag functionality remains intact,
- public tag URLs are unavailable,
- no dead tag links are emitted,
- ON restores public navigation without rebuilding tag data,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 11: Optional Admin tools capability bundle

Implement these using the same foundation. Do not invent independent toggle mechanisms per tool.

## 11A. Duplicate Photo Detector

Default: Enabled.

OFF:

- hide detector entry points,
- block detector scan/job/review routes owned exclusively by the detector,
- preserve duplicate review/ignore ledger,
- preserve normal image delete functionality outside detector workflow.

Schema policy:

- reuse existing duplicate ledger/schema checks.

## 11B. Metadata Organizer

Default: Enabled.

OFF:

- hide Organizer tab,
- block preview/apply batch routes,
- preserve existing gallery/image metadata,
- ordinary manual organization/editing remains available.

Schema policy:

- continue using the existing structured capture-date readiness and mutation schema contracts.

## 11C. EXIF Gallery Date Suggestions

Default: Enabled.

OFF:

- disable automated suggestion/review workflow,
- keep normal manual gallery From/To date metadata editing,
- preserve existing gallery date values.

Do not call the capability simply "Gallery dates" because that would imply the ordinary metadata itself is disabled.

## 11D. Complete Gallery Report

Default: Enabled.

OFF:

- hide report generation UI,
- block report route,
- do not affect System Health, Logs, Integrity or other core diagnostics.

Keep the existing intentional `information_schema.TABLES` report exception isolated to the report subsystem.

## Admin navigation/settings

Each tool should have:

- registry group `admin_tools`,
- route ownership,
- navigation ownership where applicable,
- existing specialized route link,
- preserved data semantics.

## Acceptance criteria

- each tool OFF removes its own workflow only,
- unrelated Admin editing remains available,
- direct routes are rejected centrally,
- ledger/configuration data survives roundtrip,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 12: Diagnostics, network side effects and deployment policy

This stage contains lower-priority but operationally valuable controls.

## 12A. Development Diagnostics

Canonical owner: existing `dev_mode_enabled`.

Default: Disabled.

Scope should consistently include diagnostic functionality described as opt-in development instrumentation, including where applicable:

- public/admin render profiling,
- browser diagnostics overlay,
- Gallery benchmark UI,
- extra diagnostic payload/instrumentation.

Do not disable core logging, System Health or Integrity.

## 12B. Remote Favicon Discovery

Create one canonical setting/capability controlling **new outbound remote favicon discovery**.

Defaults:

- upgrade-compatible absent fallback: Enabled,
- fresh install: Disabled.

OFF:

- no outbound favicon-discovery HTTP requests,
- built-in known-site icons continue to work,
- already cached local favicons continue to render,
- Admin save must not block waiting on favicon network discovery.

Retain all existing SSRF/DNS rebinding/TLS/size/redirect protections when ON.

Register it with an `outbound network` badge and link to the appropriate Privacy/Network settings area.

## 12C. Built-in Update Installer

This is distinct from automatic update scheduling.

Potential capability:

**Built-in update installer**

Default: Enabled.

OFF:

- no CMS-initiated installation/reinstallation of application code,
- automatic updater becomes effectively inactive,
- read-only version status/check/integrity information may remain available,
- CI/CD-managed installations can disable self-modification.

Keep `application_autoupdate_enabled` as a subordinate preference, not a replacement for the installer capability.

Do not implement this if the current updater architecture cannot cleanly separate read-only checking from code mutation. If separation is unclear, document and defer instead of adding a misleading switch.

## 12D. Advanced Database Maintenance

Potential capability for mutation actions such as:

- ANALYZE,
- OPTIMIZE,
- safe cleanup,
- repair actions.

Recommended defaults:

- existing installations: Enabled,
- fresh general-purpose installations: optionally Disabled.

OFF must **not** hide read-only database status, schema health or integrity information.

Mutation schema policy remains authoritative regardless of capability state.

## Acceptance criteria

- diagnostics opt-in state matches actual instrumentation,
- network capability OFF guarantees no new favicon discovery requests,
- updater capability, if implemented, cleanly separates read-only status from mutation,
- database maintenance capability never weakens schema/integrity enforcement,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 13: Cross-feature health, dependency diagnostics and automated contracts

## Goal

Make the feature system self-auditing so later additions cannot silently recreate today's inconsistencies.

## Work

### 13.1 Capability health snapshot

Add a bounded Admin diagnostic representation containing, for each capability where useful:

- configured state,
- effective state,
- disabled dependency reason,
- storage source type,
- specialized settings link,
- schema capability state only when the capability is enabled and the existing lazy schema policy says it is appropriate,
- no secrets.

Do not probe every optional schema merely by opening Admin > Features. Preserve lazy schema-health behavior.

### 13.2 Static registry audits

Tests should verify:

- all core capability-key literals resolve,
- route requirements reference registered capabilities,
- dependencies reference registered capabilities,
- no dependency cycles,
- no route conflicts,
- no Admin navigation item references an unknown capability,
- no centralized Settings registry entry references an unknown feature key,
- no declared feature has an impossible storage adapter,
- fresh-install defaults are valid only for writable persisted capabilities.

### 13.3 Master-off route/UI contract

Create reusable tests for every capability that declares owned routes:

- OFF rejects owned route,
- OFF hides owned navigation where metadata exists,
- dependency OFF makes dependent routes unavailable without changing configured state.

### 13.4 Data preservation roundtrip contracts

For persistent optional subsystems, add focused tests proving OFF -> ON preserves state where practical:

- Smart Gallery definition/presentation,
- Trash entries/settings,
- authored translations,
- duplicate ledger,
- tags,
- Viewer Accounts if not already covered.

### 13.5 Query-budget checks

For expensive optional features, OFF should avoid unnecessary work:

- multilingual translation loaders,
- Smart Gallery relationship/rule work,
- telemetry maintenance/reporting,
- diagnostic profilers,
- remote favicon discovery,
- public thumbnail warmup.

Reuse existing query/profiler testing infrastructure where appropriate.

## Acceptance criteria

- adding a new capability incorrectly is likely to fail a focused test before runtime,
- disabled features are both route-safe and UI-consistent,
- schema-policy laziness is preserved,
- no secret/private path data appears in capability diagnostics,
- Full audit passes.

## Checkpoint

Affected-files ZIP.

---

# STAGE 14: Permanent documentation, cleanup and release-readiness integration

## Goal

Remove temporary scaffolding and make the architecture maintainable for future agents/developers.

## Work

1. Update `AGENTS.md` with the permanent "add/change a capability" workflow.
2. Update `ARCHITECTURE.md` with:
   - capability registry architecture,
   - configured vs effective state,
   - adapter storage model,
   - route requirements,
   - master precedence,
   - non-destructive disable semantics.
3. Update `CODEMAP.md` with exact service/controller/test ownership.
4. Update `DATABASE.md` only for actual migrations/settings persistence changes.
5. Update centralized Settings inventory documentation.
6. Update `TESTING.md` with new focused contracts while retaining central audit as authoritative orchestration.
7. Update Admin manual/README where user-facing behavior matters.
8. Remove this TEMP roadmap from the release/deployment tree.
9. Confirm no obsolete compatibility helper can be removed unless all callers and tests prove it safe.
10. Do not rename historical setting keys merely for aesthetic consistency.
11. Run full audit.
12. If preparing an actual release, follow `RELEASE.md` and use the release audit profile rather than manually stacking quick/full/release audits.

## Acceptance criteria

- temporary roadmap removed before release,
- permanent docs describe the final architecture rather than historical implementation phases,
- no dead duplicate setting APIs remain,
- compatibility wrappers have explicit purpose and tests,
- central audit passes.

---

# 8. Recommended final capability catalog

The exact final list should be confirmed during implementation inventory, but this is the target product model.

## Gallery display and visitor features

| Capability | Default | Notes |
| --- | ---: | --- |
| Public live search | Enabled | Existing feature master; subordinate site setting may remain OFF |
| Lightbox browsing modes | Enabled | Existing broad master; do not split every mode into separate feature |
| Image voting | Enabled | Existing |
| Picture game | Enabled | Existing; depends on Image Voting |
| Public gallery downloads | Enabled | Refine existing Downloads semantics so Admin export is independent |
| EXIF GPS gallery maps | Enabled | Existing |
| Flight route maps | Enabled | Existing |
| Public tag browsing | Enabled | New; tags remain internal metadata when OFF |
| Smart Galleries | Enabled | New complete subsystem master |

## Admin controls on public pages

| Capability | Default | Notes |
| --- | ---: | --- |
| Inline administration on public pages | Enabled | New |
| Picture Manager | Enabled | Existing; clarify selection ZIP ownership |

## Accounts and personalized features

| Capability | Default | Notes |
| --- | ---: | --- |
| Viewer Accounts and Collections | Disabled | Existing deliberate default |

## Content and metadata

| Capability | Default | Notes |
| --- | ---: | --- |
| Multilingual gallery/photo content | Enabled | New; independent from UI language |

## AI, uploads and integrations

| Capability | Default | Notes |
| --- | ---: | --- |
| OpenAI text assistance | Enabled | Existing |
| Local AI image metadata | Enabled | Existing |
| API uploader | Enabled | Existing |
| Mobile WebDAV uploads | Enabled | Existing |
| Gallery migration transfer | Enabled | Existing |
| SimBrief integration | Enabled | Existing |

## Admin and maintenance tools

| Capability | Default | Notes |
| --- | ---: | --- |
| Media renamer | Enabled | Existing |
| Duplicate Photo Detector | Enabled | New |
| Metadata Organizer | Enabled | New |
| EXIF Gallery Date Suggestions | Enabled | New |
| Complete Gallery Report | Enabled | New |
| Navigation data maintenance | Enabled | Existing |
| Anonymous telemetry capability | Enabled | Existing; collection may still be OFF |
| Admin Test Runs | Disabled | Existing deliberate default |

## Existing canonical operational masters surfaced through the policy dashboard

These must **not** gain duplicate feature-flag storage.

| Operational master | Upgrade default | Fresh default | Canonical owner |
| --- | ---: | ---: | --- |
| Gallery Trash | Enabled | Enabled | Trash domain settings |
| Trash automatic purge | Disabled | Disabled | Trash domain settings, subordinate only |
| Public Thumbnail Self-Healing | Enabled | Disabled | Thumbnail warmup setting/service |
| Development Diagnostics | Disabled | Disabled | `dev_mode_enabled` |
| Automatic Stable Updates | Enabled | Enabled | updater settings, subordinate if installer capability added |
| Browser Upload | Enabled | Enabled | existing browser upload settings |
| Scheduled Site Maintenance | Enabled/current behavior | Enabled/current behavior | existing maintenance setting |

## Later/optional controls

| Capability | Upgrade default | Fresh default | Notes |
| --- | ---: | ---: | --- |
| Remote favicon discovery | Enabled | Disabled | Outbound network side effect |
| Built-in update installer | Enabled | Enabled | Deployment policy, distinct from autoupdate |
| Advanced database maintenance | Enabled | Consider Disabled | Mutation tools only, not health/status |

---

# 9. Explicitly out of scope for feature disabling

Do not implement global feature switches that weaken or remove enforcement for:

- gallery visibility/private/password access,
- share token authorization,
- Admin authentication,
- Viewer/Admin identity separation,
- CSRF,
- rate limits required for security boundaries,
- NSFW access protection,
- source-media authorization,
- mutation schema policy,
- presentation schema safety policy,
- database migrations,
- integrity checking infrastructure,
- operational logs as a whole.

Do not turn ordinary presentation settings into feature flags merely because they are Boolean.

Examples that should remain settings:

- pagination,
- count badges,
- GPS marker appearance,
- gallery grid dimensions,
- Theme choices,
- individual Lightbox mode selection,
- local gallery/Smart Gallery presentation overrides,
- public viewer language-selector appearance,
- retention values and batch sizes.

---

# 10. Detailed behavior contracts for every future capability

Before any new capability is considered complete, answer all of these questions explicitly.

## State

- What is the canonical key?
- Where is the configured state persisted?
- Is it native feature storage or an existing domain setting?
- What is the upgrade fallback?
- What is the fresh-install default?
- Does the capability have dependencies?

## Data

- What persistent data exists?
- What happens to that data when OFF?
- Is OFF reversible with zero data loss?
- Is any cleanup separately available?

## Routes

- Which routes are exclusively owned?
- Which routes require multiple capabilities?
- What does anonymous direct access return while OFF?
- What does Admin direct access return while OFF?
- What does JSON/AJAX return while OFF?

## UI

- Which public controls disappear?
- Which Admin menu/cards/tabs disappear?
- Which subordinate settings remain visible/read-only?
- Can any dead action link still be emitted?

## Background work

- Does OFF stop cron/maintenance jobs specific to the feature?
- Does OFF stop public-request-triggered work?
- Does OFF stop outbound network calls?
- Does OFF prevent write-side maintenance while preserving read-only status?

## Dependencies

- Can local/gallery settings override the master? They must not.
- Does turning a parent OFF preserve child configured state? It should.
- Does turning the parent back ON restore the child automatically? It should, assuming the child remained configured ON.

## Schema

- Which existing schema-policy capability owns database readiness?
- Can schema checks be skipped while the feature is OFF?
- Does any mutation boundary remain fail-closed on unknown schema?

## Tests

- default state,
- configured state,
- effective dependency state,
- route guard,
- UI hiding,
- direct-request rejection,
- data preservation OFF -> ON,
- no unnecessary work while OFF,
- translation keys,
- Admin settings registry/discovery,
- audit logging.

If these questions cannot be answered, the switch is not ready to ship.

---

# 11. Known implementation traps to avoid

## 11.1 Saving all checkboxes from one stale form

The current "anything absent from enabled_features[] becomes OFF" model becomes dangerous as the registry expands and deployments can update underneath an open Admin tab.

The new save path should write only recognized editable capabilities from the submitted registry generation/context and should not accidentally disable a newly introduced capability merely because an older browser form did not know it existed.

## 11.2 Local defaults defeating master OFF

Never compute a feature-disabled default and then merge local persisted values over it.

Apply global master state last.

## 11.3 Raw `set_app_setting()` writes from Admin > Features

Do not bypass domain setters.

## 11.4 Hiding UI without guarding routes

UI hiding is not enforcement.

The dispatcher remains authoritative.

## 11.5 Guarding routes without hiding UI

A visible button that always returns "feature disabled" is also a broken feature contract.

Both layers are required.

## 11.6 Turning feature flags into authorization

Feature state does not replace access control. A route that needs Admin authorization still needs Admin authorization when the feature is ON.

## 11.7 Querying optional schemas for disabled capabilities

Feature OFF should short-circuit optional schema work wherever safe, consistent with the existing lazy presentation health design.

## 11.8 Deleting data on disable

Never couple checkbox OFF to cleanup/purge.

## 11.9 Adding per-feature helper sprawl

Prefer registry/policy lookup.

Keep a feature-specific helper only when it expresses domain semantics beyond a one-line capability check or is an existing compatibility API.

## 11.10 Creating a class hierarchy foreign to the project

Use the project's established namespaced service/module design. The desired abstraction is centralized behavior, not object orientation for its own sake.

---

# 12. Suggested permanent "Add a capability" recipe after this roadmap is complete

This is the workflow that should eventually be condensed into `AGENTS.md`.

1. Identify whether the requested switch is truly a subsystem capability or merely a normal setting.
2. Find the existing canonical getter/setter and persistence key before creating anything.
3. Register the capability once in the canonical registry.
4. Bind it to native feature storage or the existing domain adapter.
5. Declare dependencies.
6. Declare owned routes and multi-capability route requirements.
7. Declare Admin navigation/settings links and behavior badges.
8. Define upgrade and fresh-install defaults.
9. Define non-destructive OFF data semantics.
10. Apply global master state after subordinate/local preferences.
11. Remove feature-specific public/Admin UI when OFF.
12. Keep dispatcher route enforcement authoritative.
13. Reuse existing schema policy and short-circuit it while disabled where safe.
14. Add configured/effective/default/route/UI/data-preservation tests.
15. Add translations in EN/CS/DE/SV.
16. Update central Settings discovery when a global setting is involved.
17. Run central audit.
18. Produce affected-files ZIP.

---

# 13. Completion definition for the whole project

The entire roadmap is complete only when all of the following are true:

1. There is one canonical optional-capability registry.
2. There is no second independent feature framework.
3. Existing feature flags remain backward compatible.
4. Existing domain settings are bridged, not duplicated.
5. Configured and effective states are distinct and visible where useful.
6. Dependency state does not destroy administrator preference.
7. Global OFF always overrides local ON.
8. Every optional route has explicit capability ownership.
9. Multi-capability routes are expressed without dispatcher special-case sprawl.
10. Disabled public features do not advertise themselves through dead controls or verbose anonymous errors.
11. Disabled Admin tools disappear from navigation and reject direct execution.
12. Disabling a feature preserves persistent data unless a separate explicit cleanup action is invoked.
13. Security/integrity enforcement is never made optional through the feature system.
14. Optional schema checks remain lazy.
15. Fresh-install conservative defaults do not silently change existing upgraded installations.
16. Central Settings discovery identifies global operational masters and links to their canonical editors.
17. Development Diagnostics actually controls the diagnostics described by its UI.
18. Public thumbnail self-healing can be fully disabled before any public-triggered write occurs.
19. Smart Galleries have a complete master switch.
20. Inline public Admin controls have a complete master switch.
21. Multilingual authored content is independent from UI-language translation.
22. Public tag browsing is independent from tags as internal metadata.
23. Duplicate Detector, Metadata Organizer, date suggestions and Complete Report can be hidden cleanly.
24. Route/UI/data-preservation contracts are regression-tested.
25. All permanent documentation reflects final behavior.
26. This TEMP roadmap has been removed before release.

