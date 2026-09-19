# Disposable gallery workflow tests

The Priority 2 baseline exercises a real migrated database and the actual PHP HTTP
routes in a temporary application copy. It never loads the checkout's config.php,
copies its gallery/data/cache directories, or connects to its configured database.
All sample photographs and credentials are generated for the run.

## Entry points

The central audit remains the test orchestrator. The main audit discovers
tests/gallery_workflow_safety_test.php, tests/gallery_workflow_integration_test.php,
and tests/gallery_workflow_browser_test.php through PHP regression discovery.
The browser PHP entry point launches tests/gallery_workflow_browser.mjs using the
same standalone headless Chromium approach as the existing map fixture. It needs
no browser tool runtime, npm install, Playwright, Selenium, or Composer dependency.

Ordinary audits without a disposable fixture run the safety test and explicitly
SKIP the HTTP and browser integration tests. A skip is not full-stack evidence.
GALLERY_WORKFLOW_REQUIRED=1 makes missing integration prerequisites BLOCKED with a
nonzero exit code. Allow 180 seconds per integration PHP test in the audit registry.

Prerequisites: PHP 8.1+ with pdo_mysql, curl, gd, zip, dom and mbstring; Node.js;
an installed Chrome/Chromium/Edge; and either MySQL 8 locally or a disposable
MySQL/MariaDB service. Browser execution preserves Chromium's sandbox. Running
Chromium as root without a working sandbox is unsupported.

## Local disposable MySQL

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
beforehand. Both central modes require the same three workflow/concurrency PASS
records and preserve the central reports. An independently provisioned disposable
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
| GALLERY_WORKFLOW_BROWSER | existing Chromium executable |

There is no accepted input database name or arbitrary DSN. The runner chooses
gallery_workflow_ followed by 24 random hex characters, uses CREATE DATABASE
without IF NOT EXISTS, and drops only a database it successfully created.
The account needs CREATE/DROP and application migration permissions for that
prefix only. Never repurpose an administrator or production account.

.github/workflows/gallery-workflows.yml creates independent MySQL 8.4 and
MariaDB 11.4 service containers on port 13316, without host data volumes.
The fixed service bootstrap password is a disposable CI fixture value, not an
installation credential. scripts/gallery_workflow_ci.php additionally requires
GitHub Actions and verifies the service hostname before creating its restricted,
random-password runner account. The root credential is removed from child
environments. CI requires all three real-workflow/concurrency PASS records from
the central regression log; an absent, skipped or unregistered test cannot qualify.
Only compact audit Markdown/JSON reports are uploaded as artifacts.

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
