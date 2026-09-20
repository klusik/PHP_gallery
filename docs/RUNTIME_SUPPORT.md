<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/RUNTIME_SUPPORT.md
Module Type: Operator Guide
Purpose: Record reviewed PHP lifecycle policy and bounded disposable session evidence.
Responsibilities:
  - Distinguish compatibility, recommended deployments and measured test limitations.
Author: Rudolf Klusal
-->

# PHP runtime support

PHP 8.1 remains the minimum syntax/runtime compatibility target. This does not
mean PHP 8.1 receives upstream security fixes. Existing installations are not
locked out, upgraded, or reconfigured by the support policy.

Use the latest patch release of a maintained PHP branch for deployment. The
reviewed deployment minimum is PHP 8.3; prefer PHP 8.5 for a new deployment after
checking the application and image extensions in staging. PHP 8.4 is also a
maintained deployment target. PHP 8.2 has limited remaining upstream support and
is below the project's recommended deployment minimum.

## Reviewed upstream schedule

Verified against the official [PHP supported versions table](https://www.php.net/supported-versions.php)
and [unsupported branches table](https://www.php.net/eol.php) on **2026-09-20**.
End dates are inclusive UTC calendar dates.

| PHP branch | Active support through | Security support through | Deployment guidance at review |
| --- | --- | --- | --- |
| 8.1 | Already ended | 2025-12-31 | Compatibility only; plan a maintained runtime upgrade |
| 8.2 | 2024-12-31 | 2026-12-31 | Below recommended deployment minimum |
| 8.3 | 2025-12-31 | 2027-12-31 | Maintained deployment minimum |
| 8.4 | 2026-12-31 | 2028-12-31 | Maintained deployment target |
| 8.5 | 2027-12-31 | 2029-12-31 | Preferred deployment target |

Review these dates when PHP announces lifecycle changes or a new branch, and
before the deployment minimum reaches its security-support deadline. Vendor
backports and exact patch-level vulnerability status are outside this branch
schedule; ask the hosting provider for those separately. A passing CI job does
not establish the patch level or extensions of a production web worker.

## Setup and runtime diagnostics

Check both the CLI used for maintenance and the web SAPI used by the site. They
can run different versions or load different extensions. Preserve the current
configuration, database, galleries and protected runtime storage while testing a
host upgrade. Exercise login, authenticated media, classic/browser upload,
thumbnail generation and any enabled image conversion in a disposable staging
installation before switching the host runtime.

The baseline remains PHP with PDO MySQL and the existing feature dependencies.
The CI workflow explicitly provisions `pdo_mysql`, `pdo_sqlite` (tests only),
`curl`, `gd`, `zip`, `dom` and `mbstring`; Unicode jobs also provision `intl`.
Imagick/HEIC/DNG capabilities still belong to Runtime Diagnostics' existing image
checks. Their availability is not implied by the PHP version.

`app/policy_constants.php` owns all six immutable `Gallery\Core\RUNTIME_SUPPORT_*`
definitions, including the reviewed inclusive UTC schedule. The runtime service
explicitly loads this dependency-free owner and imports only these definitions;
there is no bootstrap, configuration or database dependency and no service-local
constant alias. `app/services/runtime_support.php` remains the offline policy
evaluation and localized presentation-data owner.
`runtime_support_status()` returns the bounded advisory policy.
`runtime_support_health_status()` wraps that same policy with centrally localized
plain-text labels and seven copy-report lines for the existing Admin dashboard
and Runtime Diagnostics. It has no SQL, network lookup, feature
flag, persistence, raw `phpinfo()` output, paths or request/cookie inputs. Its
`compatible` field describes the minimum version condition; it is not a test-run
attestation. Its `deployment_recommended` field also requires a reviewed branch
whose upstream support has not expired.

The lifecycle states are `active`, `security_only`, `end_of_life`, and `unknown`.
Unlisted branches and evaluation before the review date are `unknown`; future
versions never acquire a green recommendation by numerical comparison alone.
Unknown, obsolete and below-policy branches set `action_required`. The model
includes only the branch, fixed policy values, reviewed dates, one official
reference URL, booleans, and at most two bounded suggested-check identifiers.
An expired minimum produces an advisory; it never changes application access.

## CI coverage and evidence

The existing `.github/workflows/gallery-workflows.yml` keeps central audit
orchestration and defines four intentional environments:

| Job | PHP | Database | Title normalization | Verification owner |
| --- | --- | --- | --- | --- |
| Maintained minimum | 8.3 | Disposable MySQL 8.4 | `intl` + `mbstring`: NFKC/lowercase | Existing workflow provisioner, central full audit |
| Newer maintained branch | 8.5 | Disposable MariaDB 11.4 | `intl` + `mbstring`: NFKC/lowercase | Existing workflow provisioner, central full audit |
| Compatibility floor | 8.1 | No external database | `intl` + `mbstring`: NFKC/lowercase | Central full source audit |
| Optional-extension fallback | 8.5 | No external database | `intl` disabled, `mbstring` present: ASCII only | Central full source audit |

This is not the Cartesian product of every PHP/database/extension combination.
In particular PHP 8.4 is not a separate CI job, and the source-only jobs do not
claim real-database/browser workflow coverage. The existing database jobs retain
mandatory disposable workflows and their no-skip check. CI uses
`GALLERY_SESSION_REQUIRED=1` so the lightweight session fixture cannot silently skip.
The actual-route follow-up below additionally needs the disposable database job;
source-only jobs do not claim that coverage.

`runtime_normalization_environment_test.php` asserts that the actual installed
capabilities match `GALLERY_RUNTIME_NORMALIZATION=unicode|ascii`; a mislabeled
environment fails. It executes ASCII, fullwidth, combining-accent and ligature
cases through the actual normalization service. Unsuppressed PHP warnings and
deprecations in this smoke test become exceptions and fail the test. This strict
scope is not a promise that the historical central runner rejects every warning
in every other test. Existing complete title-service contracts remain part of
the central regression suite. The fallback retains the documented conservative
ASCII semantics from [Title completion](TITLE_COMPLETION.md).

Local development evidence on 2026-09-20: PHP **8.3.30**, lifecycle boundary test
passed; installed Unicode normalization passed; `php -n` normalization with
neither optional extension passed. `runtime_support_surfaces_test.php` passed
five lifecycle cases across both actual rendered surfaces (ten primary renders),
two escaping cases, shared localized report assertions and MVC/loader/badge
wiring assertions. It also renders all five states on both surfaces in each of
the four maintained JSON languages (40 further renders), checks all 14 runtime
keys and their placeholders, and compares the English/Czech PHP fallbacks.
It checks expired 8.1, below-policy 8.2, maintained 8.3,
active 8.4 and unreviewed 8.6 without changing the installed runtime or database.
No hosted matrix case has been executed as part of this change. PHP 8.1/8.5
hosted results, browser visual review and the parent's central full audit remain
separate evidence to collect. Workflow configuration alone is not evidence that
these environments pass.

## Session contention measurement

`tests/session_contention_test.php` owns a separate disposable fixture in
`tests/support/session_contention.php`, `tests/support/session_contention_runtime.php` and
`tests/fixtures/session_contention_router.php`. It does not edit or require the
existing `gallery_workflow*` fixture and never reads the site's configuration,
connects to its database, or copies its sessions. It needs CLI PHP, cURL,
`pdo_sqlite`, `proc_open`, and loopback listeners. The central PHP regression
discovery runs the test with its registered 90-second timeout; missing prerequisites are explicit SKIP locally and
BLOCKED when `GALLERY_SESSION_REQUIRED=1`.

Three independent PHP HTTP workers share one newly owned temporary files-session
directory. One request enters a bounded slow-work barrier. Two authenticated
suggestion requests then run concurrently: one with the holder's cookie and one
with a different cookie. Three repetitions compare holding the session against
closing only the synthetic holder's session before slow work. The minimum overlap
window is 900 ms **after both reader-start barriers are observed**. Holder and
reader readiness are each bounded to five seconds; the holder self-releases
after fifteen seconds, leaving room for both readiness phases and observation
if its parent disappears. HTTP transport is bounded to twenty seconds and the
registered whole-test timeout remains ninety seconds. Separate workers avoid
mistaking the PHP development server's single-worker queue for a session lock.

The fixture runs the real `cms_start_session()`, `current_user()`, title
controller, title service and model SQL against per-request SQLite sample rows.
It captures the existing `session_start_begin` and `session_start_end` marks.
HTTP first-byte and total times are reported separately. `session_start_ms`
includes lock acquisition, session storage I/O and decode time; PHP does not
expose pure lock-wait time. The barrier additionally proves whether each reader
finishes before the holder is released, without imposing a production timing SLA.

Measured on 2026-09-20, PHP 8.3.30 on Windows, files handler. Two original development
runs passed, each with three samples per scenario. Across both runs this is
12 contention comparisons: six retained-lock and six synthetic early-close
cases, containing 24 measured suggestion responses and 12 holder responses.
The final run's medians, rounded to three decimals, were:

| Holder behavior | Reader | HTTP total ms | First byte ms | Session start ms | Completed before holder release |
| --- | --- | ---: | ---: | ---: | --- |
| Session retained | Same cookie | 926.246 | 925.867 | 920.663 | No, 0/3 |
| Session retained | Independent cookie | 5.555 | 5.075 | 0.245 | Yes, 3/3 |
| Synthetic early close | Same cookie | 5.542 | 5.155 | 0.254 | Yes, 3/3 |
| Synthetic early close | Independent cookie | 5.229 | 4.794 | 0.248 | Yes, 3/3 |

Both runs also refused the anonymous suggestion with 401 (two denial checks),
issued two isolated fixture sessions each (four issuances), and validated each
session's CSRF/preference state afterward (four persisted-state checks).
Authenticated results contained the expected sample in every comparison.
All fixture servers, files and sessions were removed after the run. Console
measurements contain only bounded scenario labels, timings, statuses and handler
names; they exclude cookie values, owner tokens, credentials, paths, raw HTTP
bodies and database errors. A killed parent can leave its owned temporary tree;
verify the exact marker and worker identity before manual cleanup.

This proves contention for the controlled files handler workload. Session issuance
is synthetic, not the real login form or remember-cookie restoration. It does
not measure a production upload, production database, other session handlers,
logout races, all translation/flash paths or mutation-lock safety.

The parent's subsequent quick audit reported a held-comparison failure, but the
original fixture printed only the failing stage. One diagnostic rerun passed;
that does not identify the historical failing assertion. The fixture now prints
only its own safe, static assertion descriptions, never arbitrary exceptions.
It also closes a readiness gap: both readers must acknowledge the real
`session_start_begin` boundary before the 900 ms observation starts. Starting the
clock merely when cURL handles were queued could confuse slow worker startup
with lock waiting. A retained-lock sample deliberately delays its reader by
1,150 ms, longer than the observation window. Connect readiness now uses the
same five-second budget as worker readiness; holder/transport ceilings cover
both readiness phases. The observation and completion assertions are not waived.

Two barrier-aware development runs passed: 12 further comparisons, 24 suggestion
responses, 12 holder responses, four fixture session issuances, two anonymous
401 checks and four persisted CSRF/preference checks. Both delayed samples
observed reader readiness before timing the full overlap (1,178.314 and
1,180.372 ms readiness; 910.812 and 909.928 ms subsequent overlap). Each retained
reader still waited for release; each independent or synthetic early-close
reader completed before release. Both runs removed their owned workers and
temporary stores. These are focused reruns, not a central-audit pass or proof
that every resource-saturated CI host meets the bounded readiness deadline.

### Actual application-route follow-up

`tests/session_route_contention_test.php` extends the evidence to the unchanged
Admin application lifecycle. Its new support files are
`tests/support/session_contention_application.php`,
`tests/support/session_contention_application_router.php`, and
`tests/support/session_contention_database.php`. It reuses the existing workflow
allocator to create a fresh application clone and migrated MySQL database, without
editing any `gallery_workflow*` support file. Measurements use three separately
owned workers/listeners, not the allocator's shared HTTP listener. Sessions,
configuration changes, translations and credentials exist only in the clone.

Both readers log in through the actual password form with genuine CSRF tokens;
invalid-CSRF submissions are refused and successful login regenerates the
session ID. Suggestion requests call the real `cms_run()` sequence, including
session startup, request initialization, authentication/translation restoration,
maintenance, dispatcher, title controller/service/model and response completion.
The long-work holder remains synthetic and authenticated. Only that holder's
fixture branch closes early; no application handler is replaced or changed.

Three successful development runs on Windows/PHP 8.3.30 provided 18 comparisons,
36 measured title responses, 18 holder responses, six genuine password/CSRF
logins, six invalid-CSRF refusals, three remember-only restorations and three
logout/revocation checks. All completed with owned workers, clone files and
disposable database cleanup. The final run's three-sample medians were:

| Holder behavior | Reader | HTTP total ms | First byte ms | Session start ms | Completed before holder release |
| --- | --- | ---: | ---: | ---: | --- |
| Session retained | Same cookie | 1194.733 | 1193.375 | 949.971 | No, 0/3 |
| Session retained | Independent cookie | 234.342 | 232.938 | 0.395 | Yes, 3/3 |
| Synthetic early close | Same cookie | 246.859 | 245.367 | 0.290 | Yes, 3/3 |
| Synthetic early close | Independent cookie | 246.120 | 244.446 | 0.295 | Yes, 3/3 |

The final run compares every session key before dispatch and after `cms_run()`
returns; shutdown callbacks are outside this snapshot. Normal title JSON and
picker JSON caused no observed late change;
only allowlisted key names and an integer count for other changes are reported.
Removing one translation from the clone's Czech catalog exercises the actual
picker HTML fallback: it writes `cms_translation_missing` after dispatch, and
the next request confirms that diagnostic persisted alongside the language and
CSRF state. Remember-only title access also creates a new persistent PHP session
through real authentication restoration. Logout invalidates both ordinary access
and subsequent reuse of the revoked remember cookie. These checks are not a
proof covering every translation, error, shutdown callback or concurrent logout.

A subsequent central quick run exposed an environment-isolation defect before
measurement: inherited outer workflow token/path values overwrote the new
child's identity, so the child's health check correctly refused the request.
This was reproduced locally using conflicting inherited identity sentinels.
The fixture now copies only the allocator's explicit child identity/database
fields over the runner environment, checks them before launch, and asserts this
precedence on every run. The final successful run above includes that regression.
The five-second readiness deadline and 900 ms observation were not relaxed.
This focused repair is not a claim that the parent's central rerun has passed.

The central runner discovers the test with a 180-second whole-test timeout in
`php_test_requirements`; no new orchestration path is introduced. It requires
`pdo_mysql`, `curl`, `gd`, `zip`, `dom`, `mbstring`, child processes and loopback
listeners. Use explicit `GALLERY_WORKFLOW_ENABLE=disposable-only` with the
existing dedicated database runner, or `GALLERY_SESSION_ENABLE=disposable-only`
plus `GALLERY_SESSION_MYSQL_BIN` pointing to a MySQL daemon for a newly owned
temporary data directory. Private-daemon startup verifies its data-directory
identity before creating any credential. It never discovers a site's database.
Missing prerequisites are SKIP, or BLOCKED with `GALLERY_WORKFLOW_REQUIRED=1`
or `GALLERY_SESSION_ROUTE_REQUIRED=1`; the lightweight fixture's separate
`GALLERY_SESSION_REQUIRED` switch does not force a database test in source-only CI.

### Session-write review and decision

**Production decision: no session-close change.** This investigation adds the
disposable measurement fixture and documentation only; production session
bootstrap, title-completion routing and upload session behavior are unchanged.
No live host PHP upgrade, host configuration change or production load test was
performed. The locking evidence combines synthetic slow work with disposable
readers: synthetic session issuance in the lightweight test, and genuine
password/CSRF login plus actual application routes in the follow-up. It is not
an actual upload workload, production concurrency measurement or evidence for
another session handler.
Closing a suggestion after it acquires
its session cannot remove its incoming wait behind an upload that already holds
the lock. Moving an upload close earlier requires a separate lifecycle review:

- `app/security.php::current_user()` can call
  `admin_auth_restore_persistent_login_from_request()`, which regenerates the
  session ID and persists `user_id`. Restoration must finish before closing.
- `app/bootstrap/request.php::cms_initialize_request()` runs translation bootstrap
  and Viewer remember restoration before dispatch. Translation context/language
  writes and missing-translation diagnostics in `app/services/translations.php`
  must remain persistent wherever the complete route lifecycle can reach them.
  The actual picker HTML fallback above demonstrates a late diagnostic write;
  treating the whole picker route as read-only would silently discard it.
- `app/controllers/admin_uploads.php` validates session CSRF and writes
  `admin_upload_error` after classic-upload failure; its form GET consumes that
  session value. An upload close before work would lose that error path unless
  it were deliberately migrated.
- `upload_automation_with_gallery_lock()` exists for automation ingestion, but
  this review does not establish that every classic/browser upload path uses
  sufficient operation/entity locking. The session lock must not be removed on
  that assumption. The parent's durable-move and retry-safety work is independent.

The title JSON success path showed no late write in these cases, but closing it
after session acquisition cannot remove the incoming wait measured here. A benefit
from reducing its own short lock-holding time has not been established. A blanket
title/picker close is therefore neither necessary for this measured wait nor safe
for the demonstrated HTML fallback.

Keep Admin reads out of `cms_route_is_read_only_media_asset()`. A further proposal
to shorten a long-running upload's session hold needs a representative disposable
upload/suggestion comparison and complete upload error/domain-lock review. This
investigation does not justify a global session-close refactor.

## Integrated presentation ownership

`app/services.php` loads the runtime service once before the dashboard service.
`admin_dashboard_view_model()` prepares `runtime_support_status` using
`runtime_support_health_status()`. `cms_admin_diagnostics()` prepares the same
model after administrator authorization and includes its `report_lines` in the
existing copy report without changing the positional environment rows.

The prepared field has three documented members: `policy` is the unchanged
offline lifecycle model, `labels` contains localized plain text, and
`report_lines` reuses those same labels. `policy.action_required` drives the main
Maintenance badge, the deferred System Health subtab badge and suppression of
the System ready card. `view_render_admin_runtime_support_card()` in
`admin_dashboard_sections.php` is the shared escaping/markup owner; both the
dashboard and Runtime Diagnostics call it. Views neither observe the host nor
call the policy service. No schema capability, feature flag or diagnostics
route is added, and existing mutation/schema health registrations are preserved.

README requirements, shared-hosting installation and CLI setup guidance link to
this policy. The standalone installer's hard PHP 8.1 compatibility check remains
unchanged. `docs/OPERATIONS.md` did not exist at inspection, so no parallel
operations document was created.

The four new PHP regression entry points are `runtime_support_test.php`,
`runtime_normalization_environment_test.php`, `runtime_support_surfaces_test.php`
and `session_contention_test.php`. The central runner discovers all four;
`scripts/audit_registry.php` gives the session fixture a 90-second timeout.
Parent orchestration owns the central audit and manifest refresh.

The additional `runtime_support_constants_test.php` guards the mechanical move
of the six reviewed definitions into the Core policy owner. It checks exact
values/types, absence of service-local aliases, dependency-free standalone
loading and 13 inclusive UTC lifecycle cases. This ownership change does not
revise the schedule, raise compatibility requirements or reconfigure a host.
The actual-route follow-up adds `session_route_contention_test.php` and its
three owned support files, with the 180-second timeout described above. The
parent retains central verification, source-inventory integration and manifest
refresh; the fixture does not modify shared workflow support or live storage.

### Translation catalog integration

The runtime service is the single owner of the following English fallback
strings. All 14 keys are installed in the maintained English, Czech, German and
Swedish JSON catalogs, with matching English/Czech legacy PHP fallback entries.
Only runtime keys were added; other parallel agents' catalog entries are
preserved. The existing `admin.dashboard.badge_action` key supplies the Action
label. The regression checks catalog completeness, unique runtime keys,
placeholder parity, fallback equality and rendering in all four languages.

| Key under `admin.runtime_support.` | English fallback | Placeholders |
| --- | --- | --- |
| `title` | PHP runtime support | None |
| `summary` | PHP {branch}: {state}. | `branch`, `state` |
| `state_active` | Active upstream support | None |
| `state_security_only` | Upstream security fixes only | None |
| `state_end_of_life` | Upstream support ended | None |
| `state_unknown` | Support status requires review | None |
| `security_until` | Upstream security support through {date}. | `date` |
| `security_unknown` | No reviewed security-support deadline is available for this branch. | None |
| `baseline` | Compatibility minimum: PHP {minimum}. Deployment baseline at policy review: PHP {maintained}; preferred branch: PHP {preferred}. | `minimum`, `maintained`, `preferred` |
| `guidance_maintained` | Keep this branch at its latest patch level and verify enabled extensions in staging. | None |
| `guidance_review` | Review the official PHP support schedule and confirm the hosting runtime before deployment. | None |
| `guidance_upgrade` | Plan a hosting upgrade to a maintained PHP branch and verify the application and enabled extensions in staging. | None |
| `reviewed` | Policy reviewed: {date}. | `date` |
| `reference` | Official PHP support schedule | None |
