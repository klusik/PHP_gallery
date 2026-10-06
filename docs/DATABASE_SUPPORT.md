# Database support policy

Reviewed 2026-10-05. This document owns PHP Gallery's database-version status, the
project's direct CI evidence, and the difference between vendor maintenance and
project qualification. The workflow matrix is in
[`.github/workflows/gallery-workflows.yml`](../.github/workflows/gallery-workflows.yml).

## Status and qualification matrix

| Project status | Database series | Required PHP representative | Evidence and guidance |
| --- | --- | --- | --- |
| Maintained and directly tested | MySQL 8.4 LTS | PHP 8.3 | Required disposable real-database workflow and database-semantic fixture. |
| Maintained and directly tested | MariaDB 10.11 LTS | PHP 8.3 | Required disposable real-database workflow and database-semantic fixture; included because 10.11 is a current shared-hosting LTS line. |
| Maintained and directly tested | MariaDB 11.4 LTS | PHP 8.5 | Required disposable real-database workflow and database-semantic fixture. |
| Legacy / best effort | MySQL 8.0; MariaDB 10.4 through 10.10 | None | Historical compatibility may remain, but these lines have no required project database workflow and no project regression or security assurance. Upgrade to a maintained, directly tested series. |
| Unsupported historical versions | MySQL 5.7 and earlier; MariaDB 10.3 and earlier | None | Outside the project's support policy. Do not deploy new installations on these series. |
| Unqualified | Any database series not listed above | None | Compatibility has not been established by required direct CI. This includes newer vendor-maintained LTS series, MySQL Innovation releases, and future series. A higher version number does not grant qualification. |

The matrix names exact representative tuples, not every combination of PHP and
server versions. In particular, PHP 8.1 remains the application's source/runtime
compatibility floor and is checked by a source-only job; it does not qualify a
MySQL or MariaDB combination. The separate browser job also has no external
server. CI status is evidence for the listed representative and workflow at the
revision tested, not a statement that every provider configuration, server patch,
plugin, SQL mode, or production dataset behaves identically.

The required jobs use disposable databases, run the central real workflow and
concurrency coverage with skips forbidden, and never connect to an installation.
The MySQL 8.4/PHP 8.3 and MariaDB 11.4/PHP 8.5 jobs are retained. MariaDB
10.11/PHP 8.3 is an additional required row rather than a full cross-product.
The direct semantic fixture exercises the migration and SQL contracts below on
both MySQL and MariaDB: InnoDB and `utf8mb4` metadata, vote `CHECK` enforcement,
JSON validation/extraction, and advisory `GET_LOCK()` behavior. The broader
real-database workflow's concurrency scenarios exercise transactional row locks.

## Database behavior the application relies on

Migrations and models target PDO MySQL and use InnoDB tables with
`utf8mb4_unicode_ci`, foreign keys, unique indexes, and ordinary MySQL/MariaDB
DDL. Transactional admission and mutation paths use `SELECT ... FOR UPDATE`;
the real-database workflow includes race checks for those paths. Admin operation
keys, gallery edit/move coordination, upload automation, telemetry maintenance,
and other bounded jobs use `GET_LOCK()` advisory locks. The disposable semantic
fixture checks lock exclusion and release behavior without involving a live site.

The initial image-vote schema declares
`CHECK (vote IN (-1, 1))`. MySQL before 8.0.16 accepted but did not enforce
`CHECK`, so input validation in the application remains necessary even where the
database also enforces the constraint. The direct MySQL representative is 8.4;
the MariaDB representative verifies the migration and constraint on that family.

The anonymous-telemetry migration declares two `context_json JSON` columns, and
telemetry diagnostics query them with `JSON_VALID`, `JSON_EXTRACT`, and
`JSON_UNQUOTE`. The semantic fixture verifies JSON validation, extraction,
Unicode round-tripping, and rejection of malformed values. MariaDB implements
`JSON` as a `LONGTEXT` alias with JSON validation, while MySQL uses a native JSON
representation. The Gallery relies on the shared SQL operations and does not
require byte-identical storage formats. Cooperative-gallery state also uses JSON
extraction from a text column.

The migration runner accounts for MySQL/MariaDB differences around
`ALTER TABLE ... IF NOT EXISTS`: it treats known duplicate-object DDL errors as a
successful replay when an interrupted migration already made the change. Do not
remove that replay path based on one engine's behavior, and do not assume all
server DDL can be rolled back as one transaction. Schema inspection queries
`INFORMATION_SCHEMA` for table, column, index and relationship state. Server
version reporting is diagnostic; the application does not select a different
runtime code path based on `SELECT VERSION()`.

## Compatibility retirement handoff

No production database-version branches were found. The unsupported historical
floor and the Smart Gallery query's former window-function-avoidance rationale
are policy/documentation retirement candidates for Issue #73. That review must
also assess the existing digest index on correctness, performance, and safety;
keep the query and index behavior until that evidence supports a change. The
cross-engine duplicate-DDL replay path remains necessary for maintained MySQL and
MariaDB releases. Changing the support policy alone does not justify deleting
compatibility or index behavior.

## Vendor lifecycle and upgrade planning

Vendor maintenance and PHP Gallery's test status answer different questions.
As reviewed on 2026-10-05, MySQL 8.4 is an LTS line; MySQL's LTS model specifies
five years of Premier Support and three years of Extended Support. MySQL 8.0
reached vendor end of life with 8.0.46 in April 2026. MariaDB Foundation policy
lists Community maintenance through 2028-02-16 for 10.11 and 2029-05-29 for 11.4.
The Foundation also lists newer maintained LTS lines, including 11.8 and 12.3;
they remain unqualified by PHP Gallery until required CI directly covers them.

Before changing a production database server:

1. Make a verified backup of the database and the matching Gallery files,
   `config.php`, galleries and protected runtime storage. Confirm that a restore
   is possible.
2. Clone the installation into an isolated staging environment and use the
   database vendor's documented supported upgrade path. For example, MySQL 5.7
   cannot jump straight to 8.4; its documented path goes through MySQL 8.0.
3. Exercise the upgraded database with the same application version first, then
   apply pending Gallery migrations through the normal migration runner and
   qualify login, uploads, protected media, reports, maintenance and relevant
   concurrency workflows.
4. Switch production only after the staging upgrade and restore checks succeed.
   Do not treat an application migration as a substitute for a database-server
   upgrade procedure.

Cross-family migration (MySQL to MariaDB or MariaDB to MySQL) is not implied by
this project matrix. Compare collation, SQL mode, authentication, JSON behavior,
backup format, and vendor-supported import/upgrade procedures before planning it.

## Primary references

- [MySQL 8.4 LTS and Innovation release model](https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html)
- [MySQL 8.4 CHECK constraints](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html)
- [MySQL 8.0.16 CHECK enforcement change](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-16.html)
- [MySQL 8.0 release notes and end-of-life notice](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/)
- [MySQL supported upgrade paths to 8.4](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html)
- [MariaDB JSON data type](https://mariadb.com/docs/server/reference/data-types/string-data-types/json)
- [MariaDB Foundation maintenance policy](https://mariadb.org/about/)
- [MySQL `GET_LOCK()` behavior](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html)
- [MariaDB 10.11 release series](https://mariadb.com/docs/release-notes/community-server/10.11/what-is-mariadb-1011)
