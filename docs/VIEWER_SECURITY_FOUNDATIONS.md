# Viewer Security Foundations

This document describes the viewer-account security architecture from the dormant Phase 0 through Phase 0.7 foundations to the reachable Phase 1.0 account HTTP boundary, Phase 1.1 favourites, Phase 1.2 account lifecycle wiring, Phase 2.0 private collections, the pre-Phase 3 administrator provisioning inter-step, Phase 2.5 administrator viewer-account security controls, Phase 3.0 unlisted read-only collection sharing, Phase 4.0 open-registration policy/lifecycle preparation, and Phase 4.1 public verified-email open registration. The Phase 0 sections remain historical foundation contracts; later sections document the intentionally narrow viewer-facing, administrator-operated, and recipient-facing features now wired to those services. Public profiles/discovery, uploads, comments, passkey ceremonies, MFA, CAPTCHA/Turnstile, and other optional viewer authentication features remain unavailable.

The existing gallery and administrator behavior remains the authority for all current application functionality.

## Non-negotiable identity boundary

The existing `users` table, `current_user()`, administrator session state, administrator persistent-login tokens, and `require_admin()` form one historical administrator identity domain. They are not a generic account system.

The future viewer domain is intentionally separate:

```text
administrator identity                 viewer identity
----------------------                 ---------------
users                                  viewer_accounts
current_user()                         current_viewer()
$_SESSION['user_id']                   $_SESSION['viewer_auth']
admin_remember_tokens                  viewer_remember_tokens
existing admin auth controllers        no Phase 0 viewer controllers
```

**Viewer authentication must never satisfy administrator authorization.**

This is particularly important because the current gallery authorization model intentionally gives authenticated administrator identities broad access. `visitor_can_access_gallery()` has a historical `current_user()` administrator bypass, and public media code contains related administrator-aware checks. Phase 0 preserves those rules exactly. Viewer session state is stored under a different namespace and `current_user()` remains unaware of viewer tables and viewer session state.

No viewer state is written to `$_SESSION['user_id']`. No viewer row is written to `users`. No viewer role is added to the administrator role model.

## Public download capability boundary

Public gallery-download capabilities are deliberately outside the Viewer identity domain. A download capability is a short-lived HMAC-signed bearer for one resource, one resource ID, and one action scope (`progressive` or `legacy`). It does not identify a viewer, create a session, persist gallery access, or bypass the canonical gallery/image visibility checks. Every progressive manifest and source request revalidates the capability and then re-runs current resource authorization; reusable manifest metadata contains no capability or permission snapshot. Legacy POST fallback similarly validates its separate scope before current resource authorization and bounded archive handling.

Viewer login therefore neither replaces nor broadens the public download capability contract. Collection/favourite membership is not accepted as download authority, a shared collection token is not accepted as gallery-download authority, and cached download metadata never converts earlier viewer/gallery visibility into a persistent grant. The public-download hardening intentionally relies on cheap scoped authorization, cache-key control, bounded server work, and artifact reuse rather than CAPTCHA or Viewer-account enrollment.

## Collection permission invariant

**A collection reference is not an authorization grant.**

`viewer_favourites` and `viewer_collection_items` reference `images.id`, but they contain no gallery visibility, password, share-token, access-mode, or permission snapshot fields. A future read path must obtain the referenced image and re-run the canonical gallery/media authorization checks for the requesting principal at request time.

Consequences:

1. Adding an image to favourites does not preserve access to that image.
2. Adding an image to a collection does not preserve access to that image.
3. Sharing a collection does not grant access to its images.
4. If a source gallery later becomes private, inaccessible references must stop resolving for a viewer who cannot independently access that source.
5. If an image is deleted, its favourite and collection references are deleted by foreign-key cascade. No gallery/media row is ever deleted because viewer data is deleted.
6. A future collection share token can authorize only access to the collection container. It must never be accepted by the existing gallery/media authorization layer.

The canonical media identity is `images.id`. The existing scanner and rename/move logic preserve the database image row when a media path changes, while actual image deletion removes the row. Using the canonical image id therefore avoids fragile path-string identity and gives deterministic cleanup semantics.

## Phase 0 feature state

Viewer functionality is disabled by default in `config.example.php`:

```php
'viewer_accounts' => [
    'enabled' => false,
    'registration_mode' => 'disabled',
    // ... bounded future limits ...
],
```

`viewer_accounts_enabled()` requires the global Viewer Accounts master feature plus an enabled subordinate viewer mode. Registration policy normalization accepts only `disabled`, `invite_only`, and `open`; unknown values fail closed to `disabled`. `viewer_registration_mode()` returns `disabled` while the global master feature is off even if a subordinate configuration or administrator override contains another registration value. The master feature remains OFF by default.

No dispatcher entry, controller, view, public form, JavaScript module, CSS rule, email trigger, or share URL was added. The dormant token/session/rate-limit services also fail closed while the viewer feature is disabled, so disabling the feature is not dependent only on the absence of routes.

## Phase 0.5 pending-registration boundary

Phase 0.5 prepares how a future anonymous person can express registration intent without becoming a durable viewer identity.

The central invariant is:

**A pending registration request is not a viewer account.**

Future anonymous registration starts in `viewer_registration_requests`, not `viewer_accounts`. A syntactically valid request can therefore be deduplicated, rate-limited, expired, verified, cancelled, or garbage-collected without creating a durable account row, session, favourite namespace, collection namespace, or any authenticated principal.

`viewer_registration_request_begin()` is an internal service primitive only. It:

1. requires the master viewer feature plus `invite_only` or `open` registration mode;
2. validates and canonically normalizes the candidate email;
3. requires the Phase 0.5 schema to be verifiably available;
4. reserves exact-client and subnet abuse budgets before staging state;
5. in invite-only mode, performs a non-consuming invitation preflight before consuming identifier/global admission budgets;
6. reserves the normalized-identifier budget before the installation-global registration budget so obviously suppressed retries cannot cheaply burn the global circuit breaker;
7. checks whether the normalized email already belongs to a durable viewer account;
8. serializes admission against the hard staged-row cap;
9. creates or refreshes one row for the normalized email;
10. creates or rotates the high-entropy verification capability and stores only its SHA-256 hash, except that a repeated submission preserves an already successfully mailed, unconsumed, still-valid verifier;
11. returns plaintext verification authority only to the internal caller when a verification message is actually eligible for a delivery attempt.

It intentionally contains no `INSERT INTO viewer_accounts`.

Detailed internal `reason` values are diagnostic only. A future anonymous controller must map all eligible registration outcomes to the same `viewer_registration_public_result_code()` so account existence, invitation state, mail suppression, or throttling decisions are not exposed unnecessarily.

### `viewer_registration_requests`

Important properties:

- `normalized_email` uses the same deterministic binary comparison model as durable accounts and has a unique key;
- the staging row contains the candidate email because future verification delivery and activation need it, but it is ephemeral personal data with an indexed expiry;
- `email_fingerprint` provides a keyed pseudonymous form for security correlation without requiring another raw-email copy;
- `viewer_invitation_id` is nullable and unique, so one invitation cannot stage multiple identities;
- `verification_token_hash` is unique and contains no plaintext capability;
- `verification_token_expires_at` and `verification_token_consumed_at` separate validity from single-use consumption;
- `verification_send_count` and `verification_last_sent_at` record successful transport handoff later, independently from rate-limit reservations;
- `status` is only `pending_verification`, `email_verified`, or `cancelled`;
- `expires_at` is indexed for bounded scheduled cleanup;
- no password or password hash is stored in the pending table.

The default hard cap is 250 staged requests. It is configurable but bounded in code.

### `viewer_registration_state`

This singleton state table exists specifically to make the pending-row cap resistant to concurrent requests.

Before admitting a previously unseen normalized email, the service locks the `pending_requests` state row with `SELECT ... FOR UPDATE`. New-row admission and counter changes therefore serialize. If the cap is reached, an expired bounded batch is reclaimed and the counter is recomputed from the authoritative pending table. If the table remains at capacity, the new identity is refused instead of allowing attacker-controlled database growth.

The counter is an admission-control optimization, not independent source-of-truth business data. Scheduled cleanup repairs it by recounting the staging table while holding the same lock.

### `viewer_invitations`

An invitation is a capability, not an account and not an administrator session.

The table stores:

- a unique hash of a 256-bit random opaque token;
- optional normalized intended email for administrator display;
- optional HMAC target-email binding used as the authorization check;
- optional `created_by_admin_user_id` audit relation to the existing administrator domain;
- creation/expiry timestamps;
- claim and revocation timestamps.

The raw invitation token is never stored. The intended email may be stored in the invitation row only for the authenticated administrator list; authorization continues to use the keyed fingerprint rather than trusting that display value.

Only an existing administrator id can be recorded as creator by `viewer_invitation_issue()`. This does not merge the identity domains: the administrator foreign key is audit attribution only, while any future viewer identity remains in `viewer_accounts`.

Invitation validation is non-consuming. Claiming occurs only inside the explicit staged-registration mutation and is protected by a row lock. The service re-checks revocation, expiry, and optional email binding again while holding that row lock, so the earlier preflight is never treated as the authorization decision. Once an invitation is claimed, it cannot stage another identity. Repeated handling of the same already-claimed invitation can proceed only when the transaction proves the claim belongs to the same normalized pending request. Revocation remains callable even while registration admission is disabled, allowing dormant capabilities to be invalidated before a later re-enable. Administrator deletion first revokes the capability and cancels any staged request, then removes the invitation row; a deleted invitation link therefore cannot be used later.

## Scanner-safe email verification staging

Phase 0.5 deliberately separates:

```text
validate token
    |
    | read-only
    v
show/establish confirmation state later
    |
    | explicit state-changing confirmation
    v
consume token once
    |
    v
viewer_registration_requests.status = email_verified
```

`viewer_registration_verification_validate()` performs no irreversible transition. A mail-security scanner, preview crawler, or anti-phishing product can therefore fetch a future verification URL without consuming the capability.

`viewer_registration_verification_confirm()` performs the irreversible operation under a database transaction and `FOR UPDATE` lock. It re-checks status, token expiry, request expiry, cancellation, and prior consumption in the `UPDATE` predicate. Concurrent confirmation attempts can therefore produce only one successful transition.

Even after successful confirmation, the result is still an ephemeral registration row. Phase 0.5 does not create a viewer account, password, session, remember token, favourite, collection, or public identity. The `email_verified` staging state receives a short post-verification expiry so a later activation phase must complete deliberately rather than leaving verified personal data indefinitely.

## Viewer email-abuse authorization boundary

Email delivery is treated as a security-sensitive capability separate from HTTP request throttling.

`app/services/viewer_mail.php` intentionally sends no mail. It defines the authorization step that a future viewer verification/reset/invitation mail flow must pass before calling any transport.

For verification mail, the default reservation plan includes:

- exact normalized-address cooldown;
- exact normalized-address hourly budget;
- exact normalized-address daily budget;
- exact trusted-client IP hourly budget;
- exact trusted-client IP daily budget;
- trusted-client subnet hourly budget (`/24` IPv4, `/64` IPv6);
- trusted-client subnet daily budget;
- installation-wide daily budget.

The reservation order is deliberate: narrow recipient and network budgets are consumed before the installation-global budget. Requests that are already suppressed by a per-address cooldown or local abuse limit therefore cannot cheaply exhaust the global mail circuit breaker without becoming eligible to send.

Password-reset mail has its own independent buckets. Invitation delivery has independent recipient and installation-wide daily budgets.

These budgets reuse `viewer_rate_limits`; there is no second counter subsystem. Subjects continue to be normalized and HMAC-pseudonymized before storage. The client IP comes only from `request_client_ip()`, so spoofed forwarding headers remain ineffective unless the direct peer and header family are explicitly trusted.

A missing trustworthy client IP fails anonymous verification/reset mail closed. A limiter exception also returns a deny decision. A future transport must not run after either outcome.

Rate-limit reservation occurs before delivery. A failed transport does not refund the reservation. This is deliberate: the attempt already consumed application/network resources, and automatic refunds can create retry-amplification or concurrency races.

The default verification recipient budget is one reserved message per 10 minutes, three per hour, and five per day. Exact-IP defaults are 10/hour and 25/day, subnet defaults are 25/hour and 60/day, and the installation-wide verification budget is 50/day. These are conservative shared-hosting defaults and remain configuration-only; no Admin UI exists for them in Phase 0.5.

`viewer_rate_limit_consume()` now defines `max_attempts` literally as the number of attempts allowed within the current window. The next attempt establishes the temporary lock. Viewer rate limiting remains dormant, so correcting that Phase 0 boundary changes no existing public/admin behavior.

### Why existing administrator mail was not refactored

The current password-reset transport is embedded in `app/controllers/admin_auth.php` and includes administrator-specific settings, translations, SMTP diagnostics, and compatibility behavior.

Phase 0.5 does not move that code merely for architectural aesthetics. Touching it would widen the regression surface of an otherwise dormant foundation phase. The future viewer mail flow must either extract a neutral low-level transport in a separately tested mechanical refactor or add a small compatible neutral transport service. Until then, `viewer_mail.php` is authorization only.

