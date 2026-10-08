# Disposable gallery workflow tests

The Priority 2 baseline exercises a real migrated database and the actual PHP HTTP
routes in a temporary application copy. It never loads the checkout's config.php,
copies its gallery/data/cache directories, or connects to its configured database.
All sample photographs and credentials are generated for the run.

## Entry points

The central audit remains the test orchestrator. The full and release profiles,
and the explicit `php-regression` suite, discover
tests/gallery_workflow_safety_test.php, tests/gallery_workflow_integration_test.php,
and tests/gallery_workflow_browser_test.php through PHP regression discovery.
The quick profile uses its curated PHP subset and does not run these disposable
database workflow fixtures. The historical `--quick` convenience flag on either
workflow launcher delegates straight to the central quick profile without
creating a database, application copy or daemon; use `--audit` or `--release`
for workflow qualification.
The distinct `--audit-quick` mode provisions the same owned database/application
fixture around one central quick audit and requires all nine actual route
lifecycle measurements. It retains the quick profile's curated PHP subset and
does not claim full HTTP/concurrency/browser workflow qualification.
The browser PHP entry point launches tests/gallery_workflow_browser.mjs using the
same standalone headless Chromium approach as the existing map fixture. It needs
no browser tool runtime, npm install, Playwright, Selenium, or Composer dependency.

Ordinary audits without a disposable fixture run the safety test and explicitly
SKIP the HTTP and browser integration tests. A skip is not full-stack evidence.
GALLERY_WORKFLOW_REQUIRED=1 makes missing integration prerequisites BLOCKED with a
nonzero exit code. Explicit GALLERY_WORKFLOW_BROWSER=disabled records only browser
coverage as SKIP, including when workflows are required. Allow 180 seconds per
integration PHP test in the audit registry.

Prerequisites: PHP 8.1+ with pdo_mysql, curl, gd, zip, dom and mbstring; Node.js;
an installed Chrome/Chromium/Edge; and either MySQL 8 locally or a disposable
MySQL/MariaDB service. Browser execution preserves Chromium's sandbox. Running
Chromium as root without a working sandbox is unsupported.

## Local disposable MySQL

The authored-content language fixture selects `sample-1.jpg` and `sample-2.jpg`
by filename and sets their photo order explicitly. Filesystem scan order and
auto-increment IDs must not choose which photo exercises untranslated source
fallback or the translated social-preview caption outside the selected page.

Set GALLERY_WORKFLOW_ENABLE to the exact value disposable-only,
GALLERY_WORKFLOW_MYSQL_BIN to an existing MySQL 8 mysqld executable, and
GALLERY_WORKFLOW_BROWSER to an installed Chromium executable. An optional
GALLERY_WORKFLOW_NODE overrides the Node executable.

~~~powershell
$env:GALLERY_WORKFLOW_ENABLE = 'disposable-only'
$env:GALLERY_WORKFLOW_MYSQL_BIN = 'D:\laragonwebserver\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$env:GALLERY_WORKFLOW_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
php scripts/gallery_workflow_mysql.php --development
~~~

The paths above are workstation examples. Agents may reuse the verified paths
in ignored .agent-local/gallery-workflow-tools.json. No credentials belong there.
The launcher uses --no-defaults for initialization and serving, a fresh random
system temporary directory, a random loopback port, and disables MySQL X and
binary logging. It verifies the connected server's data directory before changing
credentials or issuing SHUTDOWN. It generates a dedicated account whose grants
cover only the escaped gallery_workflow_ database-name prefix.

The --development mode runs only the newly added HTTP/browser tests while they
are being developed. For normal integrated verification use:

~~~text
php scripts/gallery_workflow_mysql.php --audit
~~~

That mode delegates to php scripts/audit.php --profile=full once and supplies the
seeded fixture to both the new tests and the existing
viewer_phase07_mysql_concurrency_test.php. It is not a replacement audit runner.
The central audit's compact reports remain in cache/test-audit. No release
preparation, manifest refresh, publication, or production migration is implied.

For release preparation, finish the version, documentation, manual, and manifest
steps in `RELEASE.md` and initialize the qualification fingerprint first. Then
use the same prerequisite environment with:

~~~text
php scripts/gallery_workflow_mysql.php --release
~~~

This provisions the owned fixture around exactly one
`php scripts/audit.php --profile=release` invocation; it does not run `full`
beforehand. Both central modes require the same database/HTTP and concurrency PASS
records, plus browser PASS when not explicitly disabled, and preserve the central
reports. An independently provisioned disposable
service can likewise use `scripts/gallery_workflow_run.php --release`.

The local daemon launcher uses MySQL 8 initialization flags. A MariaDB executable
is not interchangeable with it; MariaDB coverage uses the CI service below.

