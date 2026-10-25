# Schema policy for application changes

Read this reference before changing schema observation, security/authentication,
ingestion, destructive operations or optional presentation. It preserves the
behavior formerly specified in root AGENTS; observation belongs to
`app/services/schema_inspection.php`, policy to the named domain services.
See [DATABASE.md](../DATABASE.md) and [TESTING.md](../TESTING.md) for permanent
architecture and regression ownership. Root authorization and CI-first rules
apply.

## Schema Inspection Reliability Guidelines
Schema capability checks use explicit `available`, `missing`, and `unknown`
states at every security-sensitive, mutation-sensitive, and optional
presentation/reporting boundary. `app/services/schema_inspection.php` owns
observation and request-local caching; feature policy remains in the
security/access services, `app/services/mutation_schema_policy.php`, or
`app/services/presentation_schema_policy.php` according to risk. Do not
reintroduce ambiguous boolean existence checks at a boundary that must
distinguish confirmed absence from inspection failure.

NSFW Guard is the first converted security-sensitive caller. Preserve its
three-state policy: complete schema uses normal protection, confirmed
pre-feature schema uses the documented compatibility path, and unknown
inspection state fails public NSFW-sensitive requests with the centralized 503
boundary. Explicit NSFW setting changes require verified schema. Keep System
Health diagnostics bounded and never expose raw database exceptions.

The NSFW service-unavailable boundary must remain route-aware: translated
minimal HTML for human pages, JSON for structured endpoints, and plain text for
media or crawler responses. Preserve HTTP 503, private no-store caching, retry
and noindex headers, request-ID correlation, and the stable bounded security log
event. Logging failure must never interfere with the protective public response.

Admin System Health and Runtime Diagnostics must consume the shared bounded NSFW
health model. Missing and unknown states require visible action badges; unknown
includes only safe suggested checks and request correlation. Keep affected
objects restricted to validated table/column identifiers. The generic disabled
state applies only when a feature has a real configuration switch; NSFW Guard
currently has none.

The security pilot established the result shape and naming, public/Admin
presentation,
bounded logging, and the request-local query budget. The completed conversion
keeps
those structured result names stable unless a concrete future requirement proves
a
deficiency. Policy code should prefer the explicit state predicates over
repeated
raw array-key checks. Migration execution is a cache invalidation
boundary: successful schema DDL, duplicate-DDL replay, migration-ledger
bootstrap, and successful repair callbacks must clear request-local schema
inspection results before same-process validation. Data-only statements should
not invalidate schema capability state.

Migration callbacks must also tolerate an older runtime owner already included
before updater activation. Replacing its bytes and using `require_once` cannot
provide newly added functions in that PHP process. Load migration-owned bootstrap
files with independent symbols and use the explicit PDO; do not couple a callback
to new runtime adapters or silently swallow undefined-function failures. Cover
actual callback execution after an old-model/file-swap vector, populated branches,
replay and SQL-error rollback in hosted integration, alongside fresh installation.

## Gallery access and administrator authentication

The converted gallery access/visibility/share-token and administrator
authentication storage preserves these policies:

- gallery access is legacy only when **all** core access columns are confirmed
  absent; aggregate `missing` with a partially applied migration must fail
  closed;
- visibility may map canonical `unpublished` to historical `draft` only after a
  successful column-definition inspection proves the old vocabulary;
- protected gallery/media/thumb/metadata/download routes must pass the
  dispatcher
  visibility, access, and NSFW preflight before handler output;
- confirmed missing remember-token storage may degrade only to ordinary
  PHP-session
  login; unknown storage must not issue/use persistent tokens;
- confirmed missing `users.email` preserves username login, but password reset
  requires verified `users.email` plus `password_reset_tokens`;
- Google OAuth configuration readiness and `user_google_accounts` schema
  readiness
  are separate decisions; unknown link storage must not be described as merely
  unconfigured;
- share-token generation/use requires verified storage, while revocation may
  always
  clear a verified validating hash even if optional encrypted display-token
  storage
  is missing or unknown;
- security schema logs and System Health may contain bounded
  capability/operation
  identifiers, validated table/column names, safe categories, and request IDs
  only.
  Never include passwords, hashes that act as credentials, share/reset tokens,
  remember cookies, CSRF values, OAuth secrets, DSNs, raw SQL, raw database
  exception messages, or filesystem paths.

`admin_security_schema_health_statuses()` is the shared Admin registration for
converted security/authentication capabilities. Runtime Diagnostics and
dashboard
System Health must consume the same bounded models. Add focused
available/missing/
unknown tests for any policy change and require a green hosted `full` audit for
the exact prepared candidate.

## Destructive and ingestion workflows

Destructive and ingestion workflows use
`app/services/mutation_schema_policy.php`. Preserve these mutation rules:

- preflight the complete required schema **before the first irreversible target
  mutation**, including filesystem moves/copies/deletes, credential creation,
  database row deletion, migration target writes, derivative replacement, repair
  DDL, or replacement of active application files;
- `available` preserves the existing operation, `missing` may use only an
  explicitly documented compatibility path or a migration/repair bootstrap, and
  `unknown` must refuse or pause the mutation;