There is still:

- no PHP `mail()` call in viewer services;
- no SMTP socket in viewer services;
- no provider API integration;
- no queue or worker;
- no viewer-triggered email;
- no invitation email;
- no registration email;
- no password-reset email for viewers.

## Database model

Migrations `database/migrations/202608180001_viewer_security_foundations.php` and `database/migrations/202608180002_viewer_registration_foundations.php` are additive. They create new InnoDB tables with `CREATE TABLE IF NOT EXISTS` and do not alter `users`, `galleries`, `images`, or existing authentication/share-token tables.

### `viewer_accounts`

Minimal viewer identity and lifecycle record.

Important fields:

- `id`: internal opaque database identity. It is not sufficient public authorization.
- `email`: stored contact/identity address. Personal data.
- `normalized_email`: deterministic identity comparison value using binary collation and a unique constraint.
- `password_hash`: native `password_hash()` output, nullable so future passkey/invite designs are not forced to invent a fake password.
- `status`: `pending_verification`, `active`, `suspended`, or `disabled`.
- `security_version`: monotonically increasing authentication invalidation version.
- `email_verified_at`, `password_changed_at`, `last_login_at`, `suspended_at`, `disabled_at`: security/lifecycle timestamps.
- `created_at`, `updated_at`: account lifecycle timestamps.

The account table intentionally does not add name, birth date, gender, address, phone number, biography, public username, avatar, or public profile data.

### `viewer_email_verification_tokens`

Stores email verification capabilities. The plaintext token is generated only when issuance occurs and is never stored. `token_hash` contains the SHA-256 digest of a high-entropy random token. `email_fingerprint` is an HMAC fingerprint rather than a second raw email copy. Expiry, consumption, invalidation, account ownership, and cleanup are indexed.

A newly issued token invalidates prior unused verification tokens for the same account. Consumption uses `SELECT ... FOR UPDATE` and an ownership-independent hashed lookup, then marks exactly one row consumed.

### `viewer_password_reset_tokens`

Uses the same hashed, expiring, single-use model. Each row also stores the account `security_version` that existed at issuance. A future reset flow must compare the consumed token version with the current account version before changing credentials. Password change/reset/recovery can then increase the account security version to invalidate other authentication state.

### `viewer_remember_tokens`

Prepares a selector/verifier design:

- selector: random public lookup component;
- verifier: high-entropy secret returned to the future browser flow;
- verifier hash: only this digest is stored;
- account security version: binds the persistent credential to current account security state;
- expiry and revocation timestamps;
- optional HMAC user-agent fingerprint.

Verification joins `viewer_accounts` and requires the account to remain active and the token security version to equal the account version. Phase 0 deliberately does not create a remember-me cookie or restore a viewer session from this table. A future successful restore flow must rotate the persistent credential rather than repeatedly using one verifier indefinitely.

### `viewer_sessions`

Server-side session records contain a hash of an independent high-entropy viewer session token, account id, security version, expiry/revocation timestamps, and privacy-safer HMAC fingerprints for client IP/user agent.

The plaintext token is retained only inside the existing PHP session under `$_SESSION['viewer_auth']`. It is never placed in the administrator `user_id` key.

This makes future session enumeration and per-session revocation possible without introducing Redis, workers, or another infrastructure dependency.

### `viewer_security_events`

Structured viewer security audit records. It stores:

- optional account id;
- bounded event key and outcome;
- HMAC client IP/user-agent fingerprints;
- bounded request correlation id;
- allowlisted small JSON context;
- creation and retention timestamps.

The context sanitizer accepts only low-risk diagnostic keys. Passwords, authentication tokens, CSRF values, raw email addresses, cookies, authorization headers, and secret URLs are not valid event context. The event record is detached with `ON DELETE SET NULL` when an account is deleted so a security trail may be retained without retaining the account row/email.

### `viewer_rate_limit_buckets` and `viewer_rate_limits`

These tables provide a future server-side abuse-control foundation with hard storage boundedness.

`viewer_rate_limits` uses a composite primary key `(bucket, subject_hash)`. Subjects are normalized and HMAC-hashed. Raw IPs, emails, or account identifiers do not need to be stored in the throttle table.

`viewer_rate_limit_buckets.entry_count` is serialized with a row lock before admitting a new subject. Each allowlisted bucket has a configured maximum number of subject rows. When the cap is reached, stale rows are reclaimed; if the bucket remains full, a new attacker-controlled subject is refused rather than growing the table without bound.

This is intentionally separate from the existing administrator throttle storage. The existing admin throttle behavior is preserved for compatibility. A later dedicated admin-hardening change may choose to adopt the same atomic/bounded principles after separate regression review.

### `viewer_favourites`

Composite primary key `(viewer_account_id, image_id)` makes duplicate favourites impossible even during concurrent requests. Both foreign keys cascade only into viewer-reference data. Deleting an account removes its favourites. Deleting an image removes stale favourite references. Deleting a favourite never affects an image.

There is no permission field.

### `viewer_collections`

Stores account-owned containers with bounded plain-text metadata:

- owner id;
- title up to 160 characters;
- optional description up to 2,000 characters;
- creation/update timestamps.

No HTML or Markdown rendering model is introduced. A future renderer must escape these fields as ordinary untrusted text.

### `viewer_collection_items`

Composite primary key `(viewer_collection_id, image_id)` prevents duplicate image membership. `position` plus the ordering index provides deterministic future ordering. Foreign keys cascade only on collection/account/image deletion.

There is no visibility/access/share/password/permission snapshot.

### `viewer_collection_share_tokens`

Prepares revocable capability records for future collection-container sharing:

- collection id;
- optional creator account id for audit attribution;
- unique token hash;
- created, last-used, expiry, and revocation timestamps.

The raw capability token is not a database column. A future share URL must contain high-entropy random authority and lookup must hash it before comparison. A sequential table id is never sufficient authority.

The table intentionally contains no image or gallery permission fields. A valid collection token must never be forwarded to or interpreted by existing gallery/media share-token authorization.

### `viewer_passkeys`

Schema-only preparation for later WebAuthn support:

- credential identifier plus a unique SHA-256 identity hash;
- public key material;
- signature counter;
- transports;
- AAGUID;
- friendly name;
- creation/update/last-used timestamps.

No private key field exists. No WebAuthn ceremony, challenge storage, browser JavaScript, dependency, or passkey UI exists in Phase 0.

## Migration and installation behavior

The migration uses the existing timestamped migration runner. It is included automatically in the normal pending-migration sequence and therefore also in fresh installation, because the installer executes the same migration directory.

The migration is replay-safe at the statement level with `CREATE TABLE IF NOT EXISTS`. It is non-destructive and makes no data conversion. Existing installations retain existing rows and semantics. The runner records the migration only after every statement succeeds, matching the existing application migration contract.

MySQL/MariaDB DDL performs implicit commits, so Phase 0 does not claim an all-or-nothing transaction across the entire migration file. Replay safety is the recovery mechanism for interruption. Parent tables are created before dependent foreign keys, and existing `images` is created by the initial schema long before this migration.

There is no reverse-migration framework. Rollback means restoring a pre-update database backup if a release itself must be reversed. Removing the new tables is not automated because that could destroy future viewer data.

## Password primitives

`app/services/viewer_accounts.php` uses PHP native password APIs only:

- `password_hash()`;
- `password_verify()`;
- `password_needs_rehash()`.

Argon2id is preferred when exposed by the PHP build. Otherwise `PASSWORD_DEFAULT` is used. If the fallback resolves to bcrypt, the accepted byte length is capped at 72 so the application never silently truncates a longer password. Non-bcrypt native algorithms use a 4,096-byte defensive input cap to prevent unreasonable request memory/CPU consumption while still allowing long passphrases and password-manager output.

No composition rule requiring uppercase/lowercase/number/symbol was added. A future registration/reset flow should add product-level minimum-length guidance and rate limiting, not custom cryptography.

Passwords must never be logged or written to recoverable storage.

## Opaque authority tokens

`app/services/security_tokens.php` supplies the common low-level opaque-token primitives:

- `security_opaque_token_generate()` uses `random_bytes()` and URL-safe Base64 without padding;
- `security_token_selector_generate()` creates a random selector for selector/verifier credentials;
- `security_authority_token_hash()` stores a deterministic SHA-256 digest of high-entropy authority;
- `security_authority_token_verify()` performs `hash_equals()` verification.

A default opaque token contains 32 random bytes, providing 256 bits of random input before encoding.

For high-entropy random capability tokens, a fast digest is appropriate because an offline database attacker cannot feasibly enumerate the random token space. Passwords are different and therefore use slow native password hashing.

Existing gallery share tokens are not migrated in Phase 0. Their current `access_token_hash` behavior remains unchanged so existing links cannot regress.

## Email normalization

`viewer_email_normalize()`:

1. trims whitespace around the complete submitted address;
2. validates it with PHP's email validation;
3. rejects NUL and addresses over the database limit;
4. preserves the local part exactly;
5. lowercases the domain only;
6. performs no provider-specific dot or plus-address rewriting.

`normalized_email` uses a binary database collation and a unique key. This makes the application's chosen comparison deterministic rather than inheriting a server/database default collation. The design intentionally does not claim that every provider treats local-part case identically.

Future anti-abuse rate-limit identifiers use a more aggressive lowercased/trimmed subject normalization because throttling should resist trivial case/whitespace bypass. Throttle identity is not the authoritative account identity comparison.

## Account lifecycle and security version

Supported account states are:

| State | Future authentication | Future content mutation | Intended meaning |
| --- | --- | --- | --- |
| `pending_verification` | no | no | Account exists but required verification is incomplete. |
| `active` | yes | yes | Normal verified account. |
| `suspended` | no | no | Temporarily blocked by security/administrative action. |
| `disabled` | no | no | Disabled account pending deletion/recovery policy. |

A separate `deleted` status is not introduced. Physical account deletion has deterministic referential cleanup and removes the account's personal email data. Security events use `ON DELETE SET NULL` so retained security diagnostics can survive without the personal account row.

`security_version` is copied into viewer sessions, remember tokens, password reset tokens, recent reauthentication authority, and staged email-change authority. `viewer_account_invalidate_authentication()` increments it and revokes live viewer sessions, remember tokens, and pending password-reset tokens in one service transaction. Phase 0.7 password change, verified email change, account-state transitions, and deletion all re-check the authoritative version under transaction before mutating security state.

Even if explicit revocation of an individual row were missed, version mismatch makes old authentication state invalid when it is checked correctly. A successful Phase 0.7 password change or verified email switch deliberately revokes all viewer sessions and remember credentials, clears local viewer/re-authentication authority, and requires a normal login again rather than silently minting a replacement session.

## Viewer session model

The application already starts a PHP session using the historical configured administrator session name. Phase 0 does not rename that cookie because doing so would be a broad compatibility change. Future viewer state can coexist inside the PHP session only under an explicit independent namespace:

```php
$_SESSION['viewer_auth'] = [
    'account_id' => 123,
    'security_version' => 4,
    'token' => 'high-entropy viewer session secret',
];
```

`viewer_session_establish()`:

1. refuses while the viewer feature is disabled;
2. requires an active viewer account;
3. creates independent random session authority;
4. stores only its hash in `viewer_sessions`;
5. binds the row to the account security version;
6. optionally records HMAC IP/user-agent fingerprints for diagnostics;
7. calls `session_regenerate_id(true)` when a PHP session is active to prevent fixation;
8. writes only the viewer namespace and preserves administrator state.

`current_viewer()` resolves only this namespace plus `viewer_sessions` and `viewer_accounts`. It rejects disabled feature state, missing/malformed viewer state, expired or revoked sessions, non-active accounts, and any security-version mismatch.

`viewer_session_clear()` and viewer revocation do not unset `$_SESSION['user_id']`. Conversely, `current_user()` does not inspect `viewer_auth`.

Phase 0.7 adds `$_SESSION['viewer_reauthentication']` as a separate short-lived authority for sensitive account operations. A normal valid viewer session is insufficient by itself. The reauthentication record is bound to viewer account id, current `security_version`, current server-side `viewer_sessions.id`, establishment time, and expiry. Interactive password login may establish it, and `viewer_reauthenticate_password()` can refresh it after explicit current-password verification. Remember-token restoration never establishes it: `viewer_session_establish()` clears inherited recent-auth state before a restored session is created. Expiry, account mismatch, session-row mismatch, version change, logout, account-state transition, password reset/change, verified email change, or deletion fails closed and clears or invalidates the authority. No password or password-derived secret is stored in this namespace.

The existing PHP session cookie is already configured `HttpOnly`, `SameSite=Lax`, and `Secure` when HTTPS is detected. A later viewer-login phase should re-review cookie/session lifetime and whether separating viewer/admin PHP session cookies is worth the additional compatibility complexity. Phase 0 does not change existing cookie behavior.

## CSRF