## Already disposable database services and CI

scripts/gallery_workflow_run.php --audit can use an independently provisioned
disposable service when these explicit environment variables are supplied:

| Variable | Required value |
| --- | --- |
| GALLERY_WORKFLOW_ENABLE | disposable-only |
| GALLERY_WORKFLOW_DB_HOST | literal 127.0.0.1 |
| GALLERY_WORKFLOW_DB_PORT | dedicated port 1024–65535, excluding 3306 |
| GALLERY_WORKFLOW_DB_USER | gallery_workflow_runner |
| GALLERY_WORKFLOW_DB_PASSWORD | dedicated password of at least 16 characters |
| GALLERY_WORKFLOW_BROWSER | existing Chromium executable, or explicit disabled for browser-only opt-out |

There is no accepted input database name or arbitrary DSN. The runner chooses
gallery_workflow_ followed by 24 random hex characters, uses CREATE DATABASE
without IF NOT EXISTS, and drops only a database it successfully created.
The account needs only schema-local `SELECT`, `INSERT`, `UPDATE`, `DELETE`,
`CREATE`, `ALTER`, `DROP`, `INDEX`, and `REFERENCES` permissions for that prefix.
The engine contract also needs schema-local `CREATE TEMPORARY TABLES` for its
connection-owned JSON table; this is a test prerequisite, not a production grant.
It does not need `TRIGGER`, `SUPER`, an `ALL PRIVILEGES` grant, or authority over
server-global settings. Never repurpose an administrator or production account.

.github/workflows/gallery-workflows.yml creates independent MySQL 8.4, MariaDB
10.11 and MariaDB 11.4 service containers on port 13316, without host data volumes.
It is also a reusable workflow. Direct PR/push/manual CI keeps the safe `full`
profile behavior. `.github/workflows/release-qualification.yml` can call it with
`audit_profile=release`; that preserves the normal matrix and adds one isolated
`Authoritative release audit` job running the central `release` profile. The release caller owns deterministic preparation of the trusted
`release/v_X.Y.Z` branch. If the target patch-note section is incomplete, it
builds complete previous-tag-to-HEAD text diff evidence for GitHub Copilot CLI through
`.github/scripts/patch_notes_ai.php`. The personal-repository workflow expects
a fine-grained PAT in the `COPILOT_GITHUB_TOKEN` repository secret, selects
`gpt-6-luna` with an explicit model-unavailable fallback to `auto`, disables project prompt-mode extensions and grants no Copilot
tools. The model output is validated as data before only the incomplete target
release section may be replaced; completed maintainer-authored notes skip AI.
The text diff has no byte truncation. Separate temporary stdout/diagnostic files
avoid pipe deadlocks; each Git child has a 30-second deadline and the evidence
step has a two-minute workflow limit. Metadata/diagnostic bounds fail explicitly
instead of accepting partial evidence. Missing authentication, AI failure or invalid output blocks
qualification. Once release notes are valid, GitHub builds all four PDFs,
refreshes production inventory and the integrity manifest, passes
`check_release.php`, commits only reviewed release-preparation paths and calls
the reusable matrix with the exact prepared candidate SHA and previous-tag source
base. The final `Release qualification gate` succeeds only when preparation and
the complete reusable matrix succeed. This stage still does not merge, tag or
publish.
During ordinary development, edit the Markdown/LaTeX sources without compiling PDFs
after each change. All four manual source editions stay aligned, while tracked PDFs
may lag until the final hosted release preparation builds them together before the manifest.

Before AI/TeX, the caller invokes the centrally owned `release-preflight` static profile;
the final exact-SHA matrix remains complete. TinyTeX provisioning uses the checksum-locked
2026.02 / TeX Live 2025 final toolchain, never a rolling package repository.
The exact cache key covers lock, package inventory and helper; a hit verifies the local
installation without networking, and save requires all four successful PDF builds.
See [the release toolchain and branch lifecycle](../RELEASE.md) for timeout, invalidation,
reproducible PDF and rerun rules. Release fixes remain on the release branch until
future `release -> main -> develop` reconciliation.