- never use `db_table_exists()` or `db_column_exists()` as authorization to skip
  destructive cleanup, ingestion registration, token handling, or maintenance.
  Those legacy boolean helpers cannot distinguish confirmed absence from an
  inspection failure;
- optional cleanup/metadata columns may be skipped only after a three-state
  probe
  confirms they are missing. Unknown optional dependencies must stop the
  operation
  before a partial mutation can be committed;
- token revocation is intentionally narrower than token issuance/authentication.
  Revocation should remain possible whenever the identity and revocation columns
  required to disable the credential are verified, even if unrelated optional or
  authentication columns are missing;
- browser/classic upload, WebDAV PUT, and gallery migration must establish both
  core ingestion readiness and thumbnail-metadata write readiness before source
  or
  derivative files are committed to the target gallery. A prepared ZIP, WebDAV
  request body, or resumable migration job should remain recoverable on refusal;
- thumbnail reads may retain documented legacy file-only behavior, but
  generation,
  metadata repair, variant deletion, and bulk thumbnail deletion must stop on an
  unknown metadata schema before derivative files are changed;
- database cleanup/repair and updater activation may proceed through a confirmed
  missing `schema_migrations` state only where the workflow itself can safely
  bootstrap or apply migrations. Unknown metadata state still blocks the
  mutation;
- update source validation must require both `schema_inspection.php` and
  `mutation_schema_policy.php` before active application files can be replaced;
- mutation refusal logs use `database.mutation_schema_refused` with only bounded
  capability, state, operation, affected database-object identifiers, and
  ordinary
  safe request correlation. Never log raw SQL, PDO messages, database
  credentials,
  API keys, WebDAV secrets, upload paths, migration source paths, or update
  staging
  paths;
- `admin_mutation_schema_health_statuses()` is the shared System Health and
  Runtime
  Diagnostics registration for the ten converted mutation capability groups.
  Keep
  those surfaces synchronized with the policy service and preserve visible
  Action
  state for `missing` or `unknown` results.

`tests/mutation_schema_policy_test.php` is the focused mutation contract. Extend
it
whenever a converted mutation gains a new schema dependency or compatibility
path.
Keep optional presentation/reporting policy separate from destructive mutation
policy.

## Optional presentation and reporting

Optional presentation/reporting callers use
`app/services/presentation_schema_policy.php`. Preserve these final rules:

- read-only optional presentation may be omitted for confirmed `missing` schema
  and may also be omitted for `unknown` only when omission cannot expose
  protected data, weaken access policy, or authorize a persistent mutation;
  `unknown` must emit bounded diagnostics and appear in System Health;
- optional-feature writes must never use `unknown` as proof of absence. Use
  `presentation_schema_assert_write_available()` when the write requires the
  capability, or `presentation_schema_assert_known()` only for an audited
  compatibility path where confirmed `missing` safely means no optional
  persistence;
- GPS/EXIF maps, flight maps/navigation data, voting, Picture Game, lightbox
  overrides, OpenAI settings, AI image metadata, SimBrief route-map persistence,
  navigation account/cache storage, telemetry reporting, and the complete Admin
  report each have named capability status functions; do not collapse them into
  a generic all-features-ready boolean;
- Picture Game GET state records displayed pairs, so its pair-selection route is
  a write boundary even though it renders a page. Voting, Picture Game results,
  telemetry settings/maintenance, navigation account persistence, AI queue
  changes, and similar state changes require conclusive schema policy;
- gallery creation/import must verify voting storage before enabling voting, and
  must not silently discard an explicit or metadata-organizer-inherited lightbox
  override when its schema state is `unknown`;
- metadata-organizer capture-date readiness uses structured inspection of
  `images.exif_taken_at`; do not restore the legacy boolean column probe;
- navigation token disconnect/revocation may use a narrower verified
  account-deletion capability, while account creation/token persistence requires
  the complete account schema;
- SimBrief draft generation remains independent from optional flight-route
  persistence. Missing/unknown route-map schema may omit only the map write, not
  the remote draft itself;
- Admin gallery-report schema discovery uses structured named-object inspection
  for known report dependencies. Its direct `information_schema.TABLES` query is
  the intentional exception because the report must dynamically enumerate every
  base table rather than ask whether one known object exists; keep its output
  generic on query failure;
- System Health presentation definitions must stay lazy. Evaluate a real feature
  flag first and report `disabled` without metadata probes when the feature is
  off. Enabled capability resolvers share the request-local schema cache;
- `database.presentation_schema_degraded` may contain only bounded
  capability/state/operation and validated affected-object names. Never include
  SQL, raw database exceptions, DSNs, passwords, OAuth/API tokens, cookies, CSRF
  values, reset/share tokens, or private paths;
- retain the final regression contract in
  `tests/presentation_schema_policy_test.php`, including the ten-probe first-use
  voting budget, zero-query lazy registry construction, exact capability
  registry, source-boundary audits, and redaction checks.

The schema-inspection reliability roadmap is complete. Permanent behavior
belongs in this policy reference, scoped `AGENTS.md`, `ARCHITECTURE.md`,
`CODEMAP.md`, `DATABASE.md`, `README.md`, `TESTING.md`, release notes, and the
administrator manual. Do not recreate a temporary phased roadmap in release
packages.