The existing CSRF primitive uses 32 random bytes encoded as hexadecimal and `hash_equals()` comparison. It is session-scoped and is suitable as the primitive for future cookie-authenticated viewer mutations as long as future viewer routes explicitly require it.

Phase 0 does not rename the current admin CSRF key, because doing so would risk existing forms. It also does not add viewer endpoints merely to exercise CSRF.

Future viewer POST mutations that must require CSRF include favourites, collection mutation, collection membership mutation, share creation/revocation, account-security changes, and any state-changing logout design. Authentication endpoints must separately address login CSRF/session fixation as appropriate.

## Trusted proxy and client IP handling

`app/services/client_ip.php` provides a conservative client-IP resolver for future viewer abuse controls.

Default configuration trusts no proxies and no forwarding headers. Therefore the result is the canonicalized `REMOTE_ADDR` only. An attacker cannot select another rate-limit identity by sending `X-Forwarded-For`, `X-Real-IP`, or `CF-Connecting-IP` directly.

To enable forwarded client IPs, an installation must explicitly configure both:

```php
'security' => [
    'trusted_proxies' => [
        '203.0.113.10',
        '2001:db8:100::/48',
    ],
    'trusted_proxy_headers' => [
        'x-forwarded-for',
    ],
],
```

Only exact IPs and CIDR ranges are accepted as trusted peers. `X-Forwarded-For` is walked from the directly connected side toward the client and stops at the first untrusted hop. Single-IP headers are accepted only when the direct peer itself is trusted and that exact header family was enabled.

Do not add broad Internet ranges to `trusted_proxies`. Configure only infrastructure that is actually controlled/trusted by the installation operator.

Phase 0 does not replace `visitor_hash()` or the existing administrator throttle IP behavior. That preserves current behavior. Future viewer throttling uses `request_client_ip()`.

## Abuse-control model

CAPTCHA and MFA are not the primary Phase 0 anti-abuse boundary. The prepared model combines multiple independent server-side subjects and hard resource caps.

Allowlisted future rate-limit buckets cover:

- registration by client IP, subnet, identifier, and global circuit breaker;
- login by client IP, identifier, account, and global circuit breaker;
- email verification/resend;
- password-reset request and attempt;
- share creation;
- global abuse pressure.

The precise policies remain internal and can be tuned before routes are enabled. Attackers cannot submit arbitrary bucket names to create unlimited bucket rows.

Subjects support:

- normalized client IP;
- network prefix (`/24` for IPv4, `/64` for IPv6) where appropriate;
- normalized identifier;
- account id;
- global constant subject.

The design supports both targeted and distributed attacks. For example, password spraying can be constrained by source/subnet/global limits even when each target identifier is attempted only once; credential stuffing can be constrained by IP/global plus identifier/account buckets.

Rate-limit counters are enforcement data, not telemetry. They are server-side, indexed, and bounded. Cleanup is not performed on every normal page request.

## Enumeration resistance

No public registration/login/reset endpoint exists yet. Future endpoint contracts should be designed so unauthenticated callers cannot distinguish unnecessarily between:

- unknown email;
- known pending account;
- active account;
- suspended/disabled account;
- reset message accepted for delivery versus no account eligible for delivery.

The externally visible request response should normally be generic. Internal decisions and low-risk security events may be more specific, without logging raw credentials/tokens or unnecessary personal data.

Do not add fake sleeps as an architectural substitute for generic behavior. If later measurements reveal a meaningful timing oracle, address the concrete path with bounded work and tests.

## Email sending boundary

The repository already contains administrator password-reset mail and SMTP/PHP-mail transport code in `app/controllers/admin_auth.php`. Phase 0.5 intentionally leaves that implementation untouched.

`app/services/viewer_mail.php` now defines the viewer-specific **authorization boundary before delivery**, but not delivery itself. Future viewer verification/reset/invitation endpoints must call `viewer_mail_authorize_send()` and must not invoke transport unless it returns `allowed=true`.

The prepared verification/reset plans enforce independent:

1. normalized-email limits;
2. per-address cooldowns;
3. hourly and daily recipient budgets;
4. exact trusted-client IP limits;
5. coarse `/24` IPv4 or `/64` IPv6 subnet limits;
6. installation-wide daily circuit breakers reserved last;
7. generic unauthenticated public result codes;
8. fail-closed behavior when a trustworthy IP or limiter persistence is unavailable.

Invitation delivery has separate recipient/global budgets because it is intended to be administrator-triggered rather than an anonymous public endpoint.

The existing administrator transport should only be extracted into a neutral low-level service in a separate regression-focused change if that extraction can preserve its settings, translations, SMTP diagnostics, and behavior mechanically. Phase 0.5 does not make viewer code depend on the administrator controller and does not add a second SMTP implementation.

The verification/reset token services already replace or invalidate older live tokens for durable accounts. Pending registration verification independently rotates `viewer_registration_requests.verification_token_hash` when a request is refreshed.

## Quotas and resource boundaries

Phase 0 through Phase 0.7 prepare bounded configuration values, with authentication-related limits now enforced internally. Phase 0.7 centralizes the future content mutation contract in `viewer_content_quota_config()`:

- `max_viewer_favourites_per_account` default 5000;
- `max_viewer_collections_per_account` default 25;
- `max_viewer_items_per_collection` default 500;
- `max_active_viewer_collection_shares_per_collection` default 1;
- viewer rate-limit subjects per fixed bucket;
- pending registration rows per installation;
- durable viewer accounts per installation (`max_viewer_accounts`, default 250);
- active viewer sessions per account (default 10);
- active viewer remember credentials per account (default 10);
- registration requests per installation/day;
- verification/reset/invitation email reservations.

The pending-registration row quota **is** enforced now at the service/database boundary because anonymous staged state would otherwise be an immediate database-amplification primitive once a later route is added. Admission is serialized through `viewer_registration_state`.

Email resend/reset/invitation/email-change mail-intent quotas are represented by the rate-limit policy model rather than persistent account columns. Content quotas are security/resource boundaries, not UI suggestions. Their configuration parser accepts only explicit integers or strict decimal integer strings, applies hard lower/upper bounds, and falls back to conservative defaults for malformed input. There is no unlimited sentinel. Content quotas are not enforced yet because no viewer content mutation service or route exists. A future mutation must enforce its applicable quota inside the same atomic ownership mutation transaction in addition to database uniqueness constraints. Phase 0.7 deliberately does not add speculative content counter tables.

## Object authorization and IDOR/BOLA rules for later phases

No viewer CRUD endpoint exists in Phase 0. Future services/controllers must use ownership-constrained operations. An object id by itself is never authorization.

Preferred mutation shape:

```sql
UPDATE viewer_collections
SET title = ?, updated_at = ?
WHERE id = ?
  AND viewer_account_id = ?
```

The service should distinguish only the information the caller is allowed to know. Similar owner constraints apply to delete, reorder, share-token issuance/revocation, and collection membership changes.

A future collection read has two separate decisions:

1. may this viewer/share capability access the collection container?
2. may this requester access each referenced source image under canonical gallery/media authorization now?

Passing decision 1 can never imply decision 2.

Phase 0.7 makes decision 2 concrete through `viewer_source_image_can_reference()` and `viewer_source_image_can_render_reference()`. Both resolve the canonical `images` row directly from authoritative storage, refresh the source gallery, require the source image to remain public, and evaluate gallery password/share/session and NSFW request grants through `visitor_can_access_gallery_without_admin_bypass()`. That helper deliberately reproduces the existing non-admin branch of `visitor_can_access_gallery()` but never consults `current_user()`. This is essential because a future viewer identity must not acquire the historical administrator bypass, and an administrator-created collection reference must not transfer administrator access to another requester. Denied resolution returns only inaccessible/null state, never the protected title, filename, filesystem path, EXIF, or gallery metadata.

The authorization decision is recomputed for the current request every time. A password-unlocked gallery can therefore be referenced only while the current request/session holds the existing gallery grant, and the stored favourite/collection row cannot preserve that grant for a later request. The same applies if a gallery later becomes protected/private or an image becomes hidden/deleted.

## Viewer plain-text metadata policy

`viewer_plain_text_validate()` validates future viewer-controlled labels without parsing or transforming them. The first field policy, `viewer_collection_title_policy()`, permits ordinary Unicode/spaces with a maximum of 120 Unicode code points and 480 UTF-8 bytes. Validation requires valid UTF-8, rejects NUL and ASCII control characters, and rejects Unicode bidi/format controls U+061C, U+200E/U+200F, U+202A through U+202E, and U+2066 through U+2069 to reduce misleading admin/log presentation. Input is rejected rather than truncated.

The validator does not parse HTML, Markdown, URLs, or perform output escaping. Text such as `<script>` is still plain stored text. Existing contextual rendering helpers such as `e()` remain responsible for output escaping. No Unicode normalization is claimed or required, and `intl` is not made a dependency.

## Concurrency and database integrity

Database constraints protect invariants even if two PHP requests race:

- unique `viewer_accounts.normalized_email`;
- unique one-time token hashes;
- unique remember-token selectors;
- unique server-side viewer-session hashes;
- composite favourite primary key `(viewer_account_id, image_id)`;
- composite collection-item primary key `(viewer_collection_id, image_id)`;
- unique collection share-token hashes;
- unique passkey credential-id hashes;
- composite rate-limit primary key `(bucket, subject_hash)`.

One-time token consumption takes a database row lock and updates only an unconsumed/uninvalidated row. Viewer rate-limit bucket row admission is serialized before adding a new attacker-controlled subject.

A future content service must handle duplicate-key/quota conflicts deliberately rather than relying on `SELECT` followed by `INSERT` as its only integrity check.

Phase 0.7 includes `tests/viewer_phase07_mysql_concurrency_test.php`, an optional real MySQL/MariaDB integration harness. It is outside the ordinary database-free unit suite and requires `pdo_mysql` plus `GALLERY_TEST_MYSQL_DSN`, with optional `GALLERY_TEST_MYSQL_USER` / `GALLERY_TEST_MYSQL_PASSWORD`. Workers use separate PHP processes and separate PDO connections and are released through an explicit pipe barrier instead of relying on sleeps as the only synchronization mechanism. The harness exercises duplicate activation, durable-account hard-cap admission, active-session cap, remember rotation, reset-token final use, security-version invalidation competing with authentication authority, and deletion/capacity-counter consistency. Missing driver/configuration/migrated storage produces an explicit `SKIP`; it must never be described as a successful live race run.

## Cleanup and retention

`viewer_security_maintenance_cleanup()` is wired into the existing scheduled site-maintenance service independently of the viewer feature flag. This is deliberate: disabling viewer capability must not retain expired security or personal data indefinitely. Cleanup still requires the relevant viewer schema to be **confirmed available** through three-state schema inspection; missing or unknown viewer schema returns a bounded `storage=unavailable` maintenance result and does not break unrelated gallery maintenance.

Cleanup uses bounded delete batches and indexed expiry/retention fields. It does not enable registration, authentication, or any viewer mutation. It covers:

- expired/old durable-account verification tokens;
- expired/old reset tokens;
- expired/revoked remember tokens;
- expired/revoked viewer sessions;
- expired/revoked collection share tokens;
- security events past `retention_until`;
- stale viewer rate-limit subjects plus bucket-count reconciliation;
- expired pending registration requests;
- expired/revoked/old claimed invitations;
- expired/consumed/cancelled email-change requests;
- registration-capacity counter reconciliation after staged-row cleanup;
- durable-account capacity counter reconciliation from authoritative `viewer_accounts` state.

`viewer_registration_maintenance_cleanup()` uses the same scheduled maintenance path and does not run on every normal page request.

Content rows such as favourites and collections are not age-expired by the security cleanup. Their retention belongs to account/content lifecycle policy.

## Phase 0.6 authentication and request-security boundary

Phase 0.6 finishes the route-free transitions needed before an invite-only HTTP flow can exist. No new controller calls these functions, no viewer cookie is emitted, and no viewer email is sent.

### Aggregate schema capability and fail-closed behavior

`viewer_auth_schema_status()` combines the required authentication/security tables through the repository's three-state schema inspection model. Only **confirmed available** storage permits viewer authentication operations. Confirmed missing and inspection-error/unknown states both fail closed. Arbitrary PDO errors are not interpreted as an old schema.

This does not turn viewer schema into a public-gallery dependency. `current_viewer()` checks local `viewer_auth` state before schema inspection, catches storage failures, clears only invalid viewer-local authority, and returns `null`. Ordinary anonymous requests therefore do not query dormant viewer tables merely to determine identity or cache behavior.

### Verified staging -> activation grant -> durable account

The activation path is intentionally split into three authorities:

```text
non-consuming verification inspection
        |
        | no session authority
        v
explicit verification confirmation
        |
        | rotates PHP session id
        v
$_SESSION['viewer_registration_activation']
        |
        | short-lived HMAC-bound request id/context only
        v
viewer_registration_activate_verified(password)
        |
        | one transaction, authoritative row locks
        v
viewer_accounts(status=active, security_version=1)
```