The exact PHP representatives and qualification categories are defined in
[Database support](DATABASE_SUPPORT.md).
The fixed service bootstrap password is a disposable CI fixture value, not an
installation credential. scripts/gallery_workflow_ci.php additionally requires
GitHub Actions and verifies the service hostname before creating its restricted,
random-password runner account. The root credential is removed from child
environments. Database/runtime matrix jobs set PHP_GALLERY_BROWSER=disabled and
GALLERY_WORKFLOW_BROWSER=disabled at job scope, so they do not duplicate Chromium
work. A separate `browser-tests` job on PHP 8.3 and Node 22 discovers and versions
Chrome/Chromium, sets PHP_GALLERY_BROWSER_REQUIRED=1, and runs
`php scripts/audit.php --suite=browser-map`. Required mode fails when the browser
is missing or cannot start, when browser fixtures are skipped, or when no browser
fixture runs. The full central audit remains in place, and the database/HTTP
workflows, engine contracts, image-move crash recovery, and MySQL/MariaDB races still require PASS records
from the central regression log. An absent, skipped or unregistered database/HTTP
or concurrency test cannot qualify. Local browser discovery and explicit
executable overrides retain their existing behavior unless the operator
deliberately sets the same disabled value.
Only compact audit Markdown/JSON reports are uploaded as artifacts.

After verifying the disposable service identity, the CI bootstrap creates the
runner with exactly that schema-local grant. It neither reads nor changes binary
logging policy or any other server-global setting. The revision migration is
ordinary portable table DDL: interrupted installation may leave the revision
column present without its migration ledger row, and replay must accept that
duplicate column without requiring any additional database authority.

The actual MySQL concurrency fixture verifies that the application advances a
gallery revision explicitly before side effects, rejects stale editor forms, and
keeps every registered filesystem writer behind the shared busy-lock wrapper.
Database-trigger interception and ad hoc SQL issued outside those application
boundaries are not part of the product contract. Local source-policy tests or
private MySQL runs do not establish that either CI container image has executed
successfully.

`tests/gallery_workflow_ci_trigger_policy_test.php` exercises the actual
bootstrap statements with inert PDO/environment adapters. The historical filename
now guards removal of trigger-era authority: it covers ownership refusal before
account changes, the exact schema-local grant, absence of server-global SQL, and
root credential removal. It opens no database connection and does not invoke CI
or the central audit.

## Assertions and present limits

The HTTP journey performs real administrator login; rejects anonymous and invalid
CSRF create/upload/edit/delete/restore attempts; creates a nested gallery; uploads
a generated JPEG with multipart transport; edits metadata and visibility; moves a
subtree to Trash and restores it; and rejects mutation after logout. It verifies
database rows, sidecars, parent relationships, original hashes, media responses,
and absence of extra rows/files after retry.

Prepared ZIP upload retry discards the first successful HTTP result and resends
the same session/batch. The test requires identical durable image identity and
file counts. This models lost-response retry; it does not inject a TCP disconnect
during response streaming. Repeated restore must not duplicate the subtree.

The CMS intentionally allows direct URLs for unpublished galleries, while hiding
them from ordinary listings. Private visibility and password protection are the
media authorization denial cases. The test preserves that distinction.

The full-stack Chromium journey covers dynamically opened creation, two immediate
button clicks with a delayed response, consecutive edits after real editor
replacement, multipart upload with a generated browser File, private/public
visibility changes and anonymous media checks, recoverable card deletion, restore
inside a dynamically opened Trash fragment, and submission after logout while an
editor remains open. It checks unchanged document/URL, an open panel, replaced
fragments, SQL/sidecar state, Trash ledger state, and the restored original's hash.
One real PHP editor response is held while a later save and refresh complete;
releasing that old response must leave the newer editor intact.

The browser selects the product's standard multipart option. The default
browser-prepared worker/thumbnail pipeline, real elapsed-time session expiry
(logout is used to invalidate the session deterministically), and physical
transport disconnects remain unimplemented test scenarios. Reordered mutation
POST responses themselves are not covered by the editor-GET reordering case.

Server-side create-gallery and classic multipart-upload requests do not have a
general idempotency key. Replaying those POSTs can create suffixed duplicates.
This baseline checks browser button suppression for create and server batch
identity for prepared upload; it does not claim arbitrary POST replay safety.
Trusted physical pointer/keyboard delivery, assistive technology, mobile browser
coverage, and deliberately injected production-code mutations are not part of
this standalone fixture.

## Cleanup and failures

Fixture ownership uses a random token and a marker in a direct child of the system
temporary directory. Broad paths, wrong markers, arbitrary database names, remote
hosts, and default ports are refused. Cleanup does not follow symbolic links.
The application server is stopped before its database and files are removed;
the local daemon is shut down only after its data-directory identity is verified.

Errors report bounded assertion stages. HTTP bodies, database exceptions,
cookies, CSRF values and credentials are not copied to console or CI artifacts.
Transient diagnostic logs are private fixture files removed on normal cleanup.
A killed parent process or machine crash can leave a temporary directory or child
process; cleanup failures are nonzero and require checking exact ownership before
manual removal. No broad process-name kill or recursive deletion of a shared
temporary/workspace root is appropriate.
