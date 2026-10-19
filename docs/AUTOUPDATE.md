# Visitor-started automatic updates

Enable automatic stable updates and select five minutes in Admin > Updates.
The default is five minutes; explicitly saved 15/30/60-minute choices remain
supported. The first eligible GET/HEAD at or after that interval checks for a
new stable version. A normal gallery visit is sufficient. Login and browser
JavaScript are unnecessary. Admitted robots count; the SEO guard rejects blocked
requests before the update trigger. Five minutes is the discovery interval,
not a guarantee that downloading and installing every package finishes by then.
GitHub rate-limit waiting remains mandatory.

Discovery creates the durable background job. After a successful visitor
response, the server sends one private continuation to the installation's
configured `base_url`. Each independent worker advances a normal three-second
slice and sends the next continuation. This continues through the existing
download, validation, staging, backup, activation, migration and cleanup engine
until the job completes. The user may close the page immediately. No hosting
cron needs to be configured. The server does not keep an idle worker waiting
for the next five-minute discovery boundary: discovery of a future release
still requires another eligible visit.

The installation origin must be reachable from its own server. HTTPS uses
normal certificate verification. The transport never uses the incoming Host
header, never follows an arbitrary URL, and waits at most one second for
connection/write admission, without waiting for the child response. A host must
have enough PHP capacity to serve independent requests. Single-worker built-in
development servers retain the ordinary visitor-driven fallback.

Each handoff needs the existing private maintenance token and a random,
single-use continuation ticket. Only the ticket's SHA-256 hash is stored.
The controller requires an exact POST envelope and rejects mixed worker modes,
query-string tickets and malformed tickets before worker execution. Both the
installation chain and the individual updater worker use nonblocking locks.
The private endpoint performs no implicit maintenance before authentication.

The `updates_background.php` service belongs to the deferred `updater-work`
module. Loading the ordinary updater job entry must not load this service.
The updater-only transport loads its existing single-worker admission policy
explicitly before calling it; routine telemetry transport keeps that policy's
storage/model closure deferred. Generated runtime plans and real route include
ratchets verify these ownership boundaries without increasing their budgets.

`cache/updates/background-chain.json` retains the job identity, ticket hash,
lease deadline, last dispatch, actual last arrival and a redacted failure code.
Its contents are private installation state. A complete socket write proves
dispatch only; `last_arrival_at` proves an authenticated worker consumed its
ticket. A live queued/executing lease keeps ordinary visitors out of heavy
updater work. Lost arrivals become recoverable after 120 seconds. Failed
dispatch admission is retried no faster than thirty seconds; ordinary visits
still retain the previous bounded worker fallback.

Failed worker stages stop chaining. The existing visitor recovery waits at least
sixty seconds and uses the same stage-aware retry cleanup. Manual/Admin jobs,
beta operations and a disabled installer capability cannot be advanced by this
chain. Turning off automatic discovery still prevents new automatic jobs;
existing background work retains its established continuation semantics.
Activation remains the engine's prepared local critical section, with its
existing interruption and rollback protections.

The optional `scripts/application_update.php` runner remains supported for
installations that already use external scheduling. It is not required for the
visitor-started chain. No state file, package, installer, database migration or
hosting configuration must be copied manually for this feature.