A verification scanner can call the inspection primitive without consuming the token or receiving activation authority. Explicit confirmation consumes the verification transition and creates only short-lived pre-authentication state. That state is separate from `viewer_auth`, `user_id`, viewer CSRF, and administrator CSRF. It stores no plaintext verification token and no plaintext email.

`viewer_registration_activate_verified()` accepts the password only. It does **not** accept a registration request id from GET/POST as authorization. Inside one transaction it locks/re-reads the staged request, validates `email_verified` state, verification/expiry/cancellation state, re-locks and re-validates any invitation, checks durable normalized-email uniqueness, locks the global durable-account capacity row, validates/hashes the password, inserts exactly one active account, retires the staging row, repairs both counters, and writes the activation security event. Database uniqueness remains the final duplicate-email backstop. Activation does not automatically log the viewer in.

`viewer_account_state` is a small singleton-capacity table. `account_count` counts **all durable viewer-account rows**, not merely status=`active` rows. Account creation is serialized with `SELECT ... FOR UPDATE`; the count can be reconciled from `SELECT COUNT(*) FROM viewer_accounts` under the same lock. The conservative default hard cap is 250.

### Viewer password policy

Password-only viewer accounts use only PHP native password APIs: `password_hash()`, `password_verify()`, and `password_needs_rehash()`. Argon2id remains preferred when the runtime provides it; otherwise `PASSWORD_DEFAULT` is used with the existing explicit bcrypt input boundary.

The policy is:

- minimum 15 Unicode code points for activation/reset and eligible password-only login;
- spaces and paste are allowed;
- valid Unicode is allowed;
- no upper/lower/digit/symbol composition rules;
- no periodic expiry;
- no silent truncation;
- invalid UTF-8 and NUL are rejected;
- maximum input is 4096 bytes for non-bcrypt native algorithms and 72 bytes when the selected native algorithm is bcrypt, because bcrypt's byte boundary must never be crossed silently.

The byte maximum is a DoS/no-truncation boundary, while the 15-character minimum is measured in Unicode code points. Native algorithm defaults are retained rather than selecting more aggressive shared-hosting costs without evidence.

### Password login ordering and generic results

`viewer_authenticate_password()` is an internal service, not a controller. Its security-relevant order is fixed:

1. viewer feature enabled;
2. viewer authentication schema confirmed available;
3. strict viewer HTTPS transport accepted;
4. input normalization/byte safety;
5. exact trusted client-IP, subnet, normalized-identifier, and installation-global rate-limit admission;
6. account lookup;
7. native password verification (with a precomputed dummy native hash for unknown accounts);
8. active/verified/account eligibility check;
9. optional native password rehash;
10. `last_login_at` update;
11. normal viewer-session establishment;
12. structured security event.

The expensive password verification path is therefore behind low-cost rate limits. There is no hard account lock after N failures. Unknown email, wrong password, suspended/disabled/ineligible state all map to the same future-public authentication failure code; richer `reason` values are internal diagnostics only.

### Separate viewer CSRF authority

Viewer mutation authority uses `$_SESSION['viewer_csrf_token']` via `viewer_csrf_token()` / `viewer_csrf_verify()`. It is independently generated with 256 bits of randomness and compared with `hash_equals()`. Existing administrator `csrf_token()` / `verify_csrf()` and their `csrf_token` session key are unchanged. Neither token validates in the other domain.

### Viewer sessions and resource limits

`viewer_session_establish()` locks the authoritative account, requires status `active`, verified email, password authority, and the expected `security_version`, removes bounded inactive history, enforces the active-session cap, inserts hashed server-side session authority, rotates the PHP session id, and writes only `$_SESSION['viewer_auth']`. Existing administrator session keys survive the rotation.

The active-session default is 10 per account. Enforcement is serialized by the viewer-account row lock and revokes oldest active rows deterministically by `(created_at, id)` before inserting a replacement. Expired/revoked rows do not count. `current_viewer()` revalidates account state, row revocation/expiry, and both account/session security versions. Invalid local viewer state is cleared without clearing administrator state.

`viewer_session_revoke_current()` removes only current viewer authority. `viewer_session_revoke_all()` uses security-version invalidation for the account and does not alter administrator identity.

### Persistent viewer login rotation

Remember credentials remain selector/verifier pairs with only the verifier hash persisted. The per-account active remember-token default is 10, enforced under the same account-row serialization pattern with deterministic oldest-token revocation.

`viewer_remember_restore_and_rotate()` locks the account and remember row, verifies expiry/revocation/account state/security version/verifier hash, generates a fresh selector and verifier, replaces the old secret atomically, updates `last_used_at`, then establishes a normal viewer session. The old selector/verifier no longer matches after commit. No `setcookie()` call exists in Phase 0.6. `viewer_remember_cookie_contract()` only describes the future separate `HttpOnly`, `Secure`, `SameSite=Lax` viewer cookie contract.

### Scanner-safe password reset

Password reset is a separate internal state machine:

```text
reset request admission
  -> IP/subnet/identifier/global abuse limits
  -> account lookup
  -> viewer_mail_authorize_send()
  -> eligible account receives hashed reset-token row internally

GET-style reset token inspection
  -> read only, non-consuming

explicit reset authorization
  -> rotates PHP session id
  -> $_SESSION['viewer_password_reset']

final reset
  -> locks account + token
  -> validates token/security_version/password policy
  -> updates password/password_changed_at
  -> increments security_version
  -> consumes used token + invalidates siblings
  -> revokes all viewer sessions + remember credentials
  -> clears reset state
```

The reset pre-authentication namespace does not authenticate the viewer. A link scanner performing inspection cannot reset the password or consume the final transition. Successful reset does not automatically log in the viewer.

### Account state and security-version invalidation

Internal account-state transitions cover active, suspended, disabled, and restoration to active. Suspension/disable/restore rotates `security_version` and revokes all existing viewer sessions, remember credentials, outstanding reset tokens, pending durable-account email-verification tokens, and collection share capabilities created by that viewer. Restoration validates that durable password/email-verification authority exists and never resurrects revoked credentials or share tokens.

The status enum remains the lifecycle model. No parallel collection of unrelated authentication booleans is introduced.

### Strict viewer HTTPS and trusted proxy protocol

The historical generic `request_is_https()` is intentionally unchanged for administrator/reverse-proxy compatibility. Viewer security uses `viewer_request_is_https()` / `viewer_security_transport_allowed()` instead.

Direct HTTPS and direct server port 443 are accepted. Forwarded `X-Forwarded-Proto` or `X-Forwarded-SSL` is ignored unless the direct `REMOTE_ADDR` matches an explicitly configured trusted proxy **and** that protocol-header family appears in the separate `security.trusted_proxy_protocol_headers` setting. Invalid CIDRs are ignored, malformed/comma-ambiguous/conflicting protocol values fail closed, and IPv4/IPv6 proxy CIDRs use the same validated matcher as trusted client-IP resolution. `viewer_accounts.require_https` defaults true.

### Trusted security-link origin

Future viewer verification/reset/invitation URLs must call `viewer_security_base_url()` / `viewer_security_url()`. Authority comes only from configured `base_url`; `HTTP_HOST` is never a fallback. The configured origin must be an absolute normal HTTP/HTTPS URL without userinfo, query, or fragment. When viewer HTTPS is required, the configured origin must be HTTPS. Empty or malformed `base_url` causes future viewer security-link creation to fail closed rather than trusting request Host data.

### Cache/privacy boundary

`send_security_headers()` now treats the presence of `viewer_auth`, `viewer_registration_activation`, `viewer_password_reset`, or `viewer_csrf_token` session state as sensitive. Such responses follow the existing private/no-store branch even before any viewer HTML exists. This is a session-presence check only; no viewer database lookup is performed to decide cache policy. Historical anonymous gallery cache behavior and administrator cache behavior otherwise remain unchanged.

### Security-event transaction semantics

Authority-changing successes emit structured viewer events inside the same database transaction where practical. If event insertion fails during such a transaction, the security transition is rolled back rather than committed partially. Failure-only diagnostics use a best-effort wrapper because inability to log a denial must never turn that denial into authorization. Event context remains allowlisted and excludes passwords, hashes, tokens, session ids, CSRF values, full URLs, and raw email.

## Phase 0.7 final lifecycle and content-authorization boundary

Phase 0.7 is the final invisible foundation phase. It adds no HTTP route, controller, view, form, browser-visible link/button, JavaScript, CSS, email transport, favourite mutation, collection mutation, or collection-share consumption flow.

### Recent reauthentication

Sensitive future viewer operations must require more than possession of an ordinary viewer session. `viewer_reauthentication_status()` validates the short-lived server-side recent-auth record against `current_viewer()`, the current account `security_version`, and the current server-side viewer-session id. `viewer_recent_reauthentication_required()` exposes a fail-closed boolean boundary. `viewer_reauthenticate_password()` first reuses the established viewer login IP/subnet/identifier/global abuse-control budgets, then performs explicit current-password verification using the existing native password helper and refreshes the authority. Limiter uncertainty fails closed. Successful interactive password login may establish recent auth. Remember restoration cannot.

The configured lifetime defaults to 15 minutes and is strictly bounded to 5 through 30 minutes. No password, hash, session secret, CSRF secret, or complete security URL is logged or stored in recent-auth state.

### Internal password change

`viewer_change_password()` is route-free. It requires viewer capability/storage/transport, an active authenticated viewer, recent explicit credential proof (or performs current-password reauthentication itself), and the existing Phase 0.6 password policy. Inside one transaction it locks/re-reads the viewer account, requires the expected `security_version`, writes only a native password hash, updates `password_changed_at`, increments `security_version`, invalidates password-reset and verification/email-change authority, and revokes all viewer sessions and remember credentials. Successful commit clears local viewer authority, so a normal login is required again. The success event contains identifiers/reason metadata only.

### Staged verified email change

Migration `202608180004_viewer_account_lifecycle_foundations.php` adds `viewer_email_change_requests`. Starting a change requires an active viewer session plus recent reauthentication, applies the same email normalization/size policy as registration/login, performs account-email uniqueness checks, reserves the existing mail-abuse budget under the `email_change` action, supersedes prior pending requests under the account lock, generates high-entropy verification authority, and stores only its hash. The internal caller receives the secret for a future transport layer; Phase 0.7 does not send it.

`viewer_email_change_request_inspect()` is scanner-safe and non-consuming. A token that passes inspection does not alter the account email. `viewer_email_change_authorize()` converts explicit secret proof into separate short-lived HMAC-bound `viewer_email_change_confirmation` PHP-session state. No function accepts a client-supplied request id alone as final authority.

`viewer_email_change_confirm()` requires the active viewer, recent reauthentication, and valid confirmation state. The final transaction locks account and staged request, re-checks account/request/security version/expiry/cancellation/consumption and target uniqueness, updates the verified and normalized account email plus `email_verified_at`, increments `security_version`, consumes/cancels staged requests, invalidates reset/verification authority, and revokes sessions/remember credentials. The existing unique `viewer_accounts.normalized_email` index remains the structural race barrier. Success clears email-change, recent-auth, and viewer session authority. No notification/verification email transport is added.

### Physical account deletion and durable capacity

`viewer_account_delete()` uses the existing physical-deletion model rather than adding a new soft-delete state. It requires authoritative active viewer identity and recent reauthentication, locks the account and `viewer_account_state`, verifies `security_version`, invalidates security authority, explicitly revokes any still-active collection-share capability created by the viewer, and deletes the account. Existing foreign keys cascade account-owned sessions, remember/reset/verification/email-change tokens, favourites, collections/items/shares, and passkeys. Security events remain only as pseudonymous rows because their account relation uses `ON DELETE SET NULL`.

The same deletion transaction recounts authoritative durable viewer rows and writes `viewer_account_state.account_count` before commit. A rollback therefore cannot leave the durable-account counter decremented while the account remains. Old session/remember/reset/share authority cannot recreate access after commit.

### Lifecycle schema capability

The Phase 0.7 lifecycle feature participates in the existing `available` / `missing` / `unknown` schema inspection model. Missing or indeterminate lifecycle storage denies password/email/deletion mutations. Public gallery browsing and administrator authentication do not depend on that capability and therefore remain available if dormant viewer schema is absent or broken.

### Security events

Phase 0.7 emits or prepares secret-free keys including `viewer.reauthentication_success`, `viewer.reauthentication_failure`, `viewer.password_changed`, `viewer.email_change_requested`, `viewer.email_change_confirmed`, and `viewer.account_deleted`. Ordinary event context excludes old/new raw email, passwords/hashes, verification/reset/remember secrets, raw PHP session ids, CSRF authority, and complete URLs.

## Personal data inventory

New Phase 0 through Phase 0.7 personal/security data is deliberately minimal:

