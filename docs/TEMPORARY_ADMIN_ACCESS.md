# Temporary administrator support access (#275)

Temporary access is a separate, non-renewable 24-hour authorization grant
issued by an existing permanent administrator. It is intended for short
support and troubleshooting sessions. It does not create a new user, change
passwords or issue long-lived login credentials.

## Owner workflow

1. Sign in with the permanent administrator account. In the Admin sidebar,
   open **Account → Temporary admin access**.
2. Select **Generate support link**. The owner-only POST is CSRF-protected.
   Copy the complete HTTPS link immediately. Its token is displayed only in
   this response and cannot be retrieved from the database later.
3. Deliver the link to a trusted recipient over a private channel. The link
   is a bearer credential until its first successful redemption.
4. The recipient opens it in a separate browser profile or private session.
   The URL fragment is removed with `history.replaceState`; the recipient
   explicitly accepts using a CSRF-protected POST. JavaScript-disabled clients
   can paste the 64-character token manually.
5. Admin pages show a prominent temporary-access notice and the UTC expiry.
   The owner can view the latest 50 grants and select **Revoke now**.
   Revocation invalidates the redeemed session and unused invitation on
   the next authorization check, without modifying the permanent session.
6. The grant expires **86,400 seconds from issuance**, regardless of its
   first use. An expired grant never extends or renews itself.

### Lost, forwarded or compromised invitations

Revoke the grant immediately. Do not send an invitation by a public forum,
issue comment, repository commit, browser screenshot, or analytics link.
The app does not issue a second copy of a lost bearer secret. Create a new
invitation instead. Anyone who redeems it first receives the delegated access.

## Security and implementation

The migration `database/migrations/202610210001_admin_support_access.php`
creates `admin_support_grants` and `admin_support_grant_events`.
Both tables are InnoDB, have indexed ownership and expiry metadata, and keep
referential integrity with `users.id`.

- Invitations consist of 32 CSPRNG bytes encoded as 64 lowercase hex digits.
  Only SHA-256 of the bearer token is stored in `token_hash`.
- Redemption is an atomic conditional SQL update; exactly one competing
  redeemer can exchange a valid, unused, unrevoked, unexpired token.
- The redeemed PHP session receives a distinct 32-byte random verifier.
  Only its SHA-256 hash is persisted in the database. The session ID is
  regenerated, previous session state is cleared, and the durable-login
  cookie is revoked. The support verifier is not placed in a URL or cookie.
- `Gallery\Core\current_user()` checks the grant against the database and
  the permanent owner role, even when request-local identity is cached.
  `cms_dispatch_page()` applies a default-deny Admin route allowlist.
  Refusal is HTTP 403 with a JSON error for JSON callers and no secrets.
- Delegates can inspect diagnostics and edit approved gallery/media content.
  They cannot access password/recovery/account management, manage other
  invitations, modify global security/application settings, issue permanent
  API or login tokens, manage OAuth connections, run the updater/installer,
  or perform migrations. Future Admin routes are refused until reviewed.
- All creation, redemption, revocation and expiry operations have database
  audit records. Normal delegated use produces at most one lifecycle event
  per hour. Ordinary Admin log events include a `support_actor` context
  with the grant ID, an explicit `delegate` type and the permanent owner ID;
  the log `user_id` alone must not be mistaken for the recipient.
- No grant/session bearer values enter the audit context, exceptions,
  ordinary query strings, owner list, or UI after issuance. The recipient
  POST field is named `support_access_token` so existing browser diagnostics
  redact its value. The acceptance page sets `no-store`,
  `no-referrer`, and `noindex` headers.
- Token redemption requires both CSRF validation and the existing
  database-backed anonymous authentication rate limiter. If rate-limit or
  grant storage cannot be inspected, issuance and redemption fail closed.
- A bounded owner-page cleanup deletes at most 50 grants older than 90 days
  past expiry per invocation, cascading their grant-specific audit events.
  Expiry enforcement does not rely on cron or a cleanup request.

### MVC and runtime ownership

| Layer | Files |
| --- | --- |
| Migration | `database/migrations/202610210001_admin_support_access.php` |
| Model | `app/models/auth_persistence.php` |
| Service and restrictive route policy | `app/services/auth_persistence.php` |
| Session boundary | `app/security.php` |
| Controller | `app/controllers/admin_support.php` |
| Views and navigation | `app/views/admin_support.php`, `app/views/admin_chrome.php`, `app/views/layout.php` |
| Dispatcher | `app/bootstrap/dispatch.php` |
| Runtime loader roots | `scripts/runtime_module_roots.php` |

The loader module manifest is generated by the normal release/integration
pipeline. Do not edit `app/runtime/modules.php` manually.

## Verification

The independent regression script `tests/admin_support_access_test.php`
checks token entropy/one-time and expiry semantics, authorization restrictions,
SQL transaction and audit boundary, URL-fragment handling, session rotation,
redaction, strict route registration and language completeness.

For final qualification, run the hosted BASIC checks **after implementation**
and inspect their artifacts. The broader integration matrix, real MySQL/MariaDB
redemption concurrency, browser behavior and shared-host deployment still
require the normal integration workflow before release.

The granting administrator must run the new migration before the first grant.
An installation with missing migration or unavailable authentication-rate
storage remains fail-closed; it does not silently enable support access.