| Table | Personal/security data |
| --- | --- |
| `viewer_accounts` | Email address, credential hash, account state, security/activity timestamps. |
| `viewer_registration_requests` | Ephemeral candidate email, normalized comparison value, keyed email/IP fingerprints, hashed verification capability, send counters, lifecycle timestamps. No password. |
| `viewer_invitations` | Hashed invitation capability, optional administrator-visible intended email, keyed target-email fingerprint used for authorization binding, optional administrator creator relation, lifecycle timestamps. |
| `viewer_email_change_requests` | Ephemeral new email and normalized comparison value, random selector, hashed verification capability, security-version binding, expiry/use/cancellation state. No password. |
| verification/reset tokens | Account relation, hashed capability, expiry/use state; verification also has keyed email fingerprint. |
| `viewer_remember_tokens` | Account relation, hashed verifier, keyed UA fingerprint, lifecycle timestamps. |
| `viewer_sessions` | Account relation, hashed session authority, keyed IP/UA fingerprints, lifecycle timestamps. |
| `viewer_security_events` | Optional account relation, keyed IP/UA fingerprints, bounded event metadata. |
| favourites/collections/items | Account-owned preference/content references and plain-text collection metadata. |
| collection share tokens | Collection/account relation and hashed capability lifecycle. |
| passkeys | Public credential material and device/authenticator metadata, never a private key. |
| rate-limit tables | Keyed hashes of normalized abuse-control subjects, counters, timing data. |

Pending registration email and the Phase 0.7 staged candidate replacement email are the only new plaintext personal fields outside the durable viewer account and are deliberately ephemeral with indexed expiry cleanup. The migrations do not collect real names, phone numbers, addresses, birth dates, gender, biographies, avatars, or public usernames.

## HTTP security review

Existing browser defenses include:

- `HttpOnly` PHP session cookie;
- `SameSite=Lax` session cookie;
- `Secure` session cookie when HTTPS is detected;
- `X-Frame-Options: SAMEORIGIN`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `Cache-Control: no-store` for authenticated/non-public-cache responses;
- explicit public-cache handling for known anonymous routes.

No strict Content-Security-Policy was added. The current gallery uses inline/external assets and feature integrations that require a compatibility inventory before a restrictive CSP can be introduced safely. Adding a CSP without that exercise could break maps, scripts, styles, images, or admin tools.

Future authenticated viewer pages and container-share pages must be reviewed for cache behavior. Private/authenticated viewer responses should remain `no-store`. If a future public share route is cacheable, its cache key must include every authorization/visibility dimension or, preferably initially, remain non-cacheable until proven safe.

## Existing administrator throttle note

The current administrator `auth_rate_limits` implementation is privacy-conscious and cleans old rows, but its failed-attempt update is `SELECT` followed by `UPDATE` without row serialization. Concurrent failures can therefore undercount. Also, randomized submitted identifiers can create distinct rows until cleanup runs, because there is no hard per-bucket subject cap.

Phase 0 does not change this existing administrator behavior because the primary compatibility rule forbids an unrelated authentication rewrite. The new viewer throttle foundation addresses both concerns independently. Administrator throttle hardening should be a separate, narrowly tested security patch if undertaken.

## Existing administrator reset-token note

The existing administrator password-reset workflow predates the viewer foundation. Its token read/use/password-change sequence is not the same row-locking single-use primitive introduced for future viewers. Reusing the new viewer token tables for admins would blur identity domains and is not appropriate. If the administrator reset race window is hardened later, it should be done in the existing admin domain with regression tests and no viewer dependency.

## Threat-model mapping

1. **Automated account creation:** no public registration exists, but the staged service already combines invite/open policy, IP/subnet/identifier/global rate limits, unique-email deduplication, and a hard pending-row cap. Durable account activation now exists only as a route-free, session-bound transactional service behind verified staging and a hard account cap.
2. **Credential stuffing:** viewer login policies support IP, account/identifier, subnet, and global controls; persistent sessions are revocable/versioned.
3. **Password spraying:** distributed/target-spread attempts can be constrained by source/subnet/global buckets in addition to per-account limits.
4. **Brute-force login:** fixed policies, hashed subjects, server-side counters, password hashing, account state, and session invalidation primitives are prepared.
5. **Account enumeration:** no endpoint exists; future APIs are documented to return generic unauthenticated responses.
6. **Email bombing:** no viewer mail transport exists; the mandatory pre-delivery authorization boundary now enforces layered recipient/client/global budgets and cooldowns before any future transport may run.
7. **Password-reset abuse:** reset tokens are high-entropy, hashed, expiring, version-bound, invalidatable, single-use; future request endpoints must rate limit before sending.
8. **Verification-token guessing:** pending registration and durable-account verification use 256-bit default random authority, hashed storage, expiry, and single-use semantics.
9. **Reset-token database theft:** database contains only fast hashes of 256-bit random authority, not plaintext reset capability.
10. **Session fixation:** viewer session establishment rotates the PHP session id before storing viewer state.
11. **Session theft:** server-side hashed viewer session authority supports expiry/revocation and security-version invalidation; HTTPS remains operationally required for secure transport.
12. **Persistent-login token theft:** selector/verifier model stores only verifier hash, binds security version, supports revoke/expiry; successful internal restoration rotates selector/verifier authority atomically before establishing a normal viewer session.
13. **CSRF:** viewer mutations have a separate random/hash-equal CSRF namespace; administrator CSRF authority is not reused.
14. **Stored XSS in collection metadata:** schema stores bounded plain text only; no HTML/Markdown rendering is introduced; future output must escape.
15. **Reflected XSS:** no viewer route exists; future responses must use existing escaping/response helpers and not reflect raw identifiers/tokens.
16. **IDOR/BOLA:** owner ids are explicit in schema; future service mutations must constrain queries by viewer owner and not authorize by object id alone.
17. **Unauthorized collection modification:** future writes require authenticated viewer plus ownership-constrained SQL and CSRF.
18. **Sharing-token guessing:** future collection tokens use high-entropy opaque random authority with unique hashed storage.
19. **Share-token leakage:** raw token is never stored or logged; security event context excludes URLs/tokens; tokens are revocable/expirable/rotatable.
20. **Access after gallery permissions change:** collection/favourite rows contain references only; canonical source access must be re-evaluated at request time.
21. **Private media through shared collection:** container share authority is structurally separate from gallery/media share authorization and cannot carry image permissions.
22. **Fake client-IP proxy headers:** forwarded headers are ignored unless the direct peer and header family are explicitly trusted.
23. **Rate-limit table exhaustion:** fixed buckets plus serialized hard subject caps bound attacker-controlled row admission; stale rows are reclaimed out of band.
24. **Database row/resource exhaustion:** centralized content quota configuration exists; Phase 1.1 favourite adds serialize on the viewer account row before enforcing the bounded per-account favourite cap, pending registration has a serialized hard row cap, durable account creation has a separate serialized installation cap, and active session/remember rows have per-account caps.
25. **Race conditions:** database unique constraints, invitation/verification/account/token row locks, serialized pending/durable-account admission, deterministic session/token caps, serialized rate-limit bucket admission, security-version checks, and deletion-time capacity locking protect Phase 0 through Phase 0.7 invariants. The optional real-DB harness can exercise the strongest InnoDB guarantees rather than treating static SQL review as live concurrency.
26. **Account suspension bypass:** only `active` accounts may establish/retain viewer sessions; remember-token verification also checks current account state.
27. **Session survival after password reset:** security-version increment plus explicit session/remember/reset revocation is the implemented internal invalidation boundary.
28. **Security-event secret leakage:** event keys/context are bounded and allowlisted; token/password/email/url fields are not accepted; IP/UA are keyed fingerprints.
29. **Cache leakage of authenticated/private content:** viewer/auth pre-auth session presence forces the existing `no-store` branch without a viewer DB query; the private favourites page and viewer mutation responses preserve that boundary while anonymous galleries remain viewer-independent.
30. **Admin/viewer identity confusion:** separate tables, session namespace, services, and regression tests ensure `current_user()` remains admin-only and gallery admin bypass never checks `current_viewer()`.
31. **Stolen long-lived session used for account takeover:** password/email/delete transitions require separate recent credential proof bound to current session/account/security version; remember restoration does not satisfy it.
32. **Email-change scanner/replay:** token inspection is non-consuming, final authority is separate server-side confirmation state, the request is version-bound/single-use/cancellable, and the account row plus unique normalized email constraint are re-checked under transaction.
33. **Account deletion capability residue:** deletion revokes cross-owned viewer-created collection shares and cascades account-owned auth/content authority while reconciling the durable-account counter transactionally.
34. **Protected media through viewer references:** canonical Phase 0.7 source-image resolution deliberately omits the administrator bypass and re-evaluates current gallery/password/share/NSFW authority without returning metadata on denial.
35. **Misleading viewer-controlled labels:** future plain-text metadata requires valid bounded UTF-8 and rejects unsafe ASCII/bidi controls; HTML escaping remains an output responsibility.

## Phase 0/0.5/0.6/0.7 tests

Focused tests are:

```text
php tests/viewer_security_foundations_test.php
php tests/viewer_schema_foundations_test.php
php tests/viewer_identity_boundary_test.php
php tests/viewer_registration_foundations_test.php
php tests/viewer_mail_abuse_foundations_test.php
php tests/viewer_authentication_phase06_test.php
php tests/viewer_account_lifecycle_phase07_test.php
php tests/viewer_phase07_mysql_concurrency_test.php  # optional real MySQL/MariaDB; explicitly SKIPs when unavailable
```

They verify disabled defaults, service-level fail-closed behavior, principal separation, canonical gallery-access boundary preservation, admin/share/CSRF wiring preservation, token generation/hashing, password helpers, expiry/single-use behavior, email normalization, proxy spoof resistance, rate-limit normalization/storage caps, event redaction, additive migration definitions, foreign keys, uniqueness, collection permission non-storage, pending-email deduplication, hashed and email-bound invitations, scanner-safe non-consuming verification inspection, explicit single-use confirmation, hard staged-row capacity, generic future public results, independent mail budgets, the continued absence of open signup, and the Phase 0 services remaining transport-independent.

The Phase 0.6 test additionally covers three-state aggregate auth schema, 15-character/native password policy, viewer/admin CSRF separation, activation/reset pre-auth state, forged/trusted forwarded HTTPS behavior, IPv4/IPv6 proxy matching, Host-header poisoning resistance, cache-state contracts, account/session/remember/reset transaction structure, hard-cap serialization structure, cleanup while disabled, and the authentication/token services remaining free of direct HTTP cookie emission or mail transport.

The Phase 0.7 test covers recent-auth separation/expiry/clearing, interactive-login versus remember semantics, password/email/deletion transaction contracts, scanner-safe email-change inspection and server-side confirmation authority, lifecycle schema capability, deletion/counter/share cleanup, canonical no-admin-bypass source-image authorization, deterministic UTF-8/plain-text policy, strict centralized content quotas, migration ordering, and preservation of the Phase 0.7 services as the authoritative route-independent lifecycle/content foundation even after later HTTP controllers are added. The optional MySQL/MariaDB harness runs the seven Phase 0.7 storage races only when a compatible driver, DSN, and migrated database are present; otherwise it reports the exact reason as `SKIP`.

The normal repository migration consistency/schema policy tests remain part of full release verification. Static/model checks must never be presented as equivalent to live InnoDB race execution.

## Phase 1.0 reachable HTTP boundary

Phase 1.0 now exposes the smallest invite-only viewer account vertical slice. The reachable surface is limited to administrator invitation create/list/revoke, invitation acceptance, scanner-safe email verification and activation, viewer login/logout, optional remember-me, forgotten-password/reset, and one minimal private viewer account page.

The controllers do not redesign the foundations. They call the existing Phase 0 through 0.7 services for invitation authority, staged registration, email normalization, mail-abuse budgets, atomic activation, password policy/hash verification, viewer sessions, remember-token rotation, reset transitions, security-version invalidation, trusted HTTPS/origin handling, viewer CSRF, rate limiting, and security events.

The public trust boundary remains scanner-safe:

```text
invitation GET -> inspect only -> acceptance POST
verification GET -> inspect only -> confirmation POST -> server-side activation authority -> password POST -> atomic activation
reset GET -> inspect only -> confirmation POST -> server-side reset authority -> password POST -> atomic reset
```

GET does not irreversibly consume invitations, activate accounts, or reset passwords. Viewer/pre-auth responses are private/no-store and emit `Referrer-Policy: no-referrer`. Dedicated `viewer_*` routes bypass the generic public SEO query-string guard so bearer parameters are validated only by their security flow and are not sampled into the SEO guard's `REQUEST_URI` security log. The shared public header also suppresses its administrator-login `return` parameter on viewer security routes, preventing a complete bearer URL from being copied into ordinary navigation. The dedicated viewer remember cookie is restored before cache classification and never establishes recent reauthentication. Viewer logout revokes only viewer authority and therefore does not destroy a simultaneous administrator login.

Viewer security email delivery is now real but remains bounded. Verification mail is passed to the configured existing PHP-mail/SMTP transport only after `viewer_mail_authorize_send()` approves the viewer address/visitor/global budgets. Password-reset request authorization performs the same budget contract internally before a send-eligible reset token is returned. Security links are built only from trusted configured `base_url`; complete bearer URLs are not security-event context. Disabling viewer accounts clears local viewer session/pre-auth/CSRF/remember state during bootstrap so dormant viewer authority does not keep unrelated public gallery responses personalized or non-cacheable.

Most importantly, viewer authentication is still not gallery authorization. `current_user()` remains administrator-only, `current_viewer()` remains viewer-only, and existing gallery passwords, share grants, Smart Gallery authorization, NSFW rules, thumbnail/media authorization, and Admin persistence are unchanged.

## Intentionally unavailable after Phase 1.0

Phase 1.0 still does not implement:

- open viewer registration or a public Register/Sign up entry point;
- favourites or favourite APIs/UI;
- viewer collection CRUD, ordering, rendering, or collection sharing;
- public viewer profiles, discovery, usernames, avatars, biography, arbitrary links, or social identity;
- viewer comments or uploads;
- CAPTCHA/Turnstile;
- viewer OIDC/social login;
- viewer TOTP/MFA enrollment;
- WebAuthn/passkey ceremonies;
- magic-link login;
- Phase 0.7 change-password, staged email-change, or account-deletion HTTP UI in this first slice;
- device/session-management UI;
- any rule that grants gallery access merely because `current_viewer()` is non-null.

## Phase 1.1 reachable favourites boundary

Phase 1.1 now exposes viewer favourites as the first viewer-owned content feature. It deliberately reuses the existing `viewer_favourites` table and `images.id` identity. No new migration, permission snapshot, gallery access mode, or content ACL is introduced.

The write contract is:

```text
current_viewer()
    -> viewer CSRF POST
    -> viewer_source_image_can_reference(image_id)
    -> lock current viewer account row
    -> verify active account + security_version
    -> enforce max_viewer_favourites_per_account
    -> insert/delete only viewer_favourites reference
```

The source authorization check deliberately excludes the historical administrator bypass. A browser holding both Admin and viewer principals therefore does not gain favourite-write authority over an image merely because the Admin principal can see it. The stored favourite remains only a reference and never preserves access.

The private read contract is:

```text
owned viewer_favourites image_id
    -> viewer_source_image_can_render_reference(image_id)
    -> canonical authorized source resolver
    -> render metadata only when still independently authorized
```

Normal physical-gallery and Smart Gallery cards already passed their existing source authorization before Phase 1.1 decoration is added. Those pages receive only a batched boolean current-viewer favourite state. Lazy lightbox payloads receive the same optional boolean only after their existing gallery/NSFW authorization. `visitor_can_access_gallery()`, protected-gallery passwords, share grants, Smart Gallery membership authorization, thumbnails, originals, and media serving remain viewer-favourite unaware.

Favourite mutation is POST-only and uses the established viewer CSRF namespace. The browser module submits the same server-rendered form asynchronously and synchronizes card/lightbox representations; JavaScript is not an authority layer. Forms do not relay the current gallery URL through the mutation endpoint, so capability-bearing password/share URLs are not copied into viewer POST state. The no-JavaScript fallback goes to the private Favourites page.

The private favourites page and mutation responses remain private/no-store. Anonymous public gallery output contains no viewer favourite state. Optional favourite storage failures return no decoration instead of breaking ordinary public gallery rendering. The existing account foreign key/image foreign key cascades continue to clean stale references without affecting source media.

`tests/viewer_favourites_phase11_test.php` protects the Phase 1.1 ownership, CSRF, quota-locking, source-authorization, cache/degradation, route, lightbox, Admin/viewer coexistence, and scope boundaries. Live MySQL/MariaDB concurrency coverage remains optional and must still be reported as skipped when the driver/DSN is unavailable.

## Phase 1.2 reachable account lifecycle boundary

Phase 1.2 exposes only the dormant Phase 0.7 lifecycle transitions: recent viewer reauthentication, password change, staged verified-email change, and viewer self-deletion. `app/controllers/viewer_lifecycle.php` is deliberately separate from the Phase 1.0 authentication controller and contains no lifecycle SQL. It reuses the established viewer CSRF, strict HTTPS, no-store, security-origin, mail transport, and generic-error helpers.

Sensitive mutation routing is bounded by action identifiers, not URLs:

```text
password | email | email_confirm | delete
    -> viewer password confirmation when recent proof is missing
    -> internal allowlisted lifecycle destination only
```

Remember restoration never satisfies recent reauthentication. `viewer_reauthenticate_password()` retains the established viewer login rate limits and binds recent proof to the current viewer account, security version, and concrete viewer session. Reauthentication never writes the administrator `user_id` key.

Password change delegates to `viewer_change_password()`. The service remains responsible for password policy/hashing, account locking, one security-version increment, viewer session/remember/reset/email-change invalidation, security events, and the intentional viewer logout. The controller does not destroy the PHP session, so a simultaneous Admin principal remains valid. Favourites remain owned by the unchanged viewer account id.

Email change remains staged and scanner-safe:

```text
recent viewer auth
    -> candidate email request + viewer mail budget authorization
    -> verification mail to candidate address
    -> GET token inspection + bounded server-side confirmation authority
    -> tokenless viewer-CSRF POST
    -> atomic email/security-version transition exactly once
```

The verification GET never updates `viewer_accounts.email` and never consumes the final durable transition. The final service locks the account/request, rejects expired/cancelled/stale/replayed/conflicting state, atomically switches the verified login address, consumes one request, cancels siblings, invalidates viewer authentication authority according to Phase 0.7, and signs the viewer out. The mail URL is built from the trusted application security origin and the complete token URL is never added to security-event context.

Self-deletion requires an active viewer, recent viewer password proof, viewer CSRF, and explicit server-side destructive confirmation. `viewer_account_delete()` remains authoritative for security-version invalidation, viewer-share revocation, deletion security event, durable account removal, capacity reconciliation, and foreign-key cleanup. Viewer-owned favourites become inaccessible/deleted through the existing account cascade. Gallery images, gallery rows, Smart Galleries, public gallery share links, and administrator authentication are not viewer-owned and are not affected.

All Phase 1.2 lifecycle responses are private/no-store and inherit the viewer no-referrer/noindex security-page policy. Viewer feature disablement or unavailable lifecycle schema fails the route closed. Anonymous public gallery routing/rendering does not inspect lifecycle schema merely because the routes exist. No Phase 1.2 migration is needed; the Phase 0.7 lifecycle migration and services are reused.

`tests/viewer_account_lifecycle_phase12_test.php` protects the route/method/CSRF/reauthentication/no-store/scanner-safe boundaries, bounded destinations, service delegation, Admin/viewer coexistence, feature/schema fail-closed behavior, scope exclusions, and runtime import definitions. The existing Phase 0.7 service tests remain responsible for transaction and race invariants; live MySQL/MariaDB concurrency remains optional and must be reported as `SKIP` when unavailable.

## Phase 2.0 reachable private collection boundary

Phase 2.0 activates only private viewer-owned collections. It reuses the dormant `viewer_collections` and `viewer_collection_items` schema from `202608180001_viewer_security_foundations.php`; no new migration or ownership model is introduced. The dormant `viewer_collection_share_tokens` table remains unreachable.

`app/services/viewer_collections.php` is the authoritative private collection mutation boundary. The owner id always comes from the active viewer principal and every object operation uses an owner predicate equivalent to `collection id + viewer_account_id`. Collection creation and item admission use the existing configured quotas under row locks. The composite collection-item primary key remains the duplicate-race backstop. Reorder requests are bounded, reject malformed/duplicate/foreign image ids, lock the owned collection/items, and update integer positions in one transaction.

Collection items store only canonical image ids and ordering. Add checks the current no-admin-bypass source-image authorization before insertion, but that decision is not stored. Detail rendering reads stored references and applies `viewer_source_images_resolve_authorized()` every time. That batched resolver reloads current image/gallery state, calls `visitor_can_access_gallery_without_admin_bypass()`, applies NSFW authorization without Admin bypass, and returns only currently authorized rows. Inaccessible references remain stored and are omitted without source metadata. Collection membership therefore never becomes gallery or image authority.

A simultaneous Admin principal cannot widen the viewer collection. Physical gallery and Smart Gallery Add-to-collection controls are viewer-only, and when Admin authority may have widened the source page they independently recheck `viewer_source_image_can_reference()` before rendering the control. The mutation service repeats the same source check. Normal gallery/media authorization does not consult viewer collections.

All collection routes are private/no-store, viewer-CSRF-protected for mutations, and fail closed when the viewer feature or collection schema is unavailable. Anonymous public gallery rendering remains independent and performs no private collection lookup. Favourites remain independent references: adding/removing/deleting a collection does not change favourite state. Viewer password/email changes preserve collection ownership, and viewer account deletion continues to rely on the established account-owned foreign-key lifecycle.

`tests/viewer_collections_phase20_test.php` protects schema reuse, ownership/IDOR, method/CSRF, title/XSS policy, quota locking, duplicate handling, source authorization, Admin/viewer coexistence, transactional ordering, cache/privacy, runtime imports, and explicit Phase 3 scope exclusions.

## Pre-Phase 3 administrator-provisioned viewer accounts

Before Phase 3, the complete viewer subsystem is additionally wrapped by the canonical `viewer_accounts` Admin feature flag. This outer switch is disabled by default. While it is off, all current `viewer_*` routes and the historical Admin viewer-management route are centrally guarded, public disabled requests look like ordinary not-found requests, the Admin Viewer accounts navigation item is hidden, and the effective viewer mode is forced to `disabled` even if older configuration or `viewer_accounts_admin_mode` would otherwise enable invite-only login. No viewer rows, favourites, collections, or security history are deleted by the wrapper.

The existing administrator invitation workflow is no longer the only way to create a viewer identity. An authenticated administrator may now use **Account > Viewer accounts** to create a durable viewer row directly or delete an existing viewer. This is an operator capability only; it does not create a public registration endpoint and does not weaken the `current_user()` versus `current_viewer()` separation. Direct creation is intentionally available even while the viewer frontend switch is disabled so accounts can be staged, but viewer login remains unavailable until both the master viewer feature and the subordinate viewer frontend mode are enabled.

`202608180006_viewer_admin_account_management.php` adds `viewer_accounts.must_change_password` with a compatibility default of `0`. Direct Admin provisioning creates an `active`, verified viewer row using the same binary email uniqueness and locked `viewer_account_state` capacity enforcement as the normal durable account namespace, but sets `must_change_password=1`. The administrator may supply a password satisfying the existing viewer policy or leave it blank for cryptographic generation. Only the ordinary password hash is persisted. The plaintext temporary password is returned only for one post-redirect Admin display and is not logged or included in notification mail. Optional notification mail contains only the trusted login location and instructions to use the separately delivered temporary credential.

`must_change_password=1` is a restricted authentication state, not a normal viewer session. A correct temporary password passes credential proof but branches before `viewer_session_establish()`. The authentication service clears stale normal viewer authority, rotates the PHP session id while preserving any separate Admin principal, and creates only a bounded first-login replacement state. That state is HMAC-bound to the viewer account id, the live `security_version`, and the current temporary password hash and expires after 15 minutes. `current_viewer()`, normal viewer session establishment, viewer content mutation, and remember-token issue/verify/restore reject the flagged account, so favourites, collections, and persistent viewer authority cannot bypass the forced replacement.

The `/viewer/first-login` POST is private/no-store and uses viewer CSRF. The service re-locks and revalidates the account, applies the normal viewer password policy, rejects reuse of the temporary password, stores the replacement hash, clears `must_change_password`, records the password-change timestamp, increments `security_version`, and revokes older viewer sessions, remember credentials, reset tokens, and staged email-change authority before establishing the normal viewer session. The existing scanner-safe forgotten-password reset is also a valid independent replacement path and clears the flag on successful reset. Neither path modifies the administrator principal.

Direct Admin deletion remains separate from viewer self-deletion UI but deliberately reuses the prepared one-directional ownership model. The Admin route is POST-only and Admin-CSRF-protected with an explicit browser confirmation. The service locks the target viewer, invalidates its viewer authority, revokes any still-active dormant collection-share capabilities created by that viewer, deletes only the viewer identity, reconciles the account-cap counter, and lets existing foreign keys remove viewer-owned authentication/lifecycle/favourite/collection state. No deletion path reaches canonical images, galleries, Smart Galleries, gallery share links, or the Admin `users` identity/session.

`tests/viewer_admin_account_management_test.php` directly loads the new service/controller and protects schema/runtime symbols, capacity/password handling, no principal mixing, forced-first-login gating, remember-token bypass prevention, password non-reuse, reset compatibility, deletion scope, Admin/viewer coexistence, CSRF/no-store behavior, notification secrecy, routing, translations, and absence of public signup/sharing/profile/upload/optional-auth expansion.

## Phase 2.5 administrator viewer-account security controls

When the master viewer feature is enabled, the existing **Account > Viewer accounts** page exposes three narrowly scoped security operations without creating another management subsystem: **Suspend**, **Restore**, and **Sign out everywhere**. They remain available when only the subordinate viewer frontend mode is disabled. They are administrator-only, Admin-CSRF-protected POST mutations on the historical `admin_viewer_invitations` route. The controller performs strict positive viewer-id parsing and contains no account-state SQL; it delegates to the existing `viewer_account_suspend()`, `viewer_account_restore()`, and `viewer_session_revoke_all()` services.

Suspend and Restore are the existing transactional account-state transitions from Phase 0.7. The service locks the viewer row, rotates `security_version`, revokes all viewer sessions and remember credentials, invalidates outstanding reset tokens and durable email-verification authority, cancels pending email-change requests, and revokes dormant collection-share capabilities created by that viewer. If the same PHP session also contains the affected viewer principal, only the viewer namespace is cleared. `$_SESSION['user_id']` and the administrator principal remain untouched. Suspension does not delete the viewer account, favourites, collections, collection items, images, galleries, Smart Galleries, or gallery share links.

Restore changes only the durable lifecycle state back to `active` and rotates security authority again. It never clears `must_change_password`, reopens a revoked session row, re-enables an old Remember me validator, revives reset/email-change authority, or restores a collection-share token revoked during suspension. A pre-suspension temporary-password first-login state is bound to the old security version and active account state, so it becomes unusable; after restoration an administrator-created viewer still must complete the existing forced password-replacement flow before normal viewer authority is established.

**Sign out everywhere** is intentionally different from suspension. It keeps an active account active and calls the central authentication invalidation boundary to rotate `security_version`, revoke all viewer session rows and remember credentials, and invalidate outstanding reset authority. It does not change the password, `must_change_password`, favourites, collections, collection items, or dormant collection-share capability for the still-active owner. Other viewer accounts and all administrator authority remain unaffected.

These Admin security controls stay available even when the viewer frontend feature switch is disabled. This lets an operator secure dormant viewer data without temporarily enabling public viewer login. Missing or unknown required viewer security schema makes the mutation fail closed, while unrelated anonymous gallery browsing and administrator authentication remain independent.

The Admin table localizes friendly account states and shows Suspend plus Sign out everywhere only for active rows, Restore only for suspended rows, and the existing Delete action as before. No second Disable control is exposed. `disabled` remains an internal lifecycle state. Admin audit events attribute the three operations by internal Admin/viewer identifiers without logging credentials, tokens, CSRF values, or viewer email.

`tests/viewer_admin_security_controls_test.php` executes the real account-transition/logout-all helpers through a deterministic PDO fixture and covers Admin/viewer coexistence, security-version rotation, authority revocation, restoration non-resurrection, first-login flag/state behavior, favourites/collections preservation, dormant share revocation, other-viewer isolation, frontend-disable operation, schema failure, ID bounds, HTTP/CSRF wiring, translations, and explicit Phase 3 scope exclusion.

## Phase 3.0 unlisted read-only collection sharing

Phase 3.0 activates the dormant `viewer_collection_share_tokens` capability without adding a migration. `app/services/viewer_collection_shares.php` is deliberately separate from `viewer_collections.php`: private collections remain usable when share schema is missing/unknown, while share creation/exchange fails closed. Exactly one active link is exposed per collection. Create/replace requires normal `current_viewer()` content authority, Viewer CSRF, POST, ownership, the existing `viewer_share_create_account` limiter, and a transaction that locks account -> owned collection -> current share rows. Replacement revokes all previous unrevoked rows, generates 32 cryptographically random bytes through `security_opaque_token_generate(32)`, persists only `security_authority_token_hash()`, and sets a fixed 30-day expiry. The complete URL is carried only through a collection-scoped one-time session flash for immediate display. Revoke is POST/Viewer-CSRF and intentionally not rate-limited.

The reusable raw bearer route validates canonical token syntax before database lookup, checks current owner/collection/share/expiry state under row locks, then rotates the PHP session id and stores only a bounded `viewer_collection_share_grants` reference containing share id, collection id, expiry, and grant time. It does not establish viewer/Admin identity or gallery authority. A 303 redirect removes the secret from the displayed URL. GET exchange is scanner-safe because it neither consumes nor revokes the link. The raw route and clean shared page are no-store, no-referrer, and noindex/nofollow. The initial bearer URL may still be recorded by web-server or reverse-proxy access logs, so those logs are sensitive; application/security-event logging must never copy the raw token or complete share URL.

Every clean shared request revalidates its durable share row and active owner account, so replace, revoke, expiry, owner suspension/deletion, or collection deletion invalidates an existing recipient session on its next request. Restoration never resurrects a suspended share. Sign out everywhere remains viewer-authentication invalidation only and leaves an explicitly issued collection share active for an otherwise active owner. The shared read model loads only collection metadata and ordered image references. `viewer_source_images_resolve_authorized()` then applies current recipient-context gallery/password/share-session/NSFW rules with the explicit no-Admin-bypass variants. Collection membership/share authority is never accepted by gallery/media routes and never leaks denied image filename/path/title/gallery metadata.

All Phase 3 routes retain a `viewer_` page id, so the existing global Viewer Accounts master wrapper owns them. The master remains OFF by default; while off, raw/clean share routes are generic not-found and owner share controls are inaccessible. Shared collections remain unlisted and are absent from sitemap/search/discovery.

## Phase 4.0 open-registration policy and lifecycle foundations

Phase 4.0 does not add a generic registration route, form, Register/Sign up navigation item, anonymous registration POST, CAPTCHA, or Turnstile integration. Existing invite-only HTTP behavior remains the only reachable registration path. The Admin viewer page also keeps its existing visible disabled/invite-only checkbox for now. The database-backed `viewer_accounts_admin_mode` policy storage and service normalization can internally represent `disabled`, `invite_only`, or `open`, which lets Phase 4.1 add a three-state operator control without creating a second setting.

The global `viewer_accounts` Admin feature switch remains a separate outer boundary and still defaults OFF. When it is OFF, `viewer_registration_mode()` is always effectively `disabled`, including when the subordinate backend policy is `open`. Phase 4.0 does not redesign the repository's existing viewer-login availability semantics. Registration-policy transitions added here do not delete, suspend, revoke, sign out, or otherwise mutate existing viewer accounts, sessions, favourites, collections, or Phase 3 collection shares.

Registration-request origin is derived from the existing nullable foreign key:

```text
viewer_invitation_id IS NOT NULL
    -> invitation-backed staging

viewer_invitation_id IS NULL
    -> open-origin staging
```

No migration or parallel origin column is required. `viewer_registration_request_allowed_by_current_mode()` re-evaluates the current effective policy at each staged authority boundary. `disabled` permits no staged registration to progress, `invite_only` permits only invitation-backed staging, and `open` permits both origins subject to their existing security checks. Verification-link validation, explicit verification confirmation, and final password/account activation all call this current-policy boundary. Final activation checks it before any `INSERT INTO viewer_accounts`, so an already email-verified open-origin request cannot create an account after the operator changes policy to `invite_only` or `disabled`.

Mode changes also retire stale open-origin authority as defense in depth. Leaving `open` serializes the restrictive setting with the same registration-state lock used by request creation and final activation, commits that restrictive policy, then cancels only pending/email-verified rows with `viewer_invitation_id IS NULL`. Invitation-backed staging is left intact. Re-entering `open` performs the same open-origin cleanup while holding the registration-state lock and before `open` becomes effective, preventing both transition races and an old verification link or activation grant from becoming usable again merely because the operator later re-enabled open registration. If cancellation storage is unavailable or cleanup fails, current-mode authorization still blocks activation while the policy is restrictive, and the backend refuses to re-enable `open` until stale-authority cleanup can complete.

`viewer_registration_request_begin()` also re-reads the effective registration policy after taking the serialized pending-registration capacity lock. This closes the race where an open-origin request started just before an administrator restriction could otherwise insert fresh staging after mode-transition cleanup.

`tests/viewer_open_registration_policy_phase40_test.php` protects policy normalization, master-wrapper precedence, origin classification, current-mode authorization, open-to-restrictive cancellation, invitation preservation, cleanup-failure behavior, anti-resurrection ordering, lifecycle-service wiring, principal separation, and existing-schema reuse. Phase 4.1 updates only the old temporary assertion that no generic route exists.

## Phase 4.1 public verified-email open registration HTTP flow

Phase 4.1 exposes the existing staged-registration lifecycle through the new `viewer_register` page, with clean routing at `/viewer/register` when URL rewriting is enabled. The route is available only when the global `viewer_accounts` master feature is ON, the effective registration mode is exactly `open`, viewer security transport is allowed, and both viewer authentication and registration storage are verifiably available. The global master switch remains OFF by default. `invite_only` continues to expose viewer login and invitation-backed registration but no generic self-registration; `disabled` keeps the viewer frontend unavailable according to the existing viewer feature semantics.

The generic registration form contains only email plus the established viewer/pre-auth CSRF token. Its POST calls `viewer_registration_request_begin($email, null, request_client_ip())`, so open-origin staging continues to be represented solely by `viewer_registration_requests.viewer_invitation_id IS NULL`. No hidden origin field or alternate registration subsystem exists. A successful staging request does not create a viewer account, viewer session, Admin session, favourite, collection, or share.

After a valid CSRF submission, ordinary registration outcomes converge on one public notice: if the registration request can be accepted, a verification message will be sent to the submitted email address. Account existence, pending state, limiter state, capacity state, and mail suppression are not rendered. Verification mail continues through `viewer_mail_authorize_send()` and the configured transport, and the verification URL is built through `viewer_security_url()`. The open-origin message uses neutral registration wording rather than invitation wording. The plaintext verification capability is never returned in the registration response or security-event context, and successful delivery is marked only after the configured transport reports handoff.

The shared verification route is no longer artificially restricted to `invite_only`. Its HTTP availability accepts both `invite_only` and `open`, while the Phase 4.0 `viewer_registration_request_allowed_by_current_mode()` checks inside verification validation, explicit confirmation, and final activation remain authoritative for each staged row. Consequently, invitation-backed verification works in both `invite_only` and `open`; open-origin verification works only while the effective mode is `open`. An open-origin request created under `open` remains blocked after `open -> invite_only` or `open -> disabled`, and stale open-origin authority cannot resurrect when `open` is later re-enabled.

The scanner-safe ceremony is unchanged: verification-link GET validates without consuming authority, explicit Viewer-CSRF POST consumes verification authority and establishes only short-lived activation state, and the password POST performs the existing atomic activation. Activation creates the durable viewer account only after the current-mode check and then returns to the separate viewer login flow. It does not automatically establish `current_viewer()` and never writes Admin identity.

Invitation registration remains a distinct bearer-entry route. `viewer_http_invite_registration_available()` now permits both `invite_only` and `open`, but the invitation route still requires and validates its invitation token. Ordinary viewers cannot issue invitations, and generic open registration never accepts an invitation secret.

The Admin viewer-account page now exposes one selector backed by the existing `viewer_accounts_admin_mode` setting with exactly `disabled`, `invite_only`, and `open`. Mutations delegate to `viewer_accounts_set_admin_registration_mode()`, including the Phase 4.0 lifecycle-safe stale-open-origin cleanup. The mode-change Admin audit event records only bounded `old_mode`, `new_mode`, and `cancelled_open_origin_staging_count` context. The separate global Viewer Accounts feature flag remains the outer switch and still defaults OFF.

Repeated submission has one Phase 4.1 safety rule rather than a resend feature. If an existing pending request has `verification_send_count > 0` and its stored verification authority is unconsumed and still within both request and token validity windows, `viewer_registration_request_begin()` returns mail-ineligible without rotating `verification_token_hash` or `verification_token_expires_at`. If no verification message has ever been marked sent, or the previously sent token is already expired, the existing request may mint fresh authority and retry normal delivery. No public resend endpoint or resend UI is added.

`tests/viewer_open_registration_http_phase41_test.php` protects the route/feature matrix, null invitation origin, existing registration and verification-mail abuse buckets, viewer CSRF wiring, generic response, secret non-disclosure, scanner-safe verification, current-mode policy, invitation preservation in open mode, duplicate-token preservation and allowed retry cases, principal separation, the exact Admin three-state selector, open-only discoverability, translation parity, existing schema reuse, and the Phase 4.1 scope exclusions.

## Phase 4.2 first-party verification resend and recovery hardening

Phase 4.2 adds the explicit `viewer_resend_verification` page with clean route `/viewer/resend`. `viewer_http_verification_resend_available()` makes the surface reachable only when the global Viewer Accounts master is ON, effective registration mode is `invite_only` or `open`, secure viewer transport is allowed, and both authentication and registration storage are healthy. The global master remains OFF by default. Route availability is only the outer capability gate: `viewer_registration_request_allowed_by_current_mode()` remains authoritative for the staged row, so open-origin resend stops after `open -> invite_only` or `open -> disabled`, while valid invitation-backed staging may resend in both `invite_only` and `open`.

The public form contains only email plus Viewer/pre-auth CSRF. Email is a lookup candidate, never registration authority. The browser cannot choose a registration id, viewer id, invitation id/secret, origin, verification token, or password. After syntactically valid email plus valid CSRF, every ordinary outcome converges on one response: if a verification message can be sent for this address, it will be sent. Unknown/existing/cancelled/verified/expired/policy-forbidden/rate-limited/storage/mail-failure states are not distinguished by response text, redirect, or intentional account-state status code.

Resend uses two existing first-party abuse boundaries rather than controller counters. The registration service consumes `viewer_resend_verification_identifier` against the normalized lookup candidate and fails closed if limiter storage is unavailable. Actual mail handoff still requires `viewer_mail_authorize_send()` for `VIEWER_MAIL_ACTION_VERIFICATION`, preserving the existing email cooldown/hour/day, IP hour/day, subnet hour/day, and global day budgets. No external reputation or anti-spam service is contacted.

Migration `202608200001_viewer_registration_verification_tokens.php` adds a normalized child authority table so resend never has to replace the Phase 4.1 primary token. The primary request-row token hash/expiry remain backward-compatible and unchanged by true resend. Child rows store only a unique token hash with request ownership, bounded expiry, creation time, and nullable successful-handoff time. An unsent child cannot validate. Active children are capped per request, expired children are removed by bounded maintenance, and deleting the staged request cascades its children.

A valid primary token A and successfully sent child token B may coexist. Verification GET remains inspection-only for either authority. Explicit Viewer-CSRF confirmation through the first still-valid authority transitions the same `viewer_registration_requests` row to `email_verified`, consumes the request-level authority, removes every child sibling, and establishes only the existing short-lived activation grant. The losing sibling cannot confirm afterward and no durable viewer account is created until the existing password activation transaction. Activation still returns to Viewer Login rather than automatically creating Viewer identity; no registration/resend/verification path creates Admin identity.

Resend handoff is serialized with registration-mode transitions on the existing registration-state lock. Mode and request authority are revalidated immediately before transport. If token B transport fails, B is discarded or remains unusable/expiring; token A is never invalidated by that failure. If successful mail handoff occurs but recording `sent_at` fails, the child remains fail-closed rather than becoming usable through ambiguous database state, while older valid authority remains intact. Cancelled and request-lifetime-expired staging cannot be resurrected by resend or by later re-enabling `open`.

`tests/viewer_verification_resend_phase42_test.php` protects route/mode availability, CSRF and browser input boundaries, generic public results, reuse of the resend and verification-mail limiters, primary-token preservation, child delivery/failure semantics, both first-token-wins orders, historical Phase 4.1 links, current-mode revalidation, invitation behavior, no resurrection, principal separation, bounded schema, translations, and the zero-third-party scope.

Phase 4.2 implements no CAPTCHA, Turnstile, reCAPTCHA, hCaptcha, honeypot, proof-of-work, browser fingerprinting, adaptive challenge, external reputation API, Composer/npm security package, Redis/Memcached, queue/worker service, public viewer profile, or Phase 5 authentication feature. Those anti-automation mechanisms remain deferred; the resend boundary is deliberately compatible with a later fully first-party Phase 4.3 authorization step.

## Phase 4.3 first-party adaptive anti-automation gate

Phase 4.3 protects anonymous open registration and explicit verification resend with a local authorization layer before expensive registration/resend and mail work. `app/services/viewer_anti_automation.php` owns signed form/challenge tickets, session-bound one-time state, replay protection, server-measured form age, randomized honeypot metadata, deterministic escalation, existing rate-limit signals, bounded SHA-256 proof verification, the no-JavaScript fallback, and bounded anti-automation security events. It owns no registration, invitation, verification, account, or mail SQL and creates no Viewer, Admin, activation, invitation, or verification authority.

A protected GET issues server state equivalent to `version + kind + action + random nonce + issued_at + expires_at + honeypot id`. Active challenge state replaces honeypot metadata with signed proof difficulty. Tickets use the existing installation-specific HMAC fingerprint helper. Browser state is action-bound and short-lived, while the current PHP session keeps only scoped HMAC nonce fingerprints and bounded metadata under `viewer_anti_automation`. At most 12 unconsumed entries survive cleanup and successful use consumes authority once. Default form lifetime is 600 seconds, with normalized hard bounds of 120 to 1800 seconds.

The form ticket's server timestamp supplies the only age signal. Default minimum age is 2 seconds, bounded to 1 through 10 seconds. Too-fast submission is only an escalation signal, not permanent denial. Each form carries a randomized `vf_<random>` honeypot field hidden with the HTML `hidden` attribute, `aria-hidden`, `tabindex=-1`, and `autocomplete=off`, without a personal-data label. A populated or malformed honeypot is treated as a high-confidence suppression signal before registration/resend, limiter-heavy downstream logic, or mail work and is never announced publicly.

Repeated-request policy reuses the existing database-backed `viewer_rate_limit_consume()` subsystem rather than a controller/session/filesystem limiter. The only Phase 4.3 bucket additions are `viewer_automation_ip` at 8 attempts per 600 seconds with a 900-second lock and `viewer_automation_subnet` at 48 attempts per 600 seconds with a 900-second lock. Existing IP/subnet normalization, installation-HMAC subject fingerprints, bounded subject storage, cleanup, and fail-closed storage behavior apply. A clean request normally passes. Form age below the configured minimum, exact-IP attempt 3 or later, or subnet attempt 12 or later escalates. A honeypot hit or hard anti-automation limiter denial suppresses. Existing `viewer_register_ip`, `viewer_register_subnet`, `viewer_register_identifier`, `viewer_register_global_day`, `viewer_resend_verification_identifier`, and `viewer_verify_mail_*` controls remain independently authoritative after the local gate allows continuation.

Every active challenge POST consumes the same local anti-automation IP/subnet limiter dimensions before proof or fallback acceptance. Escalation uses a short-lived first-party proof challenge. Browser and server hash the canonical versioned input `viewer-aa-pow-v1\n<action>\n<challenge>\n<counter>` with SHA-256 and require a server-signed number of leading zero bits. The default range is 12 through 15 bits, with hard configuration bounds of 10 through 16 bits, 180-second challenge expiry, and a submitted counter ceiling of 1,048,575. Difficulty cannot be chosen by the browser. PHP checks one submitted counter with one SHA-256 operation. The local `public/assets/viewer-anti-automation.js` uses only native Web Crypto, yields periodically to the browser, imports no code, makes no remote security request, and collects no canvas/font/GPU/audio/device fingerprint.

A legitimate client is not required to have JavaScript or Web Crypto. The challenge form also contains an explicit first-party fallback protected by the same Viewer CSRF token and signed current-session challenge. It is short-lived and single-use, requires at least 3 seconds of server-measured challenge age, and consumes the same existing anti-automation IP/subnet limiter dimensions before it may continue. This fallback provides bounded abuse friction without image/audio CAPTCHA or a third-party accessibility service.

`/viewer/register` and `/viewer/resend` verify Viewer CSRF first and perform only local syntax checks before the anti-automation authorization call. `allow` reaches the existing registration/resend service; `challenge_required` renders only request-local verification state; `suppress` returns the existing generic completion wording for syntactically valid requests without downstream work; `invalid` requires fresh signed form state. Account existence, staged registration state, origin, invitation state, rate counts, and mail outcome are never exposed by the challenge. Invitation registration is unchanged and its high-entropy Admin-issued bearer remains its sole admission authority. Emailed verification GET is unchanged, validates only, and remains scanner-safe.

Phase 4.3 adds no persistent table. Phase 4.2 token A, already-sent sibling authorities, first-confirmed-token-wins cleanup, mail-failure safety, current-mode revalidation, stale open-origin cancellation/anti-resurrection, durable activation, Viewer login, Admin identity, gallery authorization, and Phase 3 collection sharing remain outside and authoritative over their own domains. No third-party CAPTCHA, remote anti-bot/reputation API, browser fingerprinting, Composer/npm security package, Redis/Memcached, daemon, queue, or new PHP extension is introduced.

`tests/viewer_anti_automation_phase43_test.php` protects the ticket, session, age, honeypot, limiter, adaptive, proof, fallback, integration-order, generic-response, token/mode/invitation/principal/scanner-safe, privacy, translation, and zero-third-party contracts.

## Phase 4.4 Viewer Registration Security Operations and Phase 4 Closure

Phase 4.4 keeps the completed open-registration security system observable without adding authority or collection. The existing **Admin -> Viewer accounts** page renders a read-only `viewer_security_operations_snapshot()` from `app/services/viewer_security_operations.php` after the existing Admin authentication/role boundary. No anonymous or Viewer metrics route is added, no second registration kill switch exists, and the existing `disabled` / `invite_only` / `open` selector remains the only registration policy control beneath the Viewer Accounts master feature.

The status block reports the master feature, effective registration mode, open-registration and resend HTTP availability, normalized first-party anti-automation state, and Viewer auth/registration/security-event/rate-limit storage health. Schema state retains the established three-state `available`, `unavailable`, and `unknown` distinction. A failed storage component makes only its metrics unavailable; it is never represented as a zero count and never changes registration, authentication, anti-automation, or mail behavior.

Capacity is aggregate only. Durable Viewer rows are compared with the configured account hard cap and staged registration rows with the configured pending-request hard cap. Open-origin (`viewer_invitation_id IS NULL`) and invitation-backed staging may be shown as aggregate counts. The operations service never renders a registration email, event account relation, or other identity dimension, and it does not repair or mutate the existing singleton capacity counters during page rendering.

Activity uses a fixed persisted-event allowlist: `viewer.registration_requested`, `viewer.verification_sent`, `viewer.verification_resend_requested`, `viewer.verification_resent`, `viewer.verification_resend_suppressed`, `viewer.automation_challenge_required`, `viewer.automation_challenge_passed`, `viewer.automation_challenge_failed`, and `viewer.automation_request_suppressed`. SQL computes rolling 24-hour and 7-day totals plus one seven-calendar-day daily trend. **Accepted registration requests** means staging requests that reached the existing `viewer.registration_requested` event, not all public POST attempts. Verification-message counts retain the existing successful transport-handoff event semantics. The trend's **Anti-automation interventions** value is exactly challenge-required plus request-suppressed events. No individual security-event row or `context_json` is exposed.

Limiter pressure is computed only for the fixed Phase 4 bucket families already defined by `viewer_rate_limit_policies()`: registration IP/subnet/identifier/global-day, verification-resend identifier, anti-automation IP/subnet, and verification-mail email/IP/subnet/global-day controls. An active subject has `last_attempt_at` inside the bucket's configured window or a still-future `locked_until`; a currently locked subject has `locked_until > now`. Stale stored rows outside the window do not create pressure merely because maintenance has not yet deleted them. Global registration and verification-mail daily usage is shown as current attempts versus configured limit only from a global row whose `first_attempt_at` is still inside the current window.

Dashboard reads never call `viewer_rate_limit_consume()`, reset/unlock/delete limiter state, run `viewer_security_maintenance_cleanup()`, issue/consume Phase 4.3 form or challenge authority, modify the `viewer_anti_automation` PHP-session namespace, or add page-view/request-attempt logging. The service adds no security event family. Existing bounded maintenance remains the only cleanup authority.

Phase 4.4 reuses existing security-purpose storage and introduces no migration, metrics table, telemetry table, JSON counter, browser identifier, fingerprint, or remote monitoring integration. Generic anonymous telemetry can remain disabled without affecting the operations panel. The panel does not display raw IP, IP hash, subnet, email/hash, limiter `subject_hash`, user-agent/hash, request id, event context, verification token/hash, invitation secret, installation secret, or session identifier.

Phase 4.2 token A and every sent sibling verification authority remain unchanged by operations reads. Current-mode revalidation, stale open-origin cancellation/anti-resurrection, invitation admission, scanner-safe verification GET, explicit confirmation POST, durable activation, Viewer login, Viewer/Admin identity separation, gallery authorization, and Phase 3 collection-share authorization remain authoritative in their original services. Phase 4.4 observes these systems and never participates in their authorization decisions.

`tests/viewer_security_operations_phase44_test.php` protects the Admin-only/read-only boundary, no-new-route/schema/telemetry contracts, three-state capability handling, authoritative capacity, fixed event aggregates and trend, stale/active/locked limiter semantics, global budget derivation, privacy exclusions, Phase 4.2/4.3 state isolation, and zero-third-party runtime contract.

Phase 4 is complete after Phase 4.4.

## Intentionally unavailable after Phase 4

After Phase 4, the following remain intentionally unavailable:

- public viewer profiles, collection directories/discovery, usernames, avatars, biography, arbitrary links, or social identity;
- named recipients, `shared with me`, recipient ACLs, multiple active links per collection, collaboration, or recipient editing;
- collection ZIP download or public publishing/discovery;
- viewer comments or uploads;
- CAPTCHA/Turnstile;
- viewer OIDC/social login;
- viewer TOTP/MFA enrollment;
- WebAuthn/passkey ceremonies;
- magic-link login;
- device/session-management UI;
- any rule that grants gallery/media access merely because `current_viewer()` is non-null, an image is favourited, an image is in a collection, or a collection share grant exists.

Viewer identity, Admin identity, gallery authorization, and collection-share authority remain separate domains.
